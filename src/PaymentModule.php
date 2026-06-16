<?php

namespace CodeWithDiki\PaymentModule;

use CodeWithDiki\PaymentModule\Data\PaymentData;
use CodeWithDiki\PaymentModule\Data\PaymentMethodData;
use CodeWithDiki\PaymentModule\Data\PaymentMethodGroupData;
use CodeWithDiki\PaymentModule\Enums\PaymentStatus;
use CodeWithDiki\PaymentModule\Models\Payment;
use CodeWithDiki\PaymentModule\Models\PaymentMethod;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Support\Facades\Route;

class PaymentModule
{
    public function createPayment(PaymentData $paymentData): Payment
    {
        $paymentMethodClass = config('payment-module.payment_method_class');

        $paymentMethod = $paymentMethodClass::isActive()->where('id', $paymentData->payment_method_id)->firstOrFail();

        // Fee from the payment method is automatically added on top of the amount,
        // so the customer is billed amount + fee (total_amount).
        $fee = $paymentMethod->calculateFee($paymentData->amount);
        $total_amount = $paymentData->amount + $fee;

        $payment = config('payment-module.payment_class')::create([
            'paymentable_type' => get_class($paymentData->paymentable),
            'paymentable_id' => $paymentData->paymentable->id,
            'customer_name' => $paymentData->customer_name,
            'customer_email' => $paymentData->customer_email,
            'customer_phone' => $paymentData->customer_phone,
            'customer_address' => $paymentData->customer_address,
            'customer_custom_data' => $paymentData->customer_custom_data,
            'payment_method_id' => $paymentMethod->id,
            'payment_code' => $paymentData->payment_code,
            'amount' => $paymentData->amount,
            'fee' => $fee,
            'total_amount' => $total_amount,
            'status' => $paymentData->status,
            'payment_headers' => $paymentData->payment_headers,
            'payment_payload' => $paymentData->payment_payload,
            'payment_response' => $paymentData->payment_response,
        ]);

        Events\PaymentCreated::dispatch($payment);

        return $payment;
    }

    public function setPaymentStatus(Payment $payment, PaymentStatus $status): Payment
    {
        // Idempotency: once a payment reaches a terminal status, ignore further
        // transitions (e.g. a replayed webhook) so events are not dispatched twice.
        if ($payment->status instanceof PaymentStatus && $payment->status->isTerminal()) {
            return $payment;
        }

        $payment->update([
            'status' => $status,
        ]);

        match ($status) {
            PaymentStatus::PAID => Events\PaymentPaid::dispatch($payment),
            PaymentStatus::FAILED => Events\PaymentFailed::dispatch($payment),
            default => null
        };

        return $payment;
    }

    public function webhookRoutes(): void
    {
        Route::prefix(config('payment-module.webhook.prefix', 'webhooks'))
            ->withoutMiddleware(config('payment-module.webhook.without_middleware', [VerifyCsrfToken::class]))
            ->group(function () {
                Route::webhooks('midtrans', 'payment-module-midtrans');
                Route::webhooks('midtrans/payout', 'payment-module-midtrans-payout');
                Route::webhooks('stripe', 'payment-module-stripe');
            });
    }

    public function createDisbursement(Data\DisbursementData $data): Models\Disbursement
    {
        if (! $data->vendor->getDisbursementProcessorClass()) {
            throw Exceptions\DisbursementNotSupportedException::forVendor($data->vendor);
        }

        $disbursement = config('payment-module.disbursement_class')::create([
            'disbursable_type' => $data->disbursable ? get_class($data->disbursable) : null,
            'disbursable_id' => $data->disbursable?->id,
            'disbursement_code' => $data->disbursement_code,
            'vendor' => $data->vendor,
            'beneficiary_name' => $data->beneficiary_name,
            'beneficiary_account' => $data->beneficiary_account,
            'beneficiary_bank' => $data->beneficiary_bank,
            'beneficiary_email' => $data->beneficiary_email,
            'amount' => $data->amount,
            'notes' => $data->notes,
            'status' => Enums\DisbursementStatus::PENDING,
            // Record the maker for separation-of-duties enforcement (null when no auth context)
            'created_by' => auth()->id(),
        ]);

        Events\DisbursementCreated::dispatch($disbursement);

        return $disbursement;
    }

