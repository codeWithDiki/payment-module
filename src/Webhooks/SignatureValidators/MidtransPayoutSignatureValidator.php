<?php

namespace CodeWithDiki\PaymentModule\Webhooks\SignatureValidators;

use Illuminate\Http\Request;
use Spatie\WebhookClient\SignatureValidator\SignatureValidator;
use Spatie\WebhookClient\WebhookConfig;

class MidtransPayoutSignatureValidator implements SignatureValidator
{
    public function isValid(Request $request, WebhookConfig $config): bool
    {
        $merchantKey = config('payment-module.midtrans_iris_merchant_key');

        $signature = hash('sha512', $request->getContent().$merchantKey);

        return hash_equals($signature, $request->header('Iris-Signature') ?? '');
    }
}
