<?php

namespace CodeWithDiki\PaymentModule\Webhooks\SignatureValidators;

use CodeWithDiki\PaymentModule\Supports\Doku\SnapClient;
use Illuminate\Http\Request;
use Spatie\WebhookClient\SignatureValidator\SignatureValidator;
use Spatie\WebhookClient\WebhookConfig;

/**
 * Verifies DOKU SNAP notifications: HMAC-SHA512 over
 * METHOD:notificationPath:accessToken:hex(sha256(body)):timestamp, keyed by the client secret.
 *
 * @see https://developers.doku.com/get-started-with-doku-api/notification
 */
class DokuSnapSignatureValidator implements SignatureValidator
{
    public function isValid(Request $request, WebhookConfig $config): bool
    {
        $clientSecret = config('payment-module.doku_client_secret');

        // Refuse to validate when the secret is unconfigured, otherwise the signature
        // would be derived from an empty key and could be forged by anyone
        if (empty($clientSecret)) {
            return false;
        }

        $signature = $request->header('X-SIGNATURE');
        $timestamp = $request->header('X-TIMESTAMP');

        if (empty($signature) || empty($timestamp)) {
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
