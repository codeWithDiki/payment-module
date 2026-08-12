<?php

namespace CodeWithDiki\PaymentModule\Models;

use CodeWithDiki\PaymentModule\Data\PaymentInstruction;
use CodeWithDiki\PaymentModule\Enums\PaymentStatus;
use CodeWithDiki\PaymentModule\Enums\PaymentVendor;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $paymentable_type
 * @property int $paymentable_id
 * @property int $payment_method_id
 * @property string $payment_code
 * @property float $amount
 * @property float $fee
 * @property float $total_amount
 * @property PaymentStatus $status
 * @property array|null $payment_headers
 * @property array|null $payment_payload
 * @property array|null $payment_response
 * @property string|null $customer_name
 * @property string|null $customer_email
 * @property string|null $customer_phone
 * @property string|null $customer_address
 * @property array|null $customer_custom_data
 * @property Carbon|null $paid_at
 * @property-read PaymentMethod $paymentMethod
 */
class Payment extends Model
{
    protected $guarded = [];

    protected $casts = [
        'status' => PaymentStatus::class,
        'amount' => 'float',
        'fee' => 'float',
        'total_amount' => 'float',
        'paid_at' => 'datetime',
        'payment_headers' => 'json',
        'payment_payload' => 'json',
        'payment_response' => 'json',
        'customer_custom_data' => 'json',
    ];

    /**
     * Amount actually billed to the customer (amount + fee). Falls back to the
     * base amount for records created before the fee columns were populated.
     */
    public function billableAmount(): float
    {
        return (float) ($this->total_amount ?: $this->amount);
    }

    /**
     * Whether an operator may settle this payment by hand.
     *
     * True only for offline channels (bank transfer, convenience store, ...) that are still
     * pending: they have no gateway to report the outcome, so a human has to verify the
     * funds arrived. Gateway payments are excluded on purpose — confirming one by hand
     * would settle an order the gateway never actually collected money for.
     */
    public function canBeConfirmedManually(): bool
    {
        return $this->status === PaymentStatus::PENDING
            && $this->paymentMethod->vendor === PaymentVendor::Offline;
    }

    public function paymentable(): MorphTo
    {
        return $this->morphTo();
    }

    public function paymentMethod(): BelongsTo
    {
        return $this->belongsTo(config('payment-module.payment_method_class'), 'payment_method_id');
    }

    #[Scope]
    protected function isPaid(Builder $query): void
    {
        $query->where('status', PaymentStatus::PAID);
    }

    #[Scope]
    protected function isPending(Builder $query): void
    {
        $query->where('status', PaymentStatus::PENDING);
    }

    #[Scope]
    protected function isFailed(Builder $query): void
    {
        $query->where('status', PaymentStatus::FAILED);
    }

    /**
     * Gateway response in the shared shape, whichever vendor produced it: the vendor's own
     * processor does the translating, so callers switch on $instruction->type instead of
     * on the vendor. Null when there is nothing for the customer to act on.
     */
    public function instruction(): ?PaymentInstruction
    {
        return app($this->paymentMethod->vendor->getPaymentProcessorClass())
            ->getPaymentInstruction($this);
    }

    /** @deprecated Use instruction()->qr_url */
    public function getQrCodeUrl(): ?string
    {
        return $this->instruction()?->qr_url;
    }

    /** @deprecated Use instruction()->redirect_url */
    public function getStripeCheckoutUrl(): ?string
    {
        return $this->vendorInstruction(PaymentVendor::Stripe)?->redirect_url;
    }

    /** @deprecated Use instruction()->virtual_account_number */
    public function getDokuVirtualAccountNumber(): ?string
    {
        return $this->vendorInstruction(PaymentVendor::Doku)?->virtual_account_number;
    }

    /** @deprecated Use instruction()->qr_string */
    public function getDokuQrString(): ?string
    {
        return $this->vendorInstruction(PaymentVendor::Doku)?->qr_string;
    }

    /** @deprecated Use instruction()->redirect_url */
    public function getDokuEwalletRedirectUrl(): ?string
    {
        return $this->vendorInstruction(PaymentVendor::Doku)?->redirect_url;
    }

    /** @deprecated Use instruction()->virtual_account_number */
    public function getMidtransVirtualAccountNumber(): ?string
    {
        return $this->vendorInstruction(PaymentVendor::Midtrans)?->virtual_account_number;
    }

    /**
     * Instruction, but only for one vendor — keeps the deprecated per-vendor accessors
     * returning null on a payment made through a different gateway.
     */
    protected function vendorInstruction(PaymentVendor $vendor): ?PaymentInstruction
    {
        return $this->paymentMethod->vendor === $vendor ? $this->instruction() : null;
    }
}
