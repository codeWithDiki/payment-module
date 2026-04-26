<?php

namespace CodeWithDiki\PaymentModule\Models;

use CodeWithDiki\PaymentModule\Enums\PaymentVendor;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PaymentMethod extends \Illuminate\Database\Eloquent\Model
{
    protected $guarded = [];

    protected $casts = [
        "meta_data" => "json",
        "is_active" => "boolean",
        "vendor" => PaymentVendor::class
    ];

    public function paymentMethodGroup() : \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(PaymentMethodGroup::class, "payment_method_group_id");
    }

    public function payments() : HasMany
    {
        return $this->hasMany(Payment::class);
    }

    #[Scope]
    protected function isActive(Builder $query) : void
    {
        $query->where("is_active", true);
    }

}