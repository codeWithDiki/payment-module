<?php

namespace CodeWithDiki\PaymentModule\Supports\PaymentMethod\Concerns;

use CodeWithDiki\PaymentModule\Models\Payment;
use Illuminate\Support\Collection;

trait InteractsWithPaymentProcessor
{
    public function processPayment(Payment $payment): void
    {
        // Implementasi logika untuk memproses pembayaran menggunakan payment processor yang sesuai
    }

    public function getChannels(): Collection
    {
        return collect([
            'offline' => 'Offline',
        ]);
    }
}
