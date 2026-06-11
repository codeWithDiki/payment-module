<?php

use CodeWithDiki\PaymentModule\Enums\PaymentStatus;
use CodeWithDiki\PaymentModule\Enums\PaymentVendor;
use CodeWithDiki\PaymentModule\Models\Payment;
use CodeWithDiki\PaymentModule\Models\PaymentMethod;
use Spatie\WebhookClient\Models\WebhookCall;

function createMidtransPayment(): Payment
{
    $paymentMethod = PaymentMethod::create([
        'name' => 'GoPay',
        'vendor' => PaymentVendor::Midtrans,
        'channel' => 'gopay',
        'is_active' => true,
    ]);

    return Payment::create([
        'paymentable_type' => 'App\\Models\\Order',
        'paymentable_id' => 1,
        'payment_method_id' => $paymentMethod->id,
        'payment_code' => 'INV-'.uniqid(),
        'amount' => 150000,
        'status' => PaymentStatus::PENDING,
    ]);
}

function midtransWebhookPayload(Payment $payment, string $transactionStatus, string $serverKey): array
{
    $statusCode = '200';
    $grossAmount = '150000.00';

    return [
        'order_id' => $payment->payment_code,
        'status_code' => $statusCode,
        'gross_amount' => $grossAmount,
        'transaction_status' => $transactionStatus,
        'signature_key' => hash('sha512', $payment->payment_code.$statusCode.$grossAmount.$serverKey),
    ];
}

it('rejects midtrans webhooks with an invalid signature', function () {
    config()->set('payment-module.midtrans_server_key', 'server-key');

    $payment = createMidtransPayment();

    $payload = midtransWebhookPayload($payment, 'settlement', 'wrong-key');

    $this->postJson('/webhooks/midtrans', $payload)
        ->assertStatus(500);

    expect($payment->fresh()->status)->toBe(PaymentStatus::PENDING)
        ->and(WebhookCall::count())->toBe(0);
});

it('marks the payment as paid on a settlement notification', function () {
    config()->set('payment-module.midtrans_server_key', 'server-key');

    $payment = createMidtransPayment();

    $this->postJson('/webhooks/midtrans', midtransWebhookPayload($payment, 'settlement', 'server-key'))
        ->assertOk()
        ->assertJson(['message' => 'ok']);

    expect($payment->fresh()->status)->toBe(PaymentStatus::PAID)
        ->and(WebhookCall::count())->toBe(1);
});

it('marks the payment as failed on an expire notification', function () {
    config()->set('payment-module.midtrans_server_key', 'server-key');

    $payment = createMidtransPayment();

    $this->postJson('/webhooks/midtrans', midtransWebhookPayload($payment, 'expire', 'server-key'))
        ->assertOk();

    expect($payment->fresh()->status)->toBe(PaymentStatus::FAILED);
});

it('ignores midtrans notifications with an unknown transaction status', function () {
    config()->set('payment-module.midtrans_server_key', 'server-key');

    $payment = createMidtransPayment();

    $this->postJson('/webhooks/midtrans', midtransWebhookPayload($payment, 'refund', 'server-key'))
        ->assertOk()
        ->assertJson(['message' => 'ok']);

    expect($payment->fresh()->status)->toBe(PaymentStatus::PENDING);
});
