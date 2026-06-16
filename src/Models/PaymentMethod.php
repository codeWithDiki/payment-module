<?php

namespace CodeWithDiki\PaymentModule\Models;

use CodeWithDiki\PaymentModule\Enums\PaymentVendor;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property int|null $payment_method_group_id
 * @property string $name
 * @property PaymentVendor $vendor
 * @property string $channel
 * @property string|null $description
 * @property string|null $image_url
 * @property float $fee_flat
 * @property float $fee_percentage
 * @property array|null $meta_data
 * @property bool $is_active
 */
class PaymentMethod extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'meta_data' => 'json',
            'is_active' => 'boolean',
            'fee_flat' => 'float',
            'fee_percentage' => 'float',
            'vendor' => config('payment-module.vendor_enum_class', PaymentVendor::class),
        ];
    }

    /**
     * Fee charged for this payment method on top of the given amount:
     * a flat nominal plus a percentage of the amount.
     */
    public function calculateFee(float $amount): float
    {
        return round((float) $this->fee_flat + ($amount * (float) $this->fee_percentage / 100), 2);
    }

    public function paymentMethodGroup(): BelongsTo
    {
        return $this->belongsTo(config('payment-module.payment_method_group_class'), 'payment_method_group_id');
    }

    public function payments(): HasMany
    {
        return $this->hasMany(config('payment-module.payment_class'), 'payment_method_id');
    }

    #[Scope]
    protected function isActive(Builder $query): void
    {
        $query->where('is_active', true);
    }
}
