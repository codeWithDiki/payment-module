<?php

namespace CodeWithDiki\PaymentModule\Supports\Disbursement;

use CodeWithDiki\PaymentModule\Enums\DisbursementStatus;
use CodeWithDiki\PaymentModule\Events\DisbursementFailed;
use CodeWithDiki\PaymentModule\Events\DisbursementGatewayProcessed;
use CodeWithDiki\PaymentModule\Models\Disbursement;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;

/**
 * Flip for Business — Money Transfer / Disbursement API.
 *
 * Flip processes payouts immediately (single-step, no maker-approver). The payout
 * is dispatched as soon as the disbursement is created, and the final status is
 * delivered via webhook.
 *
 * Authentication: HTTP Basic Auth — secret key as username, empty password.
 *
 * @see https://docs.flip.id/money-transfer/general-information
 */
class FlipDisbursement implements Contracts\DisbursementProcessor
{
    protected const SANDBOX_BASE_URL = 'https://bigflip.id/big_sandbox_api/v2';

    protected const PRODUCTION_BASE_URL = 'https://bigflip.id/api/v3';

    public function processDisbursement(Disbursement $disbursement): void
    {
        $payload = array_filter([
            'account_number' => $disbursement->beneficiary_account,
            'bank_code' => strtolower($disbursement->beneficiary_bank),
            'amount' => (int) $disbursement->amount,
            'remark' => $disbursement->notes ?: 'Disbursement '.$disbursement->disbursement_code,
            'beneficiary_email' => $disbursement->beneficiary_email,
            'idempotency_key' => $disbursement->disbursement_code,
        ]);

        $response = $this->client()
            ->withHeaders(['idempotency-key' => $disbursement->disbursement_code])
            ->post('/disburse', $payload);

        $body = $response->json();

        if ($response->failed() || empty($body['id'])) {
            $disbursement->update([
                'disbursement_payload' => $payload,
                'disbursement_response' => $body,
                'status' => DisbursementStatus::FAILED,
                'error_code' => (string) ($body['code'] ?? $response->status()),
                'error_message' => $body['errors'][0]['message'] ?? ($body['message'] ?? 'Disbursement creation failed'),
            ]);

            DisbursementFailed::dispatch($disbursement);

            return;
        }

        $disbursement->update([
            'disbursement_payload' => $payload,
            'disbursement_response' => $body,
            'reference_no' => (string) $body['id'],
            // Flip returns PENDING on creation; the final status arrives via webhook
            'status' => DisbursementStatus::PROCESSED,
        ]);

        DisbursementGatewayProcessed::dispatch($disbursement);
    }

    public function approveDisbursement(Disbursement $disbursement): void
    {
        throw new \BadMethodCallException('Flip processes payouts automatically; approval is not applicable.');
    }

    public function rejectDisbursement(Disbursement $disbursement, ?string $reason = null): void
    {
        throw new \BadMethodCallException('Flip processes payouts automatically; rejection is not applicable.');
    }

    protected function client(): PendingRequest
    {
        $baseUrl = config('payment-module.flip_is_production', false)
            ? self::PRODUCTION_BASE_URL
            : self::SANDBOX_BASE_URL;

        return Http::baseUrl($baseUrl)
            ->withBasicAuth(config('payment-module.flip_secret_key'), '')
            ->acceptJson()
            ->asForm();
    }
}
