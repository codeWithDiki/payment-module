<?php

namespace CodeWithDiki\PaymentModule\Supports\PaymentMethod;

use CodeWithDiki\PaymentModule\Models\Payment;
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

    /**
     * Offline channels have no gateway to charge, and nothing here can prove the customer
     * actually paid. The payment stays pending until an operator confirms it by hand —
     * see PaymentResource::getConfirmAction() for the Filament button that does that.
     */
    public function processPayment(Payment $payment): void
    {
        // Intentionally does nothing: confirmation is a manual, human decision.
    }
}
