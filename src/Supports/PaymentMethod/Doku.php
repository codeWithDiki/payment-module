<?php

namespace CodeWithDiki\PaymentModule\Supports\PaymentMethod;

use CodeWithDiki\PaymentModule\Enums\PaymentStatus;
use CodeWithDiki\PaymentModule\Events\PaymentGatewayProcessed;
use CodeWithDiki\PaymentModule\Models\Payment;
use CodeWithDiki\PaymentModule\PaymentModule;
use CodeWithDiki\PaymentModule\Supports\Doku\SnapClient;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Collection;

/**
 * DOKU SNAP Direct API. The channel stored on the payment method is DOKU's own channel
 * code, so it can be passed straight through as additionalInfo.channel.
 *
 * @see https://developers.doku.com/accept-payments/direct-api/snap
 */
class Doku implements Contracts\PaymentProcessor
{
    use Concerns\InteractsWithPaymentProcessor;

    protected const VIRTUAL_ACCOUNT_PATH = '/virtual-accounts/bi-snap-va/v1.1/transfer-va/create-va';

    protected const EWALLET_PATH = '/direct-debit/core/v1/debit/payment-host-to-host';

    protected const QRIS_PATH = '/snap-adapter/b2b/v1.0/qr/qr-mpm-generate';

    /**
     * Channel codes settled through the Direct Debit "jump app" API. DOKU's own SDK accepts
     * only these two: OVO, Allo and BRI go through account binding instead, which needs a
     * customer-scoped B2B2C token this package does not issue.
     *
     * @see https://github.com/PTNUSASATUINTIARTHA-DOKU/doku-php-library
     */
    protected const EWALLET_CHANNELS = [
        'EMONEY_DANA_SNAP', 'EMONEY_SHOPEE_PAY_SNAP',
    ];

    public function __construct(protected SnapClient $client) {}

    public function getChannels(): Collection
    {
        return collect([
            'VIRTUAL_ACCOUNT_BCA' => 'BCA Virtual Account',
            'VIRTUAL_ACCOUNT_BNI' => 'BNI Virtual Account',
            'VIRTUAL_ACCOUNT_BRI' => 'BRI Virtual Account',
            'VIRTUAL_ACCOUNT_BANK_MANDIRI' => 'Mandiri Virtual Account',
            'VIRTUAL_ACCOUNT_BANK_PERMATA' => 'Permata Virtual Account',
            'VIRTUAL_ACCOUNT_BANK_CIMB' => 'CIMB Niaga Virtual Account',
            'VIRTUAL_ACCOUNT_BANK_DANAMON' => 'Danamon Virtual Account',
            'VIRTUAL_ACCOUNT_BSI' => 'BSI Virtual Account',
            'VIRTUAL_ACCOUNT_BNC' => 'BNC Virtual Account',
            'VIRTUAL_ACCOUNT_BTN' => 'BTN Virtual Account',
            'VIRTUAL_ACCOUNT_MAYBANK' => 'Maybank Virtual Account',
            'VIRTUAL_ACCOUNT_SINARMAS' => 'Sinarmas Virtual Account',
            'VIRTUAL_ACCOUNT_BSS' => 'Bank Sahabat Sampoerna Virtual Account',
            'VIRTUAL_ACCOUNT_DOKU' => 'DOKU Virtual Account',
            'EMONEY_DANA_SNAP' => 'DANA',
            'EMONEY_SHOPEE_PAY_SNAP' => 'ShopeePay',
            'QRIS' => 'QRIS',
        ]);
    }

    public function processPayment(Payment $payment): void
    {
        $channel = $payment->paymentMethod->channel;
        // Bill the customer the total (amount + payment-method fee)
        $amount = $payment->billableAmount();

        $response = match (true) {
            $channel === 'QRIS' => $this->createQrCode($payment, $amount),
            in_array($channel, self::EWALLET_CHANNELS, true) => $this->createEwalletCharge($payment, $channel, $amount),
            default => $this->createVirtualAccount($payment, $channel, $amount),
        };

        $payment->update([
            'payment_headers' => $this->client->lastRequest['headers'] ?? null,
            'payment_payload' => $this->client->lastRequest['body'] ?? null,
            'payment_response' => SnapClient::describe($response),
        ]);

        // A rejected charge leaves nothing for the customer to pay; fail the payment loudly
        // instead of leaving it pending forever with an error body as its response.
        if ($response->failed()) {
            (new PaymentModule)->setPaymentStatus($payment, PaymentStatus::FAILED);

            return;
        }

        PaymentGatewayProcessed::dispatch($payment);
    }

