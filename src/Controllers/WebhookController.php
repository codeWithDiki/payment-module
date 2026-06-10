<?php

namespace CodeWithDiki\PaymentModule\Controllers;

use CodeWithDiki\PaymentModule\Enums\PaymentStatus;
use CodeWithDiki\PaymentModule\Facades\PaymentModule;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Stripe\Exception\SignatureVerificationException;
use Stripe\Webhook;

class WebhookController extends Controller
{
    public function midtrans(Request $request)
    {
        $payload = $request->all();

        $signatureValid = $this->handleMidtransSignature($payload);

        if (! $signatureValid) {
            return response()->json([
                'status' => 'error',
                'message' => 'Invalid signature',
            ], 400);
        }

        $order_id = $payload['order_id'];
        $transaction_status = match ($payload['transaction_status']) {
            'capture' => PaymentStatus::PAID,
            'settlement' => PaymentStatus::PAID,
            'pending' => PaymentStatus::PENDING,
            'deny' => PaymentStatus::FAILED,
            'expire' => PaymentStatus::FAILED,
            'cancel' => PaymentStatus::FAILED,
            default => null
        };

        $transaction = config('payment-module.payment_class')::query()->where('payment_code', $order_id)->first();

        if (! $transaction) {
            return response()->json([
                'status' => 'error',
                'message' => 'Transaction not found',
            ], 404);
        }

        PaymentModule::setPaymentStatus($transaction, $transaction_status);

        return response()->json([
            'status' => 'success',
        ]);
    }

    public function stripe(Request $request)
    {
        try {
            $event = Webhook::constructEvent(
                $request->getContent(),
                $request->header('Stripe-Signature') ?? '',
                config('payment-module.stripe_webhook_secret')
            );
        } catch (SignatureVerificationException|\UnexpectedValueException $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Invalid signature',
            ], 400);
        }

        $session = $event->data->object;

        $transaction_status = match ($event->type) {
            'checkout.session.completed' => ($session->payment_status ?? null) === 'paid' ? PaymentStatus::PAID : null,
            'checkout.session.async_payment_succeeded' => PaymentStatus::PAID,
            'checkout.session.expired',
            'checkout.session.async_payment_failed' => PaymentStatus::FAILED,
            default => null
        };

        if (! $transaction_status) {
            return response()->json([
                'status' => 'ignored',
            ]);
        }

        $transaction = PaymentModule::getPaymentByCode($session->client_reference_id ?? '');

        if (! $transaction) {
            return response()->json([
                'status' => 'error',
                'message' => 'Transaction not found',
            ], 404);
        }

        PaymentModule::setPaymentStatus($transaction, $transaction_status);

        return response()->json([
            'status' => 'success',
        ]);
    }

    private function handleMidtransSignature($payload)
    {
        $serverKey = config('payment-module.midtrans_server_key');
        $orderId = $payload['order_id'];
        $grossAmount = $payload['gross_amount'];
        $status = $payload['status_code'];
        $signatureKey = hash('sha512', $orderId.$status.$grossAmount.$serverKey);

        return hash_equals($signatureKey, $payload['signature_key']);
    }
}
