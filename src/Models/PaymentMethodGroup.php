<?php

namespace CodeWithDiki\PaymentModule\Models;

use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;

class PaymentMethodGroup extends \Illuminate\Database\Eloquent\Model
{
    protected $guarded = [];

    public function paymentMethods() : \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(PaymentMethod::class, "payment_method_group_id");
    }

    #[Scope]
    protected function isActive(Builder $query) : void
    {
        $query->where("is_active", true);
    }

}