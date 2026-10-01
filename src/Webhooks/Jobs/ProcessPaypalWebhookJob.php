<?php

namespace CodeWithDiki\PaymentModule\Webhooks\Jobs;

use CodeWithDiki\PaymentModule\Enums\PaymentStatus;
use CodeWithDiki\PaymentModule\Facades\PaymentModule;
use Spatie\WebhookClient\Jobs\ProcessWebhookJob;

/**
 * Handles PayPal webhook events (PAYMENT.CAPTURE.COMPLETED, etc.).
 *
 * The payment_code is stored as the first purchase unit's reference_id when the
 * order was created. PayPal sends the full resource object back in the webhook
 * payload.
 *
 * @see https://developer.paypal.com/docs/api/webhooks/v1/#webhook-event
 */
class ProcessPaypalWebhookJob extends ProcessWebhookJob
{
    public function handle(): void
    {
        /** @var array $payload */
        $payload = $this->webhookCall->payload;
        $eventType = (string) ($payload['event_type'] ?? '');
        /** @var array|null $resource */
        $resource = $payload['resource'] ?? [];

        $status = $this->resolveStatus($eventType);

        if (! $status) {
            return;
        }

        $reference = $resource['purchase_units'][0]['reference_id'] ?? null;

        if (! $reference) {
            return;
        }

        $transaction = PaymentModule::getPaymentByCode($reference);

        if (! $transaction) {
            return;
        }

        PaymentModule::setPaymentStatus($transaction, $status);
    }

    protected function resolveStatus(string $eventType): ?PaymentStatus
    {
        return match ($eventType) {
            'PAYMENT.CAPTURE.COMPLETED', 'CHECKOUT.ORDER.APPROVED' => PaymentStatus::PAID,
            'PAYMENT.CAPTURE.DENIED', 'PAYMENT.CAPTURE.REFUNDED', 'PAYMENT.CAPTURE.REVERSED' => PaymentStatus::FAILED,
            default => null,
        };
    }
}
