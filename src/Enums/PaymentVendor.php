<?php

namespace CodeWithDiki\PaymentModule\Enums;

use CodeWithDiki\PaymentModule\Supports\Disbursement\DokuKirim;
use CodeWithDiki\PaymentModule\Supports\Disbursement\MidtransIris;
use CodeWithDiki\PaymentModule\Supports\Disbursement\XenditDisbursement;
use CodeWithDiki\PaymentModule\Supports\PaymentMethod\Doku;
use CodeWithDiki\PaymentModule\Supports\PaymentMethod\Midtrans;
use CodeWithDiki\PaymentModule\Supports\PaymentMethod\Offline;
use CodeWithDiki\PaymentModule\Supports\PaymentMethod\Stripe;
use CodeWithDiki\PaymentModule\Supports\PaymentMethod\Xendit;

enum PaymentVendor: string
{
    case Offline = 'Offline';
    case Midtrans = 'Midtrans';
    case Stripe = 'Stripe';
    case Xendit = 'Xendit';
    case Doku = 'Doku';

    public function getPaymentProcessorClass(): string
    {
        return match ($this) {
            self::Offline => Offline::class,
            self::Midtrans => Midtrans::class,
            self::Stripe => Stripe::class,
            self::Xendit => Xendit::class,
            self::Doku => Doku::class,
        };
    }

    public function getDisbursementProcessorClass(): ?string
    {
        return match ($this) {
            self::Midtrans => MidtransIris::class,
            self::Xendit => XenditDisbursement::class,
            self::Doku => DokuKirim::class,
            // Offline has no gateway; Stripe Global Payouts is limited to US/GB sender accounts
            default => null,
        };
    }
}
