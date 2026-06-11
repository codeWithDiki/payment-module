<?php

namespace CodeWithDiki\PaymentModule\Webhooks\Jobs;

use CodeWithDiki\PaymentModule\Enums\PaymentStatus;
use CodeWithDiki\PaymentModule\Facades\PaymentModule;
use Spatie\WebhookClient\Jobs\ProcessWebhookJob;

class ProcessStripeWebhookJob extends ProcessWebhookJob
{
    public function handle(): void
    {
        $payload = $this->webhookCall->payload;

        $session = $payload['data']['object'] ?? [];

        $transaction_status = match ($payload['type'] ?? null) {
            'checkout.session.completed' => ($session['payment_status'] ?? null) === 'paid' ? PaymentStatus::PAID : null,
            'checkout.session.async_payment_succeeded' => PaymentStatus::PAID,
            'checkout.session.expired',
            'checkout.session.async_payment_failed' => PaymentStatus::FAILED,
            default => null
        };

        if (! $transaction_status) {
            return;
        }

        $transaction = PaymentModule::getPaymentByCode($session['client_reference_id'] ?? '');

        if (! $transaction) {
            return;
        }

        PaymentModule::setPaymentStatus($transaction, $transaction_status);
    }
}
