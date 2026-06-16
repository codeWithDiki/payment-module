<?php

namespace CodeWithDiki\PaymentModule\Models;

use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PaymentMethodGroup extends Model
{
    protected $guarded = [];

    public function paymentMethods(): HasMany
    {
        return $this->hasMany(config('payment-module.payment_method_class'), 'payment_method_group_id');
    }

    #[Scope]
    protected function isActive(Builder $query): void
    {
        $query->where('is_active', true);
    }
}
