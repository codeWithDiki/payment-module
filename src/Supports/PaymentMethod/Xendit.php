<?php

namespace CodeWithDiki\PaymentModule\Supports\PaymentMethod;

use CodeWithDiki\PaymentModule\Data\PaymentInstruction;
use CodeWithDiki\PaymentModule\Enums\PaymentInstructionType;
use CodeWithDiki\PaymentModule\Enums\PaymentStatus;
use CodeWithDiki\PaymentModule\Events\PaymentGatewayProcessed;
use CodeWithDiki\PaymentModule\Models\Payment;
use CodeWithDiki\PaymentModule\PaymentModule;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;

class Xendit implements Contracts\PaymentProcessor
{
    use Concerns\InteractsWithPaymentProcessor;

    protected const BASE_URL = 'https://api.xendit.co';

    /** Channel codes handled through the Virtual Account API */
    protected const VIRTUAL_ACCOUNT_BANKS = [
        'BCA', 'BNI', 'BRI', 'MANDIRI', 'PERMATA', 'BSI', 'CIMB', 'SAHABAT_SAMPOERNA', 'BJB',
    ];

    /** Channel codes handled through the eWallet Charges API */
    protected const EWALLET_CHANNELS = [
        'ID_OVO', 'ID_DANA', 'ID_LINKAJA', 'ID_SHOPEEPAY', 'ID_ASTRAPAY', 'ID_JENIUSPAY',
    ];

    public function getChannels(): Collection
    {
        return collect([
            'BCA' => 'BCA Virtual Account',
            'BNI' => 'BNI Virtual Account',
            'BRI' => 'BRI Virtual Account',
            'MANDIRI' => 'Mandiri Virtual Account',
            'PERMATA' => 'Permata Virtual Account',
            'BSI' => 'BSI Virtual Account',
            'ID_OVO' => 'OVO',
            'ID_DANA' => 'DANA',
            'ID_LINKAJA' => 'LinkAja',
            'ID_SHOPEEPAY' => 'ShopeePay',
            'QRIS' => 'QRIS',
        ]);
    }

    public function processPayment(Payment $payment): void
    {
        $channel = $payment->paymentMethod->channel;
        // Bill the customer the total (amount + payment-method fee)
        $amount = $payment->billableAmount();

        $response = match ($this->instructionType($channel)) {
            PaymentInstructionType::Qr => $this->createQrCode($payment, $amount),
            PaymentInstructionType::EWallet => $this->createEwalletCharge($payment, $channel, $amount),
            PaymentInstructionType::VirtualAccount => $this->createVirtualAccount($payment, $channel, $amount),
        };

        $payment->update([
            'payment_response' => $response->json(),
        ]);

        // A rejected charge leaves nothing for the customer to pay; fail the payment loudly
        // instead of leaving it pending forever with an error body as its response.
        if ($response->failed()) {
            (new PaymentModule)->setPaymentStatus($payment, PaymentStatus::FAILED);

            return;
        }

        PaymentGatewayProcessed::dispatch($payment);
    }

    public function getPaymentInstruction(Payment $payment): ?PaymentInstruction
    {
        $response = $payment->payment_response ?? [];
        $channel = $payment->paymentMethod->channel;
        $actions = $response['actions'] ?? [];

        return new PaymentInstruction(
            type: $this->instructionType($channel),
            vendor: $payment->paymentMethod->vendor->value,
            channel: $channel,
            amount: $payment->billableAmount(),
            qr_string: $response['qr_string'] ?? null,
            // OVO pushes a prompt to the app and returns no checkout URL at all; the rest
            // hand back a web checkout, with the deeplink as the mobile fallback.
            redirect_url: $actions['desktop_web_checkout_url']
                ?? $actions['mobile_web_checkout_url']
                ?? $actions['mobile_deeplink_checkout_url']
                ?? null,
            virtual_account_number: $response['account_number'] ?? null,
        );
    }

    protected function instructionType(string $channel): PaymentInstructionType
    {
        return match (true) {
            $channel === 'QRIS' => PaymentInstructionType::Qr,
            in_array($channel, self::EWALLET_CHANNELS, true) => PaymentInstructionType::EWallet,
            default => PaymentInstructionType::VirtualAccount,
        };
    }

    protected function createVirtualAccount(Payment $payment, string $bankCode, float $amount): Response
    {
        return $this->client()
            ->post('/callback_virtual_accounts', [
                'external_id' => $payment->payment_code,
                'callback_url' => config('payment-module.xendit_callback_url') ?? route('webhook-client-payment-module-xendit'),
                'bank_code' => $bankCode,
                'name' => $payment->customer_name ?: $payment->payment_code,
                'is_closed' => true,
                'is_single_use' => true,
                'expected_amount' => $amount,
            ]);
    }

    protected function createEwalletCharge(Payment $payment, string $channelCode, float $amount): Response
    {
        return $this->client()
            ->post('/ewallets/charges', [
                'reference_id' => $payment->payment_code,
                'currency' => 'IDR',
                'amount' => $amount,
                'external_id' => $payment->payment_code,
                'callback_url' => config('payment-module.xendit_callback_url') ?? route('webhook-client-payment-module-xendit'),
                'checkout_method' => 'ONE_TIME_PAYMENT',
                'channel_code' => $channelCode,
                'channel_properties' => array_filter([
                    'success_redirect_url' => $this->resolveUrl(config('payment-module.xendit_success_redirect_url'), $payment->payment_code),
                    'failure_redirect_url' => $this->resolveUrl(config('payment-module.xendit_failure_redirect_url'), $payment->payment_code),
                ]),
            ]);
    }

    protected function createQrCode(Payment $payment, float $amount): Response
    {
        return $this->client()
            ->post('/qr_codes', [
                'external_id' => $payment->payment_code,
                'callback_url' => config('payment-module.xendit_callback_url') ?? route('webhook-client-payment-module-xendit'),
                'reference_id' => $payment->payment_code,
                'type' => 'DYNAMIC',
                'currency' => 'IDR',
                'amount' => $amount,
            ]);
    }

    protected function client(): PendingRequest
    {
        return Http::baseUrl(self::BASE_URL)
            ->withBasicAuth(config('payment-module.xendit_secret_key'), '')
            ->acceptJson()
            ->asJson();
    }
}
