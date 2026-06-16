<?php

namespace CodeWithDiki\PaymentModule\Supports\PaymentMethod;

use CodeWithDiki\PaymentModule\Events\PaymentGatewayProcessed;
use CodeWithDiki\PaymentModule\Models\Payment;
use Illuminate\Support\Collection;
use Stripe\StripeClient;

class Stripe implements Contracts\PaymentProcessor
{
    use Concerns\InteractsWithPaymentProcessor;

    /** Currencies that Stripe treats as zero-decimal (amount is sent as-is, not multiplied by 100) */
    protected const ZERO_DECIMAL_CURRENCIES = [
        'bif', 'clp', 'djf', 'gnf', 'jpy', 'kmf', 'krw', 'mga',
        'pyg', 'rwf', 'ugx', 'vnd', 'vuv', 'xaf', 'xof', 'xpf',
    ];

    protected StripeClient $client;

    public function __construct()
    {
        // StripeClient rejects an empty string; pass null so the processor can still be
        // instantiated (e.g. to list channels) before the key is configured
        $this->client = new StripeClient([
            'api_key' => config('payment-module.stripe_secret_key') ?: null,
        ]);
    }

    public function getChannels(): Collection
    {
        return collect([
            'card' => 'Card',
            'link' => 'Link',
            'alipay' => 'Alipay',
            'wechat_pay' => 'WeChat Pay',
        ]);
    }

    public function processPayment(Payment $payment): void
    {
        $currency = config('payment-module.stripe_currency', 'usd');

        $session_payload = [
            'mode' => 'payment',
            'client_reference_id' => $payment->payment_code,
            'payment_method_types' => [$payment->paymentMethod->channel],
            'line_items' => [
                [
                    'price_data' => [
                        'currency' => $currency,
                        'product_data' => [
                            'name' => $payment->payment_code,
                        ],
                        'unit_amount' => $this->toSmallestCurrencyUnit($payment->billableAmount(), $currency),
                    ],
                    'quantity' => 1,
                ],
            ],
            'success_url' => $this->resolveUrl(config('payment-module.stripe_success_url'), $payment->payment_code),
        ];

        if ($payment->customer_email) {
            $session_payload['customer_email'] = $payment->customer_email;
        }

        if ($cancel_url = $this->resolveUrl(config('payment-module.stripe_cancel_url'), $payment->payment_code)) {
            $session_payload['cancel_url'] = $cancel_url;
        }

        $session = $this->client->checkout->sessions->create($session_payload);

        $payment->update([
            'payment_response' => $session->toArray(),
        ]);

        PaymentGatewayProcessed::dispatch($payment);
    }

    protected function toSmallestCurrencyUnit(float $amount, string $currency): int
    {
        return self::smallestCurrencyUnit($amount, $currency);
    }

    /**
     * Convert a decimal amount into Stripe's smallest currency unit. Public and static
     * so the webhook job can compute the expected amount for verification.
     */
    public static function smallestCurrencyUnit(float $amount, ?string $currency = null): int
    {
        $currency = $currency ?: config('payment-module.stripe_currency', 'usd');

        if (in_array(strtolower($currency), self::ZERO_DECIMAL_CURRENCIES)) {
            return (int) round($amount);
        }

        return (int) round($amount * 100);
    }

    protected function resolveUrl(?string $url, string $payment_code): ?string
    {
        if (empty($url)) {
            return null;
        }

        return str_replace('{payment_code}', $payment_code, $url);
    }
}
