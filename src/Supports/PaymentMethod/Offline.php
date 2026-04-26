<?php

namespace CodeWithDiki\PaymentModule\Supports\PaymentMethod;

use CodeWithDiki\PaymentModule\Enums\PaymentStatus;
use CodeWithDiki\PaymentModule\PaymentModule;

class Offline implements Contracts\PaymentProcessor
{
    use Concerns\InteractsWithPaymentProcessor;

    public function getChannels(): \Illuminate\Support\Collection
    {
        return collect([
            "bank_transfer" => "Bank Transfer",
            "cstore" => "Convenience Store",
            "offline" => "Offline Payment",
            "offline_qris" => "Offline QRIS"
        ]);
    }

    public function processPayment(\CodeWithDiki\PaymentModule\Models\Payment $payment): void
    {
        (new PaymentModule())->setPaymentStatus($payment, PaymentStatus::PAID);
    }

}