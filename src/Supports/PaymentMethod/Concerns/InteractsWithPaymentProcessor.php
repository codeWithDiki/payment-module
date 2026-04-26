<?php

namespace CodeWithDiki\PaymentModule\Supports\PaymentMethod\Concerns;

use CodeWithDiki\PaymentModule\Models\Payment;

trait InteractsWithPaymentProcessor
{
    public function processPayment(Payment $payment) : void
    {
        // Implementasi logika untuk memproses pembayaran menggunakan payment processor yang sesuai
    }

    public function getChannels() : \Illuminate\Support\Collection
    {
        return collect([
            "offline" => "Offline",
        ]);
    }

}