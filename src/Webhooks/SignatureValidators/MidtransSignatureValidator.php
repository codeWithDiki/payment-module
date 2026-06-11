<?php

namespace CodeWithDiki\PaymentModule\Webhooks\SignatureValidators;

use Illuminate\Http\Request;
use Spatie\WebhookClient\SignatureValidator\SignatureValidator;
use Spatie\WebhookClient\WebhookConfig;

class MidtransSignatureValidator implements SignatureValidator
{
    public function isValid(Request $request, WebhookConfig $config): bool
    {
        // Midtrans sends the signature inside the JSON body, not in a header
        $serverKey = config('payment-module.midtrans_server_key');

        $signature = hash('sha512', $request->input('order_id')
            .$request->input('status_code')
            .$request->input('gross_amount')
            .$serverKey);

        return hash_equals($signature, (string) $request->input('signature_key'));
    }
}
