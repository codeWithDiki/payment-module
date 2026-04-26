<?php

namespace CodeWithDiki\PaymentModule\Controllers;

use CodeWithDiki\PaymentModule\Enums\PaymentStatus;
use CodeWithDiki\PaymentModule\Models\Payment;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

class WebhookController extends Controller
{
    public function midtrans(Request $request)
    {
        $payload = $request->all();

        $signatureValid = $this->handleMidtransSignature($payload);

        if (!$signatureValid) {
            return response()->json([
                "status" => "error",
                "message" => "Invalid signature"
            ], 400);
        }

        $order_id = $payload["order_id"];
        $transaction_status = match($payload["transaction_status"]) {
            "capture" => PaymentStatus::PAID,
            "settlement" => PaymentStatus::PAID,
            "pending" => PaymentStatus::PENDING,
            "deny" => PaymentStatus::FAILED,
            "expire" => PaymentStatus::FAILED,
            "cancel" => PaymentStatus::FAILED,
            default => null
        };

        $transaction = Payment::query()->where("payment_code", $order_id)->first();

        if (!$transaction) {
            return response()->json([
                "status" => "error",
                "message" => "Transaction not found"
            ], 404);
        }

        (new \CodeWithDiki\PaymentModule\PaymentModule())->setPaymentStatus($transaction, $transaction_status);

        return response()->json([
            "status" => "success"
        ]);
    }

    private function handleMidtransSignature($payload)
    {
        $serverKey = config('payment-module.midtrans_server_key');
        $orderId = $payload["order_id"];
        $grossAmount = $payload["gross_amount"];
        $status = $payload["status_code"];
        $signatureKey = hash("sha512", $orderId . $status . $grossAmount . $serverKey);

        return hash_equals($signatureKey, $payload["signature_key"]);
    }
}