<?php

namespace CodeWithDiki\PaymentModule\Webhooks\Jobs;

use CodeWithDiki\PaymentModule\Enums\PaymentStatus;
use CodeWithDiki\PaymentModule\Facades\PaymentModule;
use Illuminate\Support\Facades\Log;
use Spatie\WebhookClient\Jobs\ProcessWebhookJob;

/**
 * Handles Flip for Business payment (Accept Payment / Pay With Flip) callbacks.
 *
 * Flip sends a form-encoded POST to the redirect_url (or a separately configured
 * callback URL) containing the bill data. The `bill_link_id` is the link_id we
 * stored in `payment_response.link_id` during createBill; we use it as a secondary
 * lookup key via the payment_response JSON column when needed, but the primary
 * lookup key is the bill's `title` field — which we set to payment_code.
 *
 * @see https://docs.flip.id/accept-payments/payment-callback
 */
class ProcessFlipWebhookJob extends ProcessWebhookJob
{
    public function handle(): void
    {
        $payload = $this->webhookCall->payload;

        // Flip sends the bill object inside the "data" key as a JSON string.
        // After spatie/webhook-client parses the request, it may already be decoded.
        $data = $payload['data'] ?? $payload;

        if (is_string($data)) {
            $data = json_decode($data, true) ?? [];
        }

        // The bill title is our payment_code; this is the canonical reference.
        $reference = $data['title'] ?? $data['bill_title'] ?? null;

        if (! $reference) {
            return;
        }

        $status = $this->resolveStatus($data);

        if (! $status) {
            return;
        }

        $transaction = PaymentModule::getPaymentByCode($reference);

        if (! $transaction) {
            return;
        }

        // Defense in depth: verify the paid amount matches what we expect to bill.
        if ($status === PaymentStatus::PAID) {
            $received = $data['amount'] ?? null;

            if ($received !== null && abs((float) $received - $transaction->billableAmount()) >= 0.01) {
                Log::warning('Flip webhook amount mismatch', [
                    'payment_code' => $transaction->payment_code,
                    'expected' => $transaction->billableAmount(),
                    'received' => $received,
                ]);

                return;
            }
        }

        PaymentModule::setPaymentStatus($transaction, $status);
    }

    protected function resolveStatus(array $data): ?PaymentStatus
    {
        $rawStatus = strtoupper((string) ($data['status'] ?? ''));

        return match ($rawStatus) {
            'SUCCESSFUL', 'PAID', 'COMPLETED' => PaymentStatus::PAID,
            'FAILED', 'CANCELLED', 'EXPIRED' => PaymentStatus::FAILED,
            default => null,
        };
    }
}
