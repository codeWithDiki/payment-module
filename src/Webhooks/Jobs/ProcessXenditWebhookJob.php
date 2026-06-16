<?php

namespace CodeWithDiki\PaymentModule\Webhooks\Jobs;

use CodeWithDiki\PaymentModule\Enums\PaymentStatus;
use CodeWithDiki\PaymentModule\Facades\PaymentModule;
use Illuminate\Support\Facades\Log;
use Spatie\WebhookClient\Jobs\ProcessWebhookJob;

class ProcessXenditWebhookJob extends ProcessWebhookJob
{
    public function handle(): void
    {
        $payload = $this->webhookCall->payload;

        // Xendit callback bodies differ per product (VA, eWallet, QR). The relevant
        // fields live either at the top level or nested under "data".
        $data = $payload['data'] ?? $payload;

        $reference = $payload['external_id']
            ?? $data['reference_id']
            ?? $data['external_id']
            ?? null;

        if (! $reference) {
            return;
        }

        $transaction_status = $this->resolveStatus($payload, $data);

        if (! $transaction_status) {
            return;
        }

        $transaction = PaymentModule::getPaymentByCode($reference);

        if (! $transaction) {
            return;
        }

        // Defense in depth: verify the paid amount matches what we expect to bill (amount + fee)
        if ($transaction_status === PaymentStatus::PAID) {
            $received = $data['amount'] ?? $data['charge_amount'] ?? $payload['amount'] ?? null;

            if ($received !== null && abs((float) $received - $transaction->billableAmount()) >= 0.01) {
                Log::warning('Xendit webhook amount mismatch', [
                    'payment_code' => $transaction->payment_code,
                    'expected' => $transaction->billableAmount(),
                    'received' => $received,
                ]);

                return;
            }
        }

        PaymentModule::setPaymentStatus($transaction, $transaction_status);
    }

    protected function resolveStatus(array $payload, array $data): ?PaymentStatus
    {
        $rawStatus = $data['status'] ?? $payload['status'] ?? null;

        $status = match (strtoupper((string) $rawStatus)) {
            'PAID', 'SUCCEEDED', 'COMPLETED', 'SUCCESS' => PaymentStatus::PAID,
            'FAILED', 'EXPIRED', 'VOIDED' => PaymentStatus::FAILED,
            default => null,
        };

        // Closed Virtual Account callbacks carry no status field; the presence of a
        // payment identifier means the VA was paid.
        if ($status === null && (isset($payload['payment_id']) || isset($payload['callback_virtual_account_id']))) {
            return PaymentStatus::PAID;
        }

        return $status;
    }
}
