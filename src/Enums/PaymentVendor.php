<?php

namespace CodeWithDiki\PaymentModule\Enums;

enum PaymentVendor: string
{
    case Offline = 'Offline';
    case Midtrans = 'Midtrans';

    public function getPaymentProcessorClass(): string
    {
        return match ($this) {
            self::Offline => \CodeWithDiki\PaymentModule\Supports\PaymentMethod\Offline::class,
            self::Midtrans => \CodeWithDiki\PaymentModule\Supports\PaymentMethod\Midtrans::class,
        };
    }

}