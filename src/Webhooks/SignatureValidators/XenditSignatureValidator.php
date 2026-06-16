<?php

namespace CodeWithDiki\PaymentModule\Webhooks\SignatureValidators;

use Illuminate\Http\Request;
use Spatie\WebhookClient\SignatureValidator\SignatureValidator;
use Spatie\WebhookClient\WebhookConfig;

class XenditSignatureValidator implements SignatureValidator
{
    public function isValid(Request $request, WebhookConfig $config): bool
    {
        // Xendit authenticates callbacks with a static verification token sent in the
        // x-callback-token header (https://docs.xendit.co/webhooks)
        $token = config('payment-module.xendit_webhook_token');

        // Refuse to validate when the token is unconfigured, otherwise any caller would pass
        if (empty($token)) {
            return false;
        }

        return hash_equals($token, $request->header('x-callback-token') ?? '');
    }
}
