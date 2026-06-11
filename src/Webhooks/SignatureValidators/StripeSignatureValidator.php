<?php

namespace CodeWithDiki\PaymentModule\Webhooks\SignatureValidators;

use Illuminate\Http\Request;
use Spatie\WebhookClient\SignatureValidator\SignatureValidator;
use Spatie\WebhookClient\WebhookConfig;
use Stripe\Exception\SignatureVerificationException;
use Stripe\Webhook;

class StripeSignatureValidator implements SignatureValidator
{
    public function isValid(Request $request, WebhookConfig $config): bool
    {
        try {
            Webhook::constructEvent(
                $request->getContent(),
                $request->header('Stripe-Signature') ?? '',
                config('payment-module.stripe_webhook_secret')
            );
        } catch (SignatureVerificationException|\UnexpectedValueException) {
            return false;
        }

        return true;
    }
}
