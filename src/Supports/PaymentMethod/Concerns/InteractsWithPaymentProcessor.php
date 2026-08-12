<?php

namespace CodeWithDiki\PaymentModule\Supports\PaymentMethod\Concerns;

use CodeWithDiki\PaymentModule\Data\PaymentInstruction;
use CodeWithDiki\PaymentModule\Models\Payment;
use Illuminate\Support\Collection;

trait InteractsWithPaymentProcessor
{
    public function processPayment(Payment $payment): void
    {
        // Implementasi logika untuk memproses pembayaran menggunakan payment processor yang sesuai
    }

    public function getChannels(): Collection
    {
        return collect([
            'offline' => 'Offline',
        ]);
    }

    /**
     * No gateway response to translate. Processors that charge one override this.
     */
    public function getPaymentInstruction(Payment $payment): ?PaymentInstruction
    {
        return null;
    }

    /**
     * Redirect URL from config, with {payment_code} substituted. Null when unconfigured,
     * so callers can drop the key rather than send an empty string to the gateway.
     */
    protected function resolveUrl(?string $url, string $payment_code): ?string
    {
        if (empty($url)) {
            return null;
        }

        return str_replace('{payment_code}', $payment_code, $url);
    }
}
