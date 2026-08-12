<?php

namespace CodeWithDiki\PaymentModule\Supports\PaymentMethod;

use CodeWithDiki\PaymentModule\Data\PaymentInstruction;
use CodeWithDiki\PaymentModule\Enums\PaymentInstructionType;
use CodeWithDiki\PaymentModule\Events\PaymentGatewayProcessed;
use CodeWithDiki\PaymentModule\Models\Payment;
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
            'gopay' => 'GoPay',
            'shopee_pay' => 'ShopeePay',
            'qris' => 'QRIS',
            'permata' => 'Permata',
            'bca' => 'BCA',
            'bni' => 'BNI',
            'bri' => 'BRI',
            'bsi' => 'BSI',
            'mandiri' => 'Mandiri',
        ]);
    }

    public function processPayment(Payment $payment): void
    {
        $transaction_details = [
            'payment_type' => $payment->paymentMethod->channel,
            'transaction_details' => [
                'order_id' => $payment->payment_code,
                'gross_amount' => $payment->billableAmount(),
            ],
        ];

        if (! in_array($payment->paymentMethod->channel, ['gopay', 'qris', 'shopee_pay'])) {
            $transaction_details['payment_type'] = 'bank_transfer';
            $transaction_details['bank_transfer'] = [
                'bank' => $payment->paymentMethod->channel,
            ];
        }

        if ($payment->paymentMethod->channel == 'qris') {
            $transaction_details['qris'] = [
                'acquirer' => 'gopay',
            ];
        }

        $response = CoreApi::charge($transaction_details);

        $payment->update([
            'payment_response' => $response,
        ]);

        PaymentGatewayProcessed::dispatch($payment);

    }

    /**
     * Midtrans answers 201 for a charge the customer can still pay; anything else (402, 406,
     * ...) leaves no VA number or QR to show, so there is no instruction to hand back.
     */
    public function getPaymentInstruction(Payment $payment): ?PaymentInstruction
    {
        $response = $payment->payment_response ?? [];

        if (($response['status_code'] ?? null) != 201) {
            return null;
        }

        $channel = $payment->paymentMethod->channel;
        $actions = collect($response['actions'] ?? []);

        return new PaymentInstruction(
            type: $this->instructionType($channel),
            vendor: $payment->paymentMethod->vendor->value,
            channel: $channel,
            amount: $payment->billableAmount(),
            qr_url: $actions->firstWhere('name', 'generate-qr-code')['url'] ?? null,
            redirect_url: $actions->firstWhere('name', 'deeplink-redirect')['url'] ?? null,
            virtual_account_number: $channel === 'permata'
                ? ($response['permata_va_number'] ?? null)
                : (collect($response['va_numbers'] ?? [])->firstWhere('bank', $channel)['va_number'] ?? null),
        );
    }

    protected function instructionType(string $channel): PaymentInstructionType
    {
        return match ($channel) {
            'qris' => PaymentInstructionType::Qr,
            'gopay', 'shopee_pay' => PaymentInstructionType::EWallet,
            default => PaymentInstructionType::VirtualAccount,
        };
    }
}
