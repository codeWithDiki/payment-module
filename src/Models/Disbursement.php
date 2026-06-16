<?php

namespace CodeWithDiki\PaymentModule\Models;

use CodeWithDiki\PaymentModule\Enums\DisbursementStatus;
use CodeWithDiki\PaymentModule\Enums\PaymentVendor;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string|null $disbursable_type
 * @property int|null $disbursable_id
 * @property string $disbursement_code
 * @property string|null $reference_no
 * @property PaymentVendor $vendor
 * @property string $beneficiary_name
 * @property string $beneficiary_account
 * @property string $beneficiary_bank
 * @property string|null $beneficiary_email
 * @property float $amount
 * @property string|null $notes
 * @property DisbursementStatus $status
 * @property array|null $disbursement_payload
 * @property array|null $disbursement_response
 * @property string|null $error_code
 * @property string|null $error_message
 * @property string|null $created_by
 * @property string|null $approved_by
 * @property Carbon|null $completed_at
 */
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
