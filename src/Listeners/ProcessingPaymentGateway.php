<?php

namespace CodeWithDiki\PaymentModule\Listeners;

use CodeWithDiki\PaymentModule\Events\PaymentCreated;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;

class ProcessingPaymentGateway implements ShouldQueue
{
    use InteractsWithQueue;
    /**
     * Create the event listener.
     */
    public function __construct()
    {
        //
    }

    /**
     * Handle the event.
     */
    public function handle(PaymentCreated $event): void
    {
        $payment = $event->payment;
        $payment_method = $payment->paymentMethod;

        $payment_gateway = $payment_method->vendor;
        $payment_processor = app($payment_gateway->getPaymentProcessorClass());

        $payment_processor->processPayment($payment);
    }
}
