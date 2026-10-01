<?php

namespace CodeWithDiki\PaymentModule\Supports\PaymentMethod;

use CodeWithDiki\PaymentModule\Data\PaymentInstruction;
use CodeWithDiki\PaymentModule\Enums\PaymentInstructionType;
use CodeWithDiki\PaymentModule\Events\PaymentGatewayProcessed;
use CodeWithDiki\PaymentModule\Models\Payment;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class Paypal implements Contracts\PaymentProcessor
{
    use Concerns\InteractsWithPaymentProcessor;
    use Concerns\ResolvesPaypalCredentials;

    public function getChannels(): Collection
    {
        return collect([
            'paypal' => 'PayPal',
            'card' => 'Credit / Debit Card',
        ]);
    }

    public function processPayment(Payment $payment): void
    {
        $accessToken = $this->paypalAccessToken();

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

        $response = $this->client($accessToken)->post('/v2/checkout/orders', $payload)->throw();
        $body = $response->json();

        if (! $body) {
            throw new RuntimeException('PayPal returned an empty response body when creating the order.');
        }

        $payment->update([
            'payment_payload' => $payload,
            'payment_response' => $body,
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

    protected function client(string $accessToken): PendingRequest
    {
        return Http::baseUrl($this->paypalBaseUrl())
            ->withToken($accessToken)
            ->acceptJson()
            ->contentType('application/json');
    }
}
