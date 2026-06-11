<?php

namespace CodeWithDiki\PaymentModule\Listeners;

use CodeWithDiki\PaymentModule\Events\DisbursementCreated;
use CodeWithDiki\PaymentModule\Exceptions\DisbursementNotSupportedException;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;

class ProcessingDisbursementGateway implements ShouldQueue
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
    public function handle(DisbursementCreated $event): void
    {
        $disbursement = $event->disbursement;

        $processor_class = $disbursement->vendor->getDisbursementProcessorClass();

        if (! $processor_class) {
            throw DisbursementNotSupportedException::forVendor($disbursement->vendor);
        }

        app($processor_class)->processDisbursement($disbursement);
    }
}
