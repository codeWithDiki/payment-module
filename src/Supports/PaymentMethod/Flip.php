<?php

namespace CodeWithDiki\PaymentModule\Supports\PaymentMethod;

use CodeWithDiki\PaymentModule\Data\PaymentInstruction;
use CodeWithDiki\PaymentModule\Enums\PaymentInstructionType;
use CodeWithDiki\PaymentModule\Enums\PaymentStatus;
use CodeWithDiki\PaymentModule\Events\PaymentGatewayProcessed;
use CodeWithDiki\PaymentModule\Models\Payment;
use CodeWithDiki\PaymentModule\PaymentModule;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;

/**
 * Flip for Business — Accept Payment API.
 *
 * Flip uses a single "bill" endpoint that supports Virtual Account (bank transfer),
 * e-wallet, QRIS, and retail outlet channels. The channel type is inferred from the
 * payment method's channel code stored on the PaymentMethod model.
 *
 * Authentication: HTTP Basic Auth — secret key as username, empty password.
 *
 * @see https://docs.flip.id/accept-payments/general-information
 */
class Flip implements Contracts\PaymentProcessor
{
    use Concerns\InteractsWithPaymentProcessor;

    protected const SANDBOX_BASE_URL = 'https://bigflip.id/big_sandbox_api/v2';

    protected const PRODUCTION_BASE_URL = 'https://bigflip.id/api/v3';

    /**
     * Bank codes supported by Flip's Virtual Account channel.
     * Channel code stored on the payment method should match these values.
     */
    protected const VIRTUAL_ACCOUNT_BANKS = [
        'BCA', 'BNI', 'BRI', 'MANDIRI', 'PERMATA', 'CIMB', 'DANAMON',
        'BSI', 'BTN', 'MUAMALAT', 'BJB',
    ];

    /**
     * E-wallet channel codes supported by Flip's Accept Payment.
     */
    protected const EWALLET_CHANNELS = [
        'OVO', 'DANA', 'SHOPEEPAY', 'LINKAJA', 'GOPAY',
    ];

    public function getChannels(): Collection
    {
        return collect([
            'BCA' => 'BCA Virtual Account',
            'BNI' => 'BNI Virtual Account',
            'BRI' => 'BRI Virtual Account',
            'MANDIRI' => 'Mandiri Virtual Account',
            'PERMATA' => 'Permata Virtual Account',
            'CIMB' => 'CIMB Niaga Virtual Account',
            'DANAMON' => 'Danamon Virtual Account',
            'BSI' => 'BSI Virtual Account',
            'BTN' => 'BTN Virtual Account',
            'MUAMALAT' => 'Bank Muamalat Virtual Account',
            'BJB' => 'BJB Virtual Account',
            'OVO' => 'OVO',
            'DANA' => 'DANA',
            'SHOPEEPAY' => 'ShopeePay',
            'LINKAJA' => 'LinkAja',
            'GOPAY' => 'GoPay',
            'QRIS' => 'QRIS',
            'ALFAMART' => 'Alfamart',
            'INDOMARET' => 'Indomaret',
        ]);
    }

    public function processPayment(Payment $payment): void
    {
        $amount = $payment->billableAmount();

        $payload = array_filter([
            'title' => $payment->payment_code,
            'type' => 'SINGLE',
            'amount' => (int) $amount,
            'redirect_url' => $this->resolveUrl(config('payment-module.flip_redirect_url'), $payment->payment_code),
            'sender_name' => $payment->customer_name ?: $payment->payment_code,
            'sender_email' => $payment->customer_email,
            'sender_phone_number' => $payment->customer_phone,
            'sender_address' => $payment->customer_address,
        ]);

        $response = $this->client()->post('/pwf/bill', $payload);

        $body = $response->json();

        $payment->update([
            'payment_payload' => $payload,
            'payment_response' => $body,
        ]);

        if ($response->failed() || empty($body['link_id'])) {
            (new PaymentModule)->setPaymentStatus($payment, PaymentStatus::FAILED);

            return;
        }

        PaymentGatewayProcessed::dispatch($payment);
    }

    public function getPaymentInstruction(Payment $payment): ?PaymentInstruction
    {
        $response = $payment->payment_response ?? [];
        $channel = $payment->paymentMethod->channel;

        // Flip returns a hosted payment link that covers all configured channels
        $redirectUrl = $response['link_url'] ?? null;

        return new PaymentInstruction(
            type: $this->instructionType($channel),
            vendor: $payment->paymentMethod->vendor->value,
            channel: $channel,
            amount: $payment->billableAmount(),
            redirect_url: $redirectUrl,
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

    protected function client(): PendingRequest
    {
        $baseUrl = config('payment-module.flip_is_production', false)
            ? self::PRODUCTION_BASE_URL
            : self::SANDBOX_BASE_URL;

        return Http::baseUrl($baseUrl)
            ->withBasicAuth(config('payment-module.flip_secret_key'), '')
            ->acceptJson()
            ->asForm();
    }
}
