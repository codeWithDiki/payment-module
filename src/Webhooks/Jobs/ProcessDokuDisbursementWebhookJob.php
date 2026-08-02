<?php

namespace CodeWithDiki\PaymentModule\Webhooks\Jobs;

use CodeWithDiki\PaymentModule\Enums\DisbursementStatus;
use CodeWithDiki\PaymentModule\Facades\PaymentModule;
use Spatie\WebhookClient\Jobs\ProcessWebhookJob;

class ProcessDokuDisbursementWebhookJob extends ProcessWebhookJob
{
    public function handle(): void
    {
        $payload = $this->webhookCall->payload;

        $disbursement_status = match ((string) ($payload['latestTransactionStatus'] ?? '')) {
            '00' => DisbursementStatus::COMPLETED,
            '05', '06' => DisbursementStatus::FAILED,
            // 03 is still pending, nothing to transition
            default => null,
        };

        if (! $disbursement_status) {
            return;
        }

        // Kirim DOKU references the payout by its own referenceNo (our reference_no);
        // fall back to the partner reference, which is our disbursement_code.
        $disbursement = PaymentModule::getDisbursementByReferenceNo($payload['originalReferenceNo'] ?? '')
            ?? PaymentModule::getDisbursementByCode($payload['originalPartnerReferenceNo'] ?? '');

        if (! $disbursement) {
            return;
        }

        if ($disbursement_status === DisbursementStatus::FAILED) {
            $disbursement->update([
                'error_code' => (string) ($payload['latestTransactionStatus'] ?? ''),
                'error_message' => $payload['transactionStatusDesc'] ?? 'Payout failed',
            ]);
        }

        PaymentModule::setDisbursementStatus($disbursement, $disbursement_status);
    }
}
