<?php

namespace CodeWithDiki\PaymentModule\Models;

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

    public function getQrCodeUrl(): ?string
    {
        if ($this->paymentMethod->vendor == PaymentVendor::Midtrans) {
            return (($this->payment_response['status_code'] ?? null) == 201) ? (collect($this->payment_response['actions'] ?? [])->firstWhere('name', 'generate-qr-code')['url'] ?? null) : null;
        }

        return null;
    }

    public function getStripeCheckoutUrl(): ?string
    {
        if ($this->paymentMethod->vendor == PaymentVendor::Stripe) {
            return $this->payment_response['url'] ?? null;
        }

        return null;
    }

    public function getMidtransVirtualAccountNumber(): ?string
    {
        if ($this->paymentMethod->vendor == PaymentVendor::Midtrans) {
            if ($this->paymentMethod->channel == 'permata') {
                return (($this->payment_response['status_code'] ?? null) == 201) ? ($this->payment_response['permata_va_number'] ?? null) : null;
            }

            return (($this->payment_response['status_code'] ?? null) == 201) ? (collect($this->payment_response['va_numbers'] ?? [])->firstWhere('bank', $this->paymentMethod->channel)['va_number'] ?? null) : null;
        }

        return null;
    }
}
