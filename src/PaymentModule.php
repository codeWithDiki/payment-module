<?php

namespace CodeWithDiki\PaymentModule;

use CodeWithDiki\PaymentModule\Data\PaymentData;
use CodeWithDiki\PaymentModule\Data\PaymentMethodData;
use CodeWithDiki\PaymentModule\Data\PaymentMethodGroupData;
use CodeWithDiki\PaymentModule\Enums\PaymentStatus;
use CodeWithDiki\PaymentModule\Models\Payment;
use CodeWithDiki\PaymentModule\Models\PaymentMethod;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Support\Facades\Route;

class PaymentModule {

    public function createPayment(PaymentData $paymentData): Payment
    {
        $paymentMethodClass = config('payment-module.payment_method_class');
        
        $paymentMethod = $paymentMethodClass::isActive()->where("id", $paymentData->payment_method_id)->firstOrFail();

        $payment = Payment::create([
            "paymentable_type" => get_class($paymentData->paymentable),
            "paymentable_id" => $paymentData->paymentable->id,
            "customer_name" => $paymentData->customer_name,
            "customer_email" => $paymentData->customer_email,
            "customer_phone" => $paymentData->customer_phone,
            "customer_address" => $paymentData->customer_address,
            "customer_custom_data" => $paymentData->customer_custom_data,
            "payment_method_id" => $paymentMethod->id,
            "payment_code" => $paymentData->payment_code,
            "amount" => $paymentData->amount,
            "status" => $paymentData->status,
            "payment_headers" => $paymentData->payment_headers,
            "payment_payload" => $paymentData->payment_payload,
            "payment_response" => $paymentData->payment_response,
        ]);

        Events\PaymentCreated::dispatch($payment);

        return $payment;
    }

    public function setPaymentStatus(Payment $payment, PaymentStatus $status): Payment
    {
        $payment->update([
            "status" => $status
        ]);

        match($status)
        {
            PaymentStatus::PAID => Events\PaymentPaid::dispatch($payment),
            PaymentStatus::FAILED => Events\PaymentFailed::dispatch($payment)
        };

        return $payment;
    }

    public function webhookRoutes() : void
    {
        Route::prefix("webhooks")
        ->withoutMiddleware(VerifyCsrfToken::class)
        ->group(function() {
            Route::post("midtrans", [Controllers\WebhookController::class, "midtrans"]);
        });
    }

    public function createPaymentMethod(PaymentMethodData $paymentMethodData) : Models\PaymentMethod
    {
        $payment_method_class = config("payment-module.payment_method_class");

        return $payment_method_class::create([
            "name" => $paymentMethodData->name,
            "channel" => $paymentMethodData->channel,
            "vendor" => $paymentMethodData->vendor,
            "is_active" => $paymentMethodData->is_active
        ]);
    }

    public function getPaymentByCode(string $payment_code) : ?Payment
    {
        $paymetClass = config("payment-module.payment_class");

        return $paymetClass::where("payment_code", $payment_code)->first();
    }

    public function getPaymentMethodById(int $id) : ?PaymentMethod
    {
        $paymentMethodClass = config("payment-module.payment_method_class");

        return $paymentMethodClass::find($id);
    }

    public function getActivePaymentMethods() : \Illuminate\Database\Eloquent\Collection
    {
        $paymentMethodClass = config("payment-module.payment_method_class");
        return $paymentMethodClass::isActive()->get();
    }

    public function getPaymentMethodsByGroupId(int $group_id) : \Illuminate\Database\Eloquent\Collection
    {
        $paymentMethodGroupClass = config("payment-module.payment_method_group_class");

        return $paymentMethodGroupClass::isActive()->where('id', $group_id)->paymentMethods()->isActive()->get();
    }

    public function createPaymentMethodGroup(PaymentMethodGroupData $data) : Models\PaymentMethodGroup
    {
        $paymentMethodGroupClass = config("payment-module.payment_method_group_class");

        return $paymentMethodGroupClass::create([
            "name" => $data->name,
            "slug" => $data->slug,
            "image_url" => $data->image_url,
            "is_active" => $data->is_active
        ]);
    }

    public function getPaymentFromPaymentable(string $paymentable_type, int $paymentable_id) : ?Payment
    {
        $paymentClass = config("payment-module.payment_class");

        return $paymentClass::where("paymentable_type", $paymentable_type)
            ->where("paymentable_id", $paymentable_id)
            ->first();
    }

}
