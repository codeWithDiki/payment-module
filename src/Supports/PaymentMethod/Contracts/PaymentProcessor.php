<?php

namespace CodeWithDiki\PaymentModule\Supports\PaymentMethod\Contracts;

use CodeWithDiki\PaymentModule\Models\Payment;
use Illuminate\Support\Collection;

interface PaymentProcessor
{
    public function processPayment(Payment $payment) : void;

    public function getChannels() : Collection;

}