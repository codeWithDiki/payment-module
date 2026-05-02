<?php

namespace CodeWithDiki\PaymentModule\Models;

use CodeWithDiki\PaymentModule\Enums\PaymentStatus;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class Payment extends \Illuminate\Database\Eloquent\Model
{
    protected $guarded = [];

    protected $casts = [
        'status' => PaymentStatus::class,
        'paid_at' => 'datetime',
        'payment_headers' => 'json',
        'payment_payload' => 'json',
        'payment_response' => 'json',
        'customer_custom_data' => 'json',
    ];

    public function paymentable() : MorphTo
    {
        return $this->morphTo();
    }

    public function paymentMethod() : \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(config('payment-module.payment_method_class'), "payment_method_id");
    }
    
    #[Scope]
    protected function isPaid(Builder $query) : void
    {
        $query->where('status', PaymentStatus::PAID);
    }

    #[Scope]
    protected function isPending(Builder $query) : void
    {
        $query->where('status', PaymentStatus::PENDING);
    }

    #[Scope]
    protected function isFailed(Builder $query) : void
    {
        $query->where('status', PaymentStatus::FAILED);
    }

    public function getQrCodeUrl() : ?string
    {
        if($this->paymentMethod->vendor == \CodeWithDiki\PaymentModule\Enums\PaymentVendor::Midtrans) {
            return (($this->payment_response['status_code'] ?? null) == 201) ? (collect($this->payment_response['actions'] ?? [])->firstWhere('name', 'generate-qr-code')['url'] ?? null) : null;
        }

        return null;
    }

    public function getMidtransVirtualAccountNumber() : ?string
    {
        if($this->paymentMethod->vendor == \CodeWithDiki\PaymentModule\Enums\PaymentVendor::Midtrans) {
            if($this->paymentMethod->channel == "permata") {
                return (($this->payment_response['status_code'] ?? null) == 201) ? ($this->payment_response['permata_va_number'] ?? null) : null;
            }

            return (($this->payment_response['status_code'] ?? null) == 201) ? (collect($this->payment_response['va_numbers'] ?? [])->firstWhere('bank', $this->paymentMethod->channel)['va_number'] ?? null) : null;
        }

        return null;
    }

}