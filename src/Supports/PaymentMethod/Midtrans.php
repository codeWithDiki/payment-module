<?php

namespace CodeWithDiki\PaymentModule\Supports\PaymentMethod;

use Illuminate\Support\Collection;
use Midtrans\Config;
use Midtrans\CoreApi;

class Midtrans implements Contracts\PaymentProcessor
{
    use Concerns\InteractsWithPaymentProcessor;

    public function __construct()
    {
        Config::$serverKey = config('payment-module.midtrans_server_key');
        Config::$isProduction = config('payment-module.midtrans_is_production');
        Config::$isSanitized = config('payment-module.midtrans_is_sanitized');
        Config::$is3ds = config('payment-module.midtrans_is_3ds');
        Config::$clientKey = config('payment-module.midtrans_client_key');
    }

    public function getChannels(): Collection
    {
        return collect([
            "gopay" => "GoPay",
            "shopee_pay" => "ShopeePay",
            "qris" => "QRIS",
            "permata" => "Permata",
            "bca" => "BCA",
            "bni" => "BNI",
            "bri" => "BRI",
            "bsi" => "BSI",
            "mandiri" => "Mandiri",
        ]);
    }

    public function processPayment(\CodeWithDiki\PaymentModule\Models\Payment $payment): void
    {
        $transaction_details = [
            "payment_type" => $payment->paymentMethod->channel,
            "transaction_details" => [
                "order_id" => $payment->payment_code,
                "gross_amount" => $payment->amount
            ]
        ];

        if(!in_array($payment->paymentMethod->channel, ["gopay", "qris", "shopee_pay"]))
        {
            $transaction_details["payment_type"] = "bank_transfer";
            $transaction_details["bank_transfer"] = [
                "bank" => $payment->paymentMethod->channel
            ];
        }

        if($payment->paymentMethod->channel == "qris")
        {
            $transaction_details["qris"] = [
                "acquirer" => "gopay"
            ];
        }

        $response = CoreApi::charge($transaction_details);

        $payment->update([
            "payment_response" => $response
        ]);

        \CodeWithDiki\PaymentModule\Events\PaymentGatewayProcessed::dispatch($payment);

    }


}