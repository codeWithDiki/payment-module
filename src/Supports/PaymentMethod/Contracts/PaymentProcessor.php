<?php

namespace CodeWithDiki\PaymentModule\Supports\PaymentMethod\Contracts;

use CodeWithDiki\PaymentModule\Data\PaymentInstruction;
use CodeWithDiki\PaymentModule\Models\Payment;
use Illuminate\Support\Collection;

interface PaymentProcessor
{
    public function processPayment(Payment $payment): void;

    public function getChannels(): Collection;

    /**
     * Gateway response translated into the shared instruction shape. Null when the channel
     * has nothing for the customer to act on (offline channels, or a failed charge).
     */
    public function getPaymentInstruction(Payment $payment): ?PaymentInstruction;
}
