<?php

namespace CodeWithDiki\PaymentModule\Supports\PaymentMethod;

use CodeWithDiki\PaymentModule\Enums\PaymentStatus;
use CodeWithDiki\PaymentModule\Models\Payment;
use CodeWithDiki\PaymentModule\PaymentModule;
use Illuminate\Support\Collection;

class Offline implements Contracts\PaymentProcessor
{
    use Concerns\InteractsWithPaymentProcessor;

    public function getChannels(): Collection
    {
        return collect([
            'bank_transfer' => 'Bank Transfer',
            'cstore' => 'Convenience Store',
            'offline' => 'Offline Payment',
            'offline_qris' => 'Offline QRIS',
        ]);
    }

    public function processPayment(Payment $payment): void
    {
        (new PaymentModule)->setPaymentStatus($payment, PaymentStatus::PAID);
    }
}
