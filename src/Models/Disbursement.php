<?php

namespace CodeWithDiki\PaymentModule\Models;

use CodeWithDiki\PaymentModule\Enums\DisbursementStatus;
use CodeWithDiki\PaymentModule\Enums\PaymentVendor;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class Disbursement extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'status' => DisbursementStatus::class,
            'vendor' => config('payment-module.vendor_enum_class', PaymentVendor::class),
            'completed_at' => 'datetime',
            'disbursement_payload' => 'json',
            'disbursement_response' => 'json',
        ];
    }

    public function disbursable(): MorphTo
    {
        return $this->morphTo();
    }

    #[Scope]
    protected function isPending(Builder $query): void
    {
        $query->where('status', DisbursementStatus::PENDING);
    }

    #[Scope]
    protected function isCompleted(Builder $query): void
    {
        $query->where('status', DisbursementStatus::COMPLETED);
    }

    #[Scope]
    protected function isFailed(Builder $query): void
    {
        $query->where('status', DisbursementStatus::FAILED);
    }
}
