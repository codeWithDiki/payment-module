<?php

namespace CodeWithDiki\PaymentModule\Webhooks\SignatureValidators;

use CodeWithDiki\PaymentModule\Supports\Doku\SnapClient;
use Illuminate\Http\Request;
use Spatie\WebhookClient\SignatureValidator\SignatureValidator;
use Spatie\WebhookClient\WebhookConfig;

/**
 * DOKU signs its notifications two different ways depending on which product raised them,
 * and a merchant can have both switched on at once:
 *
 *  - SNAP (Direct API): HMAC-SHA512 in X-SIGNATURE
 *  - Checkout / Jokul:  HMAC-SHA256 in Signature, prefixed "HMACSHA256="
 *
 * The header that arrived decides which one we verify. Both are keyed by the client secret,
 * so neither branch is weaker than the other.
 *
 * @see https://developers.doku.com/get-started-with-doku-api/notification
 */
class DokuSignatureValidator implements SignatureValidator
{
    public function isValid(Request $request, WebhookConfig $config): bool
    {
        $clientSecret = (string) config('payment-module.doku_client_secret');

        // Refuse to validate when the secret is unconfigured, otherwise the signature
        // would be derived from an empty key and could be forged by anyone
        if ($clientSecret === '') {
            return false;
        }

        return $request->hasHeader('Signature')
            ? $this->isValidCheckoutSignature($request, $clientSecret)
            : $this->isValidSnapSignature($request, $clientSecret);
    }

    /**
     * Checkout notification. The signed component is a newline joined list of the headers
     * DOKU sent, the target path, and a digest of the body — the raw body itself is never
     * part of it.
     *
     * @see https://developers.doku.com/accept-payments/notification
     */
    protected function isValidCheckoutSignature(Request $request, string $clientSecret): bool
    {
        $clientId = (string) config('payment-module.doku_client_id');
        $requestId = (string) $request->header('Request-Id');
        $timestamp = (string) $request->header('Request-Timestamp');

        if ($clientId === '' || $requestId === '' || $timestamp === '') {
            return false;
        }

        // A notification addressed to another merchant is not ours to act on, and signing
        // it with our own client id would let it pass on a secret it was never signed with.
        if (! hash_equals($clientId, (string) $request->header('Client-Id'))) {
            return false;
        }

        $component = [
            'Client-Id:'.$clientId,
            'Request-Id:'.$requestId,
            'Request-Timestamp:'.$timestamp,
            'Request-Target:'.$request->getRequestUri(),
        ];

        // DOKU omits the Digest line entirely when there is no body
        if (($body = $request->getContent()) !== '') {
            $component[] = 'Digest:'.base64_encode(hash('sha256', $body, true));
        }

        $expected = 'HMACSHA256='.base64_encode(
            hash_hmac('sha256', implode("\n", $component), $clientSecret, true)
        );

        return hash_equals($expected, (string) $request->header('Signature'));
    }

    /**
     * SNAP notification: HMAC-SHA512 over
     * METHOD:notificationPath:accessToken:hex(sha256(body)):timestamp.
     */
    protected function isValidSnapSignature(Request $request, string $clientSecret): bool
    {
        $signature = (string) $request->header('X-SIGNATURE');
        $timestamp = (string) $request->header('X-TIMESTAMP');

        if ($signature === '' || $timestamp === '') {
            return false;
        }

        // DOKU's docs put the access token in the string to sign but never say which token
        // they use when signing a notification to us. Both candidates below still require
        // the client secret, so accepting either cannot weaken the check — it only avoids
        // rejecting every callback if the assumption is wrong. Confirm against the DOKU
        // sandbox during integration and drop the one that does not apply.
        $candidateTokens = array_unique([SnapClient::cachedAccessToken(), '']);

        foreach ($candidateTokens as $token) {
            $expected = SnapClient::symmetricSignature(
                SnapClient::stringToSign(
                    $request->getMethod(),
                    $request->getPathInfo(),
                    $token,
                    $request->getContent(),
                    $timestamp,
                ),
                $clientSecret,
            );

            if (hash_equals($expected, $signature)) {
                return true;
            }
        }

        return false;
    }
}