    public function setDisbursementStatus(Models\Disbursement $disbursement, Enums\DisbursementStatus $status): Models\Disbursement
    {
        // Idempotency: once a disbursement reaches a terminal status, ignore further
        // transitions (e.g. a replayed webhook) so events are not dispatched twice.
        if ($disbursement->status instanceof Enums\DisbursementStatus && $disbursement->status->isTerminal()) {
            return $disbursement;
        }

        $disbursement->update(array_merge(
            ['status' => $status],
            $status === Enums\DisbursementStatus::COMPLETED ? ['completed_at' => now()] : []
        ));

        match ($status) {
            Enums\DisbursementStatus::COMPLETED => Events\DisbursementCompleted::dispatch($disbursement),
            Enums\DisbursementStatus::FAILED,
            Enums\DisbursementStatus::REJECTED => Events\DisbursementFailed::dispatch($disbursement),
            default => null
        };

        return $disbursement;
    }

    public function getDisbursementByCode(string $disbursement_code): ?Models\Disbursement
    {
        return config('payment-module.disbursement_class')::where('disbursement_code', $disbursement_code)->first();
    }

    public function getDisbursementByReferenceNo(string $reference_no): ?Models\Disbursement
    {
        return config('payment-module.disbursement_class')::where('reference_no', $reference_no)->first();
    }

    public function approveDisbursement(Models\Disbursement $disbursement): Models\Disbursement
    {
        $this->guardSeparationOfDuties($disbursement);

        $this->getDisbursementProcessor($disbursement)->approveDisbursement($disbursement);

        $disbursement->update(['approved_by' => auth()->id()]);

        return $disbursement;
    }

    /**
     * Enforce maker-approver separation of duties: the user who created a disbursement
     * may not approve it. Skipped when there is no authenticated user or no recorded maker.
     */
    protected function guardSeparationOfDuties(Models\Disbursement $disbursement): void
    {
        $currentUser = auth()->id();

        if ($currentUser !== null
            && $disbursement->created_by !== null
            && (string) $disbursement->created_by === (string) $currentUser) {
            throw Exceptions\DisbursementApprovalDeniedException::selfApproval();
        }
    }

    public function rejectDisbursement(Models\Disbursement $disbursement, ?string $reason = null): Models\Disbursement
    {
        $this->getDisbursementProcessor($disbursement)->rejectDisbursement($disbursement, $reason);

        return $disbursement;
    }

    protected function getDisbursementProcessor(Models\Disbursement $disbursement): Supports\Disbursement\Contracts\DisbursementProcessor
    {
        $processor_class = $disbursement->vendor->getDisbursementProcessorClass();

        if (! $processor_class) {
            throw Exceptions\DisbursementNotSupportedException::forVendor($disbursement->vendor);
        }

        return app($processor_class);
    }

    public function createPaymentMethod(PaymentMethodData $paymentMethodData): PaymentMethod
    {
        $payment_method_class = config('payment-module.payment_method_class');

        return $payment_method_class::create([
            'name' => $paymentMethodData->name,
            'channel' => $paymentMethodData->channel,
            'vendor' => $paymentMethodData->vendor,
            'is_active' => $paymentMethodData->is_active,
            'fee_flat' => $paymentMethodData->fee_flat,
            'fee_percentage' => $paymentMethodData->fee_percentage,
        ]);
    }

    public function getPaymentByCode(string $payment_code): ?Payment
    {
        $paymetClass = config('payment-module.payment_class');

        return $paymetClass::where('payment_code', $payment_code)->first();
    }

    public function getPaymentMethodById(int $id): ?PaymentMethod
    {
        $paymentMethodClass = config('payment-module.payment_method_class');

        return $paymentMethodClass::find($id);
    }

    public function getActivePaymentMethods(): Collection
    {
        $paymentMethodClass = config('payment-module.payment_method_class');

        return $paymentMethodClass::isActive()->get();
    }

    public function getPaymentMethodsByGroupId(int $group_id): Collection
    {
        $paymentMethodGroupClass = config('payment-module.payment_method_group_class');

        return $paymentMethodGroupClass::isActive()->where('id', $group_id)->paymentMethods()->isActive()->get();
    }

    public function createPaymentMethodGroup(PaymentMethodGroupData $data): Models\PaymentMethodGroup
    {
        $paymentMethodGroupClass = config('payment-module.payment_method_group_class');

        return $paymentMethodGroupClass::create([
            'name' => $data->name,
            'slug' => $data->slug,
            'image_url' => $data->image_url,
            'is_active' => $data->is_active,
        ]);
    }

    public function getPaymentFromPaymentable(string $paymentable_type, int $paymentable_id): ?Payment
    {
        $paymentClass = config('payment-module.payment_class');

        return $paymentClass::where('paymentable_type', $paymentable_type)
            ->where('paymentable_id', $paymentable_id)
            ->first();
    }

    public function getActivePaymentMethodGroups(): Collection
    {
        $paymentMethodGroupClass = config('payment-module.payment_method_group_class');

        return $paymentMethodGroupClass::with('paymentMethods')->isActive()->get();
    }
}
