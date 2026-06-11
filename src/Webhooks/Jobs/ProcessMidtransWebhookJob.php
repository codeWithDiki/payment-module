<?php

namespace CodeWithDiki\PaymentModule\Webhooks\Jobs;

use CodeWithDiki\PaymentModule\Enums\PaymentStatus;
use CodeWithDiki\PaymentModule\Facades\PaymentModule;
use Spatie\WebhookClient\Jobs\ProcessWebhookJob;

class ProcessMidtransWebhookJob extends ProcessWebhookJob
{
    public function handle(): void
    {
        $payload = $this->webhookCall->payload;

        $transaction_status = match ($payload['transaction_status'] ?? null) {
            'capture', 'settlement' => PaymentStatus::PAID,
            'pending' => PaymentStatus::PENDING,
            'deny', 'expire', 'cancel' => PaymentStatus::FAILED,
            default => null
        };

        if (! $transaction_status) {
            return;
        }

        $transaction = PaymentModule::getPaymentByCode($payload['order_id'] ?? '');

        if (! $transaction) {
            return;
        }

        PaymentModule::setPaymentStatus($transaction, $transaction_status);
    }
}
