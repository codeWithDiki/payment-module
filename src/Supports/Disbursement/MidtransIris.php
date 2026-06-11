<?php

namespace CodeWithDiki\PaymentModule\Supports\Disbursement;

use CodeWithDiki\PaymentModule\Enums\DisbursementStatus;
use CodeWithDiki\PaymentModule\Events\DisbursementFailed;
use CodeWithDiki\PaymentModule\Events\DisbursementGatewayProcessed;
use CodeWithDiki\PaymentModule\Models\Disbursement;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;

class MidtransIris implements Contracts\DisbursementProcessor
{
    public function processDisbursement(Disbursement $disbursement): void
    {
        $payload = [
            'payouts' => [
                array_filter([
                    'beneficiary_name' => $disbursement->beneficiary_name,
                    'beneficiary_account' => $disbursement->beneficiary_account,
                    'beneficiary_bank' => $disbursement->beneficiary_bank,
                    'beneficiary_email' => $disbursement->beneficiary_email,
                    'amount' => number_format($disbursement->amount, 2, '.', ''),
                    'notes' => $disbursement->notes,
                ]),
            ],
        ];

        $response = $this->client(config('payment-module.midtrans_iris_creator_key'))
            ->withHeaders(['X-Idempotency-Key' => $disbursement->disbursement_code])
            ->post('/payouts', $payload);

        $payout = $response->json('payouts.0');

        if ($response->failed() || ! isset($payout['reference_no'])) {
            $disbursement->update([
                'disbursement_payload' => $payload,
                'disbursement_response' => $response->json(),
                'status' => DisbursementStatus::FAILED,
                'error_code' => (string) $response->status(),
                'error_message' => $response->json('error_message') ?? 'Payout creation failed',
            ]);

            DisbursementFailed::dispatch($disbursement);

            return;
        }

        $disbursement->update([
            'disbursement_payload' => $payload,
            'disbursement_response' => $response->json(),
            'reference_no' => $payout['reference_no'],
            'status' => DisbursementStatus::tryFrom($payout['status'] ?? '') ?? DisbursementStatus::QUEUED,
        ]);

        DisbursementGatewayProcessed::dispatch($disbursement);
    }

    public function approveDisbursement(Disbursement $disbursement): void
    {
        $this->client(config('payment-module.midtrans_iris_approver_key'))
            ->post('/payouts/approve', [
                'reference_nos' => [$disbursement->reference_no],
            ])
            ->throw();

        $disbursement->update([
            'status' => DisbursementStatus::APPROVED,
        ]);
    }

    public function rejectDisbursement(Disbursement $disbursement, ?string $reason = null): void
    {
        $this->client(config('payment-module.midtrans_iris_approver_key'))
            ->post('/payouts/reject', [
                'reference_nos' => [$disbursement->reference_no],
                'reject_reason' => $reason ?? 'Rejected by approver',
            ])
            ->throw();

        $disbursement->update([
            'status' => DisbursementStatus::REJECTED,
        ]);
    }

    protected function client(string $apiKey): PendingRequest
    {
        return Http::baseUrl($this->getBaseUrl())
            ->withBasicAuth($apiKey, '')
            ->acceptJson()
            ->asJson();
    }

    protected function getBaseUrl(): string
    {
        return config('payment-module.midtrans_is_production')
            ? 'https://app.midtrans.com/iris/api/v1'
            : 'https://app.sandbox.midtrans.com/iris/api/v1';
    }
}
