<?php

namespace CodeWithDiki\PaymentModule\Supports\Disbursement;

use CodeWithDiki\PaymentModule\Enums\DisbursementStatus;
use CodeWithDiki\PaymentModule\Events\DisbursementFailed;
use CodeWithDiki\PaymentModule\Events\DisbursementGatewayProcessed;
use CodeWithDiki\PaymentModule\Models\Disbursement;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;

class XenditDisbursement implements Contracts\DisbursementProcessor
{
    protected const BASE_URL = 'https://api.xendit.co';

    public function processDisbursement(Disbursement $disbursement): void
    {
        // Xendit disburses immediately (single-step, no maker-approver), so the payout
        // is sent as soon as the disbursement is created.
        $payload = array_filter([
            'external_id' => $disbursement->disbursement_code,
            'amount' => $disbursement->amount,
            'bank_code' => $disbursement->beneficiary_bank,
            'account_holder_name' => $disbursement->beneficiary_name,
            'account_number' => $disbursement->beneficiary_account,
            'description' => $disbursement->notes ?: 'Disbursement '.$disbursement->disbursement_code,
            'email_to' => $disbursement->beneficiary_email ? [$disbursement->beneficiary_email] : null,
        ]);

        $response = $this->client()
            ->withHeaders(['X-IDEMPOTENCY-KEY' => $disbursement->disbursement_code])
            ->post('/disbursements', $payload);

        $body = $response->json();

        if ($response->failed() || ! isset($body['id'])) {
            $disbursement->update([
                'disbursement_payload' => $payload,
                'disbursement_response' => $body,
                'status' => DisbursementStatus::FAILED,
                'error_code' => (string) $response->status(),
                'error_message' => $body['message'] ?? 'Disbursement creation failed',
            ]);

            DisbursementFailed::dispatch($disbursement);

            return;
        }

        $disbursement->update([
            'disbursement_payload' => $payload,
            'disbursement_response' => $body,
            'reference_no' => $body['id'],
            // Xendit returns PENDING on creation; the final status arrives via webhook
            'status' => DisbursementStatus::PROCESSED,
        ]);

        DisbursementGatewayProcessed::dispatch($disbursement);
    }

    public function approveDisbursement(Disbursement $disbursement): void
    {
        throw new \BadMethodCallException('Xendit processes payouts automatically; approval is not applicable.');
    }

    public function rejectDisbursement(Disbursement $disbursement, ?string $reason = null): void
    {
        throw new \BadMethodCallException('Xendit processes payouts automatically; rejection is not applicable.');
    }

    protected function client(): PendingRequest
    {
        return Http::baseUrl(self::BASE_URL)
            ->withBasicAuth(config('payment-module.xendit_secret_key'), '')
            ->acceptJson()
            ->asJson();
    }
}
