<?php

namespace CodeWithDiki\PaymentModule\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * @see \CodeWithDiki\PaymentModule\PaymentModule
 */
class PaymentModule extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return \CodeWithDiki\PaymentModule\PaymentModule::class;
    }
}
