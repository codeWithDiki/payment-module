<?php

namespace CodeWithDiki\PaymentModule\Webhooks\Jobs;

use CodeWithDiki\PaymentModule\Enums\DisbursementStatus;
use CodeWithDiki\PaymentModule\Facades\PaymentModule;
use Spatie\WebhookClient\Jobs\ProcessWebhookJob;

class ProcessMidtransPayoutWebhookJob extends ProcessWebhookJob
{
    public function handle(): void
    {
        $payload = $this->webhookCall->payload;

        $disbursement_status = DisbursementStatus::tryFrom($payload['status'] ?? '');

        if (! $disbursement_status) {
            return;
        }

        $disbursement = PaymentModule::getDisbursementByReferenceNo($payload['reference_no'] ?? '');

        if (! $disbursement) {
            return;
        }

        if (isset($payload['error_code']) || isset($payload['error_message'])) {
            $disbursement->update([
                'error_code' => $payload['error_code'] ?? null,
                'error_message' => $payload['error_message'] ?? null,
            ]);
        }

        PaymentModule::setDisbursementStatus($disbursement, $disbursement_status);
    }
}
