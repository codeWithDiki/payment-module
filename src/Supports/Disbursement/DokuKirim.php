<?php

namespace CodeWithDiki\PaymentModule\Supports\Disbursement;

use CodeWithDiki\PaymentModule\Enums\DisbursementStatus;
use CodeWithDiki\PaymentModule\Events\DisbursementFailed;
use CodeWithDiki\PaymentModule\Events\DisbursementGatewayProcessed;
use CodeWithDiki\PaymentModule\Models\Disbursement;
use CodeWithDiki\PaymentModule\Supports\Doku\SnapClient;
use Illuminate\Http\Client\Response;

/**
 * Kirim DOKU payouts over SNAP.
 *
 * DOKU requires an account inquiry before every transfer: the sessionId it returns is a
 * mandatory field of transfer-bank, so a payout is always two calls.
 *
 * Note that beneficiary_bank must hold DOKU's numeric bank code (014 BCA, 008 Mandiri,
 * 002 BRI, 009 BNI), not the alphabetic code other vendors in this package use.
 *
 * @see https://developers.doku.com/payout/kirim-doku
 */
class DokuKirim implements Contracts\DisbursementProcessor
{
    protected const ACCOUNT_INQUIRY_PATH = '/snap/v1.1/emoney/bank-account-inquiry';

    protected const TRANSFER_BANK_PATH = '/snap/v1.1/emoney/transfer-bank';

    protected const CHANNEL_ID = 'KD';

    public function __construct(protected SnapClient $client) {}

    public function processDisbursement(Disbursement $disbursement): void
    {
        $inquiry = $this->inquireAccount($disbursement);
        $sessionId = $inquiry->json('additionalInfo.sessionId') ?? $inquiry->json('sessionId');

        if ($inquiry->failed() || ! $sessionId) {
            $this->fail($disbursement, $inquiry, 'Account inquiry failed');

            return;
        }

        $payload = $this->transferPayload($disbursement, $sessionId, $inquiry);

        $transfer = $this->client->post(self::TRANSFER_BANK_PATH, $payload, self::CHANNEL_ID);

        $referenceNo = $transfer->json('referenceNo');

        if ($transfer->failed() || ! $referenceNo) {
            $this->fail($disbursement, $transfer, 'Payout creation failed', $payload);

            return;
        }

        $disbursement->update([
            'disbursement_payload' => $payload,
            'disbursement_response' => $transfer->json(),
            'reference_no' => $referenceNo,
            // Kirim DOKU settles asynchronously; the final status arrives via notification
            'status' => DisbursementStatus::PROCESSED,
        ]);

        DisbursementGatewayProcessed::dispatch($disbursement);
    }

    public function approveDisbursement(Disbursement $disbursement): void
    {
        throw new \BadMethodCallException('Kirim DOKU processes payouts automatically; approval is not applicable.');
    }

    public function rejectDisbursement(Disbursement $disbursement, ?string $reason = null): void
    {
        throw new \BadMethodCallException('Kirim DOKU processes payouts automatically; rejection is not applicable.');
    }

    protected function inquireAccount(Disbursement $disbursement): Response
    {
        return $this->client->post(self::ACCOUNT_INQUIRY_PATH, [
            'partnerReferenceNo' => $disbursement->disbursement_code,
            'beneficiaryBankCode' => $disbursement->beneficiary_bank,
            'beneficiaryAccountNumber' => $disbursement->beneficiary_account,
            'customerNumber' => (string) config('payment-module.doku_sender_phone'),
            'amount' => self::money($disbursement->amount),
        ], self::CHANNEL_ID);
    }

    protected function transferPayload(Disbursement $disbursement, string $sessionId, Response $inquiry): array
    {
        [$firstName, $lastName] = self::splitName($disbursement->beneficiary_name);
        [$senderFirstName, $senderLastName] = self::splitName((string) config('payment-module.doku_sender_name'));

        return [
            'partnerReferenceNo' => $disbursement->disbursement_code,
            'customerNumber' => (string) config('payment-module.doku_sender_phone'),
            'beneficiaryAccountNumber' => $disbursement->beneficiary_account,
            'beneficiaryBankCode' => $disbursement->beneficiary_bank,
            'amount' => self::money($disbursement->amount),
            'sessionId' => $sessionId,
            'additionalInfo' => [
                'beneficiaryFirstName' => $firstName,
                'beneficiaryLastName' => $lastName,
                'beneficiaryPhoneNumber' => (string) $disbursement->beneficiary_phone,
                // Prefer the name the bank confirmed during inquiry over the one we were given
                'beneficiaryAccountName' => $inquiry->json('beneficiaryAccountName')
                    ?? $inquiry->json('additionalInfo.beneficiaryAccountName')
                    ?? $disbursement->beneficiary_name,
                'senderCountryCode' => (string) config('payment-module.doku_sender_country_code'),
                'senderFirstName' => $senderFirstName,
                'senderLastName' => $senderLastName,
                'senderPersonalId' => (string) config('payment-module.doku_sender_personal_id'),
                'senderPersonalIdType' => (string) config('payment-module.doku_sender_personal_id_type'),
                'remark' => $disbursement->notes ?: 'Disbursement '.$disbursement->disbursement_code,
            ],
        ];
    }

    protected function fail(Disbursement $disbursement, Response $response, string $fallbackMessage, ?array $payload = null): void
    {
        $disbursement->update([
            'disbursement_payload' => $payload,
            'disbursement_response' => $response->json(),
            'status' => DisbursementStatus::FAILED,
            'error_code' => (string) ($response->json('responseCode') ?? $response->status()),
            'error_message' => $response->json('responseMessage') ?? $fallbackMessage,
        ]);

        DisbursementFailed::dispatch($disbursement);
    }

    /**
     * SNAP carries amounts as a two-decimal string plus an ISO 4217 currency.
     */
    protected static function money(float $amount): array
    {
        return [
            'value' => number_format($amount, 2, '.', ''),
            'currency' => 'IDR',
        ];
    }

    /**
     * DOKU wants first and last name separately; we only store a single name.
     *
     * @return array{0: string, 1: string}
     */
    protected static function splitName(string $name): array
    {
        $parts = preg_split('/\s+/', trim($name), 2) ?: [''];

        return [$parts[0], $parts[1] ?? ''];
    }
}
