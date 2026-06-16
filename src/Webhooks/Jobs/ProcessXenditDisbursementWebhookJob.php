<?php

namespace CodeWithDiki\PaymentModule\Webhooks\Jobs;

use CodeWithDiki\PaymentModule\Enums\DisbursementStatus;
use CodeWithDiki\PaymentModule\Facades\PaymentModule;
use Spatie\WebhookClient\Jobs\ProcessWebhookJob;

class ProcessXenditDisbursementWebhookJob extends ProcessWebhookJob
{
    public function handle(): void
    {
        $payload = $this->webhookCall->payload;

        $data = $payload['data'] ?? $payload;

        $disbursement_status = match (strtoupper((string) ($data['status'] ?? ''))) {
            'COMPLETED' => DisbursementStatus::COMPLETED,
            'FAILED' => DisbursementStatus::FAILED,
            default => null,
        };

        if (! $disbursement_status) {
            return;
        }

        // Xendit references the payout by its own id (our reference_no); fall back to external_id
        $disbursement = PaymentModule::getDisbursementByReferenceNo($data['id'] ?? '')
            ?? PaymentModule::getDisbursementByCode($data['external_id'] ?? '');

        if (! $disbursement) {
            return;
        }

        if (isset($data['failure_code'])) {
            $disbursement->update([
                'error_code' => $data['failure_code'],
                'error_message' => $data['failure_code'],
            ]);
        }

        PaymentModule::setDisbursementStatus($disbursement, $disbursement_status);
    }
}
