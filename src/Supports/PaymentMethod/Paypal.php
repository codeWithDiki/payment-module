<?php

namespace CodeWithDiki\PaymentModule\Supports\PaymentMethod;

use CodeWithDiki\PaymentModule\Data\PaymentInstruction;
use CodeWithDiki\PaymentModule\Enums\PaymentInstructionType;
use CodeWithDiki\PaymentModule\Events\PaymentGatewayProcessed;
use CodeWithDiki\PaymentModule\Models\Payment;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;

class Paypal implements Contracts\PaymentProcessor
{
    use Concerns\InteractsWithPaymentProcessor;

    protected const SANDBOX_BASE_URL = 'https://api-m.sandbox.paypal.com';

    protected const PRODUCTION_BASE_URL = 'https://api-m.paypal.com';

    public function getChannels(): Collection
    {
        return collect([
            'paypal' => 'PayPal',
            'card' => 'Credit / Debit Card',
        ]);
    }

    public function processPayment(Payment $payment): void
    {
        $accessToken = $this->getAccessToken();

        /** @var array{intent: string, purchase_units: array, payment_source: array} $payload */
        $payload = [
            'intent' => 'CAPTURE',
            'purchase_units' => [
                [
                    'reference_id' => $payment->payment_code,
                    'amount' => [
                        'currency_code' => config('payment-module.paypal_currency', 'USD'),
                        'value' => number_format($payment->billableAmount(), 2, '.', ''),
                    ],
                ],
            ],
            'payment_source' => [
                'paypal' => [
                    'experience_context' => array_filter([
                        'return_url' => $this->resolveUrl(config('payment-module.paypal_return_url'), $payment->payment_code),
                        'cancel_url' => $this->resolveUrl(config('payment-module.paypal_cancel_url'), $payment->payment_code),
                    ]),
                ],
            ],
        ];

        $response = $this->client($accessToken)->post('/v2/checkout/orders', $payload);
        $body = $response->json();

        $payment->update([
            'payment_payload' => $payload,
            'payment_response' => $body ?? [],
        ]);

        PaymentGatewayProcessed::dispatch($payment);
    }

    public function getPaymentInstruction(Payment $payment): ?PaymentInstruction
    {
        /** @var array|null $response */
        $response = $payment->payment_response ?? [];
        $redirectUrl = null;

        if (isset($response['links'])) {
            foreach ($response['links'] as $link) {
                if (($link['rel'] ?? '') === 'payer-action') {
                    $redirectUrl = $link['href'] ?? null;
                    break;
                }
            }
        }

        return new PaymentInstruction(
            type: PaymentInstructionType::EWallet,
            vendor: $payment->paymentMethod->vendor->value,
            channel: $payment->paymentMethod->channel,
            amount: $payment->billableAmount(),
            redirect_url: $redirectUrl,
        );
    }

    protected function getAccessToken(): string
    {
        $baseUrl = $this->baseUrl();

        $response = Http::baseUrl($baseUrl)
            ->withBasicAuth(
                config('payment-module.paypal_client_id'),
                config('payment-module.paypal_client_secret')
            )
            ->asForm()
            ->post('/v1/oauth2/token', [
                'grant_type' => 'client_credentials',
            ]);

        /** @var string|null $token */
        $token = $response->json('access_token');

        return $token ?? '';
    }

    protected function client(string $accessToken): PendingRequest
    {
        return Http::baseUrl($this->baseUrl())
            ->withToken($accessToken)
            ->acceptJson()
            ->contentType('application/json');
    }

    protected function baseUrl(): string
    {
        return config('payment-module.paypal_is_production', false)
            ? self::PRODUCTION_BASE_URL
            : self::SANDBOX_BASE_URL;
    }
}
