<?php

namespace CodeWithDiki\PaymentModule\Enums;

use CodeWithDiki\PaymentModule\Supports\Disbursement\MidtransIris;
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

    public function getDisbursementProcessorClass(): ?string
    {
        return match ($this) {
            self::Midtrans => MidtransIris::class,
            // Offline has no gateway; Stripe Global Payouts is limited to US/GB sender accounts
            default => null,
        };
    }
}
