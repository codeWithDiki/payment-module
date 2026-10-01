<?php

namespace CodeWithDiki\PaymentModule\Supports\PaymentMethod\Concerns;

use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Shared PayPal REST plumbing: base URL and OAuth2 access token.
 *
 * Both the payment processor and the webhook signature validator use this, so the two
 * can never resolve credentials differently.
 */
trait ResolvesPaypalCredentials
{
    protected const PAYPAL_SANDBOX_BASE_URL = 'https://api-m.sandbox.paypal.com';

    protected const PAYPAL_PRODUCTION_BASE_URL = 'https://api-m.paypal.com';

    protected function paypalBaseUrl(): string
    {
        return config('payment-module.paypal_is_production', false)
            ? self::PAYPAL_PRODUCTION_BASE_URL
            : self::PAYPAL_SANDBOX_BASE_URL;
    }

    /**
     * OAuth2 client_credentials token.
     *
     * Throws instead of returning an empty string: the previous version returned '', the
     * request then went out with no Authorization header, and PayPal answered
     * AUTHENTICATION_FAILURE on the *orders* call — pointing at the wrong thing entirely.
     */
    protected function paypalAccessToken(): string
    {
        $clientId = (string) config('payment-module.paypal_client_id');
        $clientSecret = (string) config('payment-module.paypal_client_secret');

        if ($clientId === '' || $clientSecret === '') {
            throw new RuntimeException(
                'PayPal client id/secret are not configured. Set PAYPAL_CLIENT_ID and PAYPAL_CLIENT_SECRET, '
                .'make sure config/payment-module.php has the paypal_client_id and paypal_client_secret keys '
                .'(republish it if it predates them), then run `php artisan config:clear`.'
            );
        }

        $response = Http::baseUrl($this->paypalBaseUrl())
            ->withBasicAuth($clientId, $clientSecret)
            ->asForm()
            ->post('/v1/oauth2/token', [
                'grant_type' => 'client_credentials',
            ])
            ->throw();

        /** @var string|null $token */
        $token = $response->json('access_token');

        if (! $token) {
            throw new RuntimeException('PayPal accepted the credentials but returned no access token.');
        }

        return $token;
    }
}
