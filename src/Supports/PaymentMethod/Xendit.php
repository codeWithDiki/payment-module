<?php

namespace CodeWithDiki\PaymentModule\Supports\PaymentMethod;

use CodeWithDiki\PaymentModule\Events\PaymentGatewayProcessed;
use CodeWithDiki\PaymentModule\Models\Payment;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;

class Xendit implements Contracts\PaymentProcessor
{
    use Concerns\InteractsWithPaymentProcessor;

    protected const BASE_URL = 'https://api.xendit.co';

    /** Channel codes handled through the Virtual Account API */
    protected const VIRTUAL_ACCOUNT_BANKS = [
        'BCA', 'BNI', 'BRI', 'MANDIRI', 'PERMATA', 'BSI', 'CIMB', 'SAHABAT_SAMPOERNA', 'BJB',
    ];

    /** Channel codes handled through the eWallet Charges API */
    protected const EWALLET_CHANNELS = [
        'ID_OVO', 'ID_DANA', 'ID_LINKAJA', 'ID_SHOPEEPAY', 'ID_ASTRAPAY', 'ID_JENIUSPAY',
    ];

    public function getChannels(): Collection
    {
        return collect([
            'BCA' => 'BCA Virtual Account',
            'BNI' => 'BNI Virtual Account',
            'BRI' => 'BRI Virtual Account',
            'MANDIRI' => 'Mandiri Virtual Account',
            'PERMATA' => 'Permata Virtual Account',
            'BSI' => 'BSI Virtual Account',
            'ID_OVO' => 'OVO',
            'ID_DANA' => 'DANA',
            'ID_LINKAJA' => 'LinkAja',
            'ID_SHOPEEPAY' => 'ShopeePay',
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
            'payment_response' => $response,
        ]);

        PaymentGatewayProcessed::dispatch($payment);
    }

    protected function createVirtualAccount(Payment $payment, string $bankCode, float $amount): array
    {
        return $this->client()
            ->post('/callback_virtual_accounts', [
                'external_id' => $payment->payment_code,
                'bank_code' => $bankCode,
                'name' => $payment->customer_name ?: $payment->payment_code,
                'is_closed' => true,
                'is_single_use' => true,
                'expected_amount' => $amount,
            ])
            ->json();
    }

    protected function createEwalletCharge(Payment $payment, string $channelCode, float $amount): array
    {
        return $this->client()
            ->post('/ewallets/charges', [
                'reference_id' => $payment->payment_code,
                'currency' => 'IDR',
                'amount' => $amount,
                'checkout_method' => 'ONE_TIME_PAYMENT',
                'channel_code' => $channelCode,
                'channel_properties' => array_filter([
                    'success_redirect_url' => $this->resolveUrl(config('payment-module.xendit_success_redirect_url'), $payment->payment_code),
                    'failure_redirect_url' => $this->resolveUrl(config('payment-module.xendit_failure_redirect_url'), $payment->payment_code),
                ]),
            ])
            ->json();
    }

    protected function createQrCode(Payment $payment, float $amount): array
    {
        return $this->client()
            ->post('/qr_codes', [
                'reference_id' => $payment->payment_code,
                'type' => 'DYNAMIC',
                'currency' => 'IDR',
                'amount' => $amount,
            ])
            ->json();
    }

    protected function client(): PendingRequest
    {
        return Http::baseUrl(self::BASE_URL)
            ->withBasicAuth(config('payment-module.xendit_secret_key'), '')
            ->acceptJson()
            ->asJson();
    }

    protected function resolveUrl(?string $url, string $payment_code): ?string
    {
        if (empty($url)) {
            return null;
        }

        return str_replace('{payment_code}', $payment_code, $url);
    }
}
