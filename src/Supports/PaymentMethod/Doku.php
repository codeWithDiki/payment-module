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

    /** Channel codes settled through the e-wallet (direct debit) API */
    protected const EWALLET_CHANNELS = [
        'EMONEY_OVO', 'EMONEY_DANA', 'EMONEY_SHOPEEPAY',
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
            'VIRTUAL_ACCOUNT_BANK_SYARIAH_MANDIRI' => 'BSI Virtual Account',
            'VIRTUAL_ACCOUNT_BANK_DANAMON' => 'Danamon Virtual Account',
            'VIRTUAL_ACCOUNT_BNC' => 'BNC Virtual Account',
            'VIRTUAL_ACCOUNT_BTN' => 'BTN Virtual Account',
            'VIRTUAL_ACCOUNT_DOKU' => 'DOKU Virtual Account',
            'EMONEY_OVO' => 'OVO',
            'EMONEY_DANA' => 'DANA',
            'EMONEY_SHOPEEPAY' => 'ShopeePay',
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

        return $this->client->post(self::VIRTUAL_ACCOUNT_PATH, [
            'partnerServiceId' => $partnerServiceId,
            'customerNo' => '',
            'virtualAccountNo' => trim($partnerServiceId),
            'virtualAccountName' => $payment->customer_name ?: $payment->payment_code,
            'virtualAccountEmail' => $payment->customer_email,
            'virtualAccountPhone' => $payment->customer_phone,
            'trxId' => $payment->payment_code,
            // C = closed amount: the customer must pay exactly this amount
            'virtualAccountTrxType' => 'C',
            'totalAmount' => self::money($amount),
            'additionalInfo' => [
                'channel' => $channel,
                'virtualAccountConfig' => ['reusableStatus' => false],
            ],
        ], 'H2H');
    }

    protected function createEwalletCharge(Payment $payment, string $channel, float $amount): Response
    {
        return $this->client->post(self::EWALLET_PATH, [
            'partnerReferenceNo' => $payment->payment_code,
            'amount' => self::money($amount),
            'additionalInfo' => [
                'channel' => $channel,
            ],
        ], 'DH');
    }

    protected function createQrCode(Payment $payment, float $amount): Response
    {
        return $this->client->post(self::QRIS_PATH, [
            'partnerReferenceNo' => $payment->payment_code,
            'amount' => self::money($amount),
            'merchantId' => config('payment-module.doku_client_id'),
            // Mandatory on qr-mpm-generate, and constant per merchant rather than per payment
            'terminalId' => (string) config('payment-module.doku_qris_terminal_id'),
            'additionalInfo' => [
                'channel' => 'QRIS',
                'postalCode' => (string) config('payment-module.doku_qris_postal_code'),
            ],
        ], 'QRIS');
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
