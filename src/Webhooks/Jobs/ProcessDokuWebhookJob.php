<?php

namespace CodeWithDiki\PaymentModule\Webhooks\Jobs;

use CodeWithDiki\PaymentModule\Enums\PaymentStatus;
use CodeWithDiki\PaymentModule\Facades\PaymentModule;
use Illuminate\Support\Facades\Log;
use Spatie\WebhookClient\Jobs\ProcessWebhookJob;

class ProcessDokuWebhookJob extends ProcessWebhookJob
{
    public function handle(): void
    {
        $payload = $this->webhookCall->payload;

        // Each DOKU product names the merchant reference differently: SNAP Virtual Account
        // sends trxId, SNAP e-wallet and QRIS send originalPartnerReferenceNo, and a
        // Checkout notification nests it as order.invoice_number.
        $reference = $payload['trxId']
            ?? $payload['originalPartnerReferenceNo']
            ?? $payload['partnerReferenceNo']
            ?? $payload['order']['invoice_number']
            ?? null;

        if (! $reference) {
            return;
        }

        $transaction_status = $this->resolveStatus($payload);

        if (! $transaction_status) {
            return;
        }

        $transaction = PaymentModule::getPaymentByCode($reference);

        if (! $transaction) {
            return;
        }

        // Defense in depth: verify the paid amount matches what we expect to bill (amount + fee)
        if ($transaction_status === PaymentStatus::PAID) {
            $received = $payload['paidAmount']['value']
                ?? $payload['amount']['value']
                // Checkout sends a plain integer in the smallest unit, not a decimal string
                ?? $payload['order']['amount']
                ?? null;

            if ($received !== null && abs((float) $received - $transaction->billableAmount()) >= 0.01) {
                Log::warning('Doku webhook amount mismatch', [
                    'payment_code' => $transaction->payment_code,
                    'expected' => $transaction->billableAmount(),
                    'received' => $received,
                ]);

                return;
            }
        }

        PaymentModule::setPaymentStatus($transaction, $transaction_status);
    }

    protected function resolveStatus(array $payload): ?PaymentStatus
    {
        // Checkout spells the outcome out in a word instead of a code
        if (isset($payload['transaction']['status'])) {
            return match (strtoupper((string) $payload['transaction']['status'])) {
                'SUCCESS' => PaymentStatus::PAID,
                'FAILED', 'EXPIRED' => PaymentStatus::FAILED,
                // PENDING and REFUNDED are not ours to act on here
                default => null,
            };
        }

        // E-wallet and QRIS report a two digit transaction status
        if (isset($payload['latestTransactionStatus'])) {
            return match ((string) $payload['latestTransactionStatus']) {
                '00' => PaymentStatus::PAID,
                '04', '05', '06' => PaymentStatus::FAILED,
                // 03 is still pending, nothing to transition
                default => null,
            };
        }

        // A Virtual Account payment notification is only sent once the customer has paid,
        // so the presence of a paid amount is the success signal.
        return isset($payload['paidAmount']) ? PaymentStatus::PAID : null;
    }
}
