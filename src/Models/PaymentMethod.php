<?php

namespace CodeWithDiki\PaymentModule\Models;

use CodeWithDiki\PaymentModule\Enums\PaymentVendor;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PaymentMethod extends \Illuminate\Database\Eloquent\Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            "meta_data" => "json",
            "is_active" => "boolean",
            "vendor" => config('payment-module.vendor_enum_class', PaymentVendor::class),
        ];
    }

    public function paymentMethodGroup() : \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(config('payment-module.payment_method_group_class'), "payment_method_group_id");
    }

    public function payments() : HasMany
    {
        return $this->hasMany(config('payment-module.payment_class'), "payment_method_id");
    }

    #[Scope]
    protected function isActive(Builder $query) : void
    {
        $query->where("is_active", true);
    }

}