<?php

namespace CodeWithDiki\PaymentModule\Webhooks\Jobs;

use CodeWithDiki\PaymentModule\Enums\PaymentStatus;
use CodeWithDiki\PaymentModule\Facades\PaymentModule;
use Illuminate\Support\Facades\Log;
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

        // Defense in depth: only honour a PAID notification when the amount the gateway
        // reports matches what we expect to bill the customer (amount + fee).
        if ($transaction_status === PaymentStatus::PAID
            && ! $this->amountMatches((float) ($payload['gross_amount'] ?? 0), $transaction->billableAmount())) {
            Log::warning('Midtrans webhook amount mismatch', [
                'payment_code' => $transaction->payment_code,
                'expected' => $transaction->billableAmount(),
                'received' => $payload['gross_amount'] ?? null,
            ]);

            return;
        }

        PaymentModule::setPaymentStatus($transaction, $transaction_status);
    }

    protected function amountMatches(float $received, float $expected): bool
    {
        return abs($received - $expected) < 0.01;
    }
}