    /**
     * DOKU Generated Payment Code: we send the VA prefix and DOKU returns the full number.
     * The prefix is assigned per bank by DOKU and lives in the payment method's meta_data.
     */
    protected function createVirtualAccount(Payment $payment, string $channel, float $amount): Response
    {
        $partnerServiceId = $this->partnerServiceId($payment);
        $customerNo = (string)$payment->id . (string)$payment->created_at->format("YmdHis");

        if(strlen($customerNo) > 20){
            $customerNo = substr((string) $customerNo, 0, 20);
        }

        return $this->client->post(self::VIRTUAL_ACCOUNT_PATH, [
            'partnerServiceId' => trim($partnerServiceId),
            'customerNo' => $customerNo,
            'virtualAccountNo' => trim($partnerServiceId . $customerNo),
            'virtualAccountName' => $payment->customer_name ?: $payment->payment_code,
            'virtualAccountEmail' => $payment->customer_email,
            'virtualAccountPhone' => $payment->customer_phone,
            'trxId' => $payment->payment_code,
            // C = closed amount: the customer must pay exactly this amount
            'virtualAccountTrxType' => 'C',
            'totalAmount' => self::money($amount),
            'expiredDate' => self::expiry(),
            'additionalInfo' => [
                'channel' => $channel,
                'virtualAccountConfig' => ['reusableStatus' => false],
            ],
        ], 'H2H');
    }

    /**
     * Direct Debit "jump app": DOKU answers with a webRedirectUrl that hands the customer to
     * the DANA/ShopeePay app or web checkout, and calls back to urlParam once they are done.
     * The endpoint carries no CHANNEL-ID header, and rejects a request without urlParam.
     *
     * @see https://developers.doku.com/accept-payments/direct-api/snap/integration-guide/e-wallet
     */
    protected function createEwalletCharge(Payment $payment, string $channel, float $amount): Response
    {
        return $this->client->post(self::EWALLET_PATH, [
            'partnerReferenceNo' => $payment->payment_code,
            'validUpTo' => self::expiry(),
            'pointOfInitiation' => 'website',
            'urlParam' => [[
                'url' => $this->resolveUrl(config('payment-module.doku_payment_return_url'), $payment->payment_code),
                'type' => 'PAY_RETURN',
                'isDeepLink' => 'N',
            ]],
            'amount' => self::money($amount),
            'additionalInfo' => [
                'channel' => $channel,
                'orderTitle' => $payment->payment_code,
            ],
        ], extraHeaders: ['X-IP-ADDRESS' => (string) request()->ip()]);
    }

    /**
     * qr-mpm-generate requires partnerReferenceNo, amount, merchantId, terminalId and
     * additionalInfo{postalCode, feeType}. Unlike the other channels it takes no
     * additionalInfo.channel, and its CHANNEL-ID header is H2H rather than QRIS.
     *
     * @see https://developers.doku.com/accept-payments/direct-api/snap/integration-guide/qris
     */
    protected function createQrCode(Payment $payment, float $amount): Response
    {
        return $this->client->post(self::QRIS_PATH, [
            'partnerReferenceNo' => $payment->payment_code,
            'amount' => self::money($amount),
            'merchantId' => config('payment-module.doku_client_id'),
            'terminalId' => (string) config('payment-module.doku_qris_terminal_id'),
            'additionalInfo' => [
                'postalCode' => (string) config('payment-module.doku_qris_postal_code'),
                // The spec allows exactly one value: "1" (no tips). Not worth a config key.
                'feeType' => '1',
            ],
        ], 'H2H');
    }

    /**
     * When the payment stops being payable, as ISO8601 with an offset. Jakarta time, because
     * that is the wall clock the customer sees on the VA slip and in the e-wallet app.
     * Drives both the VA's expiredDate and the e-wallet's validUpTo.
     */
    protected static function expiry(): string
    {
        return now()
            ->addMinutes((int) config('payment-module.doku_payment_expiry_minutes', 60))
            ->setTimezone('Asia/Jakarta')
            ->format('c');
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
     * VA prefix (company code), 8 characters left-padded with spaces per the SNAP spec.
     */
    protected function partnerServiceId(Payment $payment): string
    {
        $prefix = $payment->paymentMethod->meta_data['partner_service_id'] ?? '';

        return str_pad(trim((string) $prefix), 8, ' ', STR_PAD_LEFT);
    }
}
