<?php

namespace CodeWithDiki\PaymentModule\Enums;

use CodeWithDiki\PaymentModule\Supports\PaymentMethod\Midtrans;
use CodeWithDiki\PaymentModule\Supports\PaymentMethod\Offline;
use CodeWithDiki\PaymentModule\Supports\PaymentMethod\Stripe;

enum PaymentVendor: string
{
    case Offline = 'Offline';
    case Midtrans = 'Midtrans';
    case Stripe = 'Stripe';

    public function getPaymentProcessorClass(): string
    {
        return match ($this) {
            self::Offline => Offline::class,
            self::Midtrans => Midtrans::class,
            self::Stripe => Stripe::class,
        };
    }
}
