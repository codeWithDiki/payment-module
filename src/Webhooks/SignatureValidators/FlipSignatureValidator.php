<?php

namespace CodeWithDiki\PaymentModule\Webhooks\SignatureValidators;

use Illuminate\Http\Request;
use Spatie\WebhookClient\SignatureValidator\SignatureValidator;
use Spatie\WebhookClient\WebhookConfig;

/**
 * Flip authenticates its callbacks by including a `token_validation` field in the
 * request body. The value must match the validation token registered in the Flip
 * Business dashboard.
 *
 * @see https://docs.flip.id/accept-payments/payment-callback
 * @see https://docs.flip.id/money-transfer/disbursement-callback
 */
class FlipSignatureValidator implements SignatureValidator
{
    public function isValid(Request $request, WebhookConfig $config): bool
    {
        $validationToken = (string) config('payment-module.flip_validation_token');

        // Refuse to validate when the token is unconfigured — an empty token would let
        // any caller pass because the body field would also be empty.
        if ($validationToken === '') {
            return false;
        }

        $receivedToken = (string) ($request->input('token_validation') ?? '');

        return hash_equals($validationToken, $receivedToken);
    }
}
