<?php

namespace CodeWithDiki\PaymentModule\Webhooks\SignatureValidators;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Spatie\WebhookClient\SignatureValidator\SignatureValidator;
use Spatie\WebhookClient\WebhookConfig;

/**
 * Verifies PayPal webhook signatures by calling PayPal's verification API.
 *
 * PayPal does not use a simple shared secret; the signature is verified server-side
 * via POST /v1/notifications/verify-webhook-signature using the webhook ID registered
 * in the PayPal dashboard.
 *
 * @see https://developer.paypal.com/docs/api/webhooks/v1/#verify-webhook-signature_post
 */
class PaypalSignatureValidator implements SignatureValidator
{
    public function isValid(Request $request, WebhookConfig $config): bool
    {
        // ponytail: $config unused — PayPal API verifies via webhook_id, not a header
        $webhookId = (string) config('payment-module.paypal_webhook_id');

        if ($webhookId === '') {
            return false;
        }

        $baseUrl = config('payment-module.paypal_is_production', false)
            ? 'https://api-m.paypal.com'
            : 'https://api-m.sandbox.paypal.com';

        // Get an access token for the verification call
        $tokenResponse = Http::baseUrl($baseUrl)
            ->withBasicAuth(
                config('payment-module.paypal_client_id'),
                config('payment-module.paypal_client_secret')
            )
            ->asForm()
            ->post('/v1/oauth2/token', [
                'grant_type' => 'client_credentials',
            ]);

        /** @var string|null $accessToken */
        $accessToken = $tokenResponse->json('access_token');

        if (! $accessToken) {
            return false;
        }

        $headers = $request->headers;

        /** @var array|null $webhookEvent */
        $webhookEvent = json_decode($request->getContent(), true);

        $verification = Http::baseUrl($baseUrl)
            ->withToken($accessToken)
            ->acceptJson()
            ->post('/v1/notifications/verify-webhook-signature', [
                'auth_algo' => (string) ($headers->get('PAYPAL-AUTH-ALGO')),
                'cert_url' => (string) ($headers->get('PAYPAL-CERT-URL')),
                'transmission_id' => (string) ($headers->get('PAYPAL-TRANSMISSION-ID')),
                'transmission_sig' => (string) ($headers->get('PAYPAL-TRANSMISSION-SIG')),
                'transmission_time' => (string) ($headers->get('PAYPAL-TRANSMISSION-TIME')),
                'webhook_id' => $webhookId,
                'webhook_event' => $webhookEvent ?? [],
            ]);

        return ($verification->json('verification_status') ?? '') === 'SUCCESS';
    }
}
