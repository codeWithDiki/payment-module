<?php

namespace CodeWithDiki\PaymentModule\Webhooks\Jobs;

use CodeWithDiki\PaymentModule\Enums\DisbursementStatus;
use CodeWithDiki\PaymentModule\Facades\PaymentModule;
use Spatie\WebhookClient\Jobs\ProcessWebhookJob;

/**
 * Handles Flip for Business money transfer (disbursement) callbacks.
 *
 * Flip sends a form-encoded POST with the disbursement object inside the "data" key.
 * The `id` field is Flip's own transaction ID (our reference_no); the `idempotency_key`
 * field matches our disbursement_code.
 *
 * @see https://docs.flip.id/money-transfer/disbursement-callback
 */
class ProcessFlipDisbursementWebhookJob extends ProcessWebhookJob
{
    public function handle(): void
    {
        $payload = $this->webhookCall->payload;

        $data = $payload['data'] ?? $payload;

        if (is_string($data)) {
            $data = json_decode($data, true) ?? [];
        }

        $status = $this->resolveStatus($data);

        if (! $status) {
            return;
        }

        // Look up by Flip's own transaction ID (stored as reference_no), then fall back
        // to our disbursement_code stored in idempotency_key.
        $disbursement = PaymentModule::getDisbursementByReferenceNo((string) ($data['id'] ?? ''))
            ?? PaymentModule::getDisbursementByCode((string) ($data['idempotency_key'] ?? ''));

        if (! $disbursement) {
            return;
        }

        if ($status === DisbursementStatus::FAILED) {
            $disbursement->update([
                'error_code' => $data['failure_reason'] ?? 'UNKNOWN',
                'error_message' => $data['failure_reason'] ?? 'Disbursement failed',
            ]);
        }

        PaymentModule::setDisbursementStatus($disbursement, $status);
    }

    protected function resolveStatus(array $data): ?DisbursementStatus
    {
        $rawStatus = strtoupper((string) ($data['status'] ?? ''));

        return match ($rawStatus) {
            'DONE' => DisbursementStatus::COMPLETED,
            'FAILED', 'CANCELLED' => DisbursementStatus::FAILED,
            // PENDING and PROCESSING don't need a transition
            default => null,
        };
    }
}
