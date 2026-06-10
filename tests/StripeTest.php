<?php

use CodeWithDiki\PaymentModule\Enums\PaymentStatus;
use CodeWithDiki\PaymentModule\Enums\PaymentVendor;
use CodeWithDiki\PaymentModule\Models\Payment;
use CodeWithDiki\PaymentModule\Models\PaymentMethod;
use CodeWithDiki\PaymentModule\Supports\PaymentMethod\Stripe;

function createStripePayment(array $attributes = []): Payment
{
    $paymentMethod = PaymentMethod::create([
        'name' => 'Card',
        'vendor' => PaymentVendor::Stripe,
        'channel' => 'card',
        'is_active' => true,
    ]);

    return Payment::create(array_merge([
        'paymentable_type' => 'App\\Models\\Order',
        'paymentable_id' => 1,
        'payment_method_id' => $paymentMethod->id,
        'payment_code' => 'INV-'.uniqid(),
        'amount' => 100,
        'status' => PaymentStatus::PENDING,
    ], $attributes));
}

function stripeSignatureHeader(string $payload, string $secret): string
{
    $timestamp = time();
    $signature = hash_hmac('sha256', $timestamp.'.'.$payload, $secret);

    return "t={$timestamp},v1={$signature}";
}

it('maps the stripe vendor to the stripe processor', function () {
    expect(PaymentVendor::Stripe->getPaymentProcessorClass())->toBe(Stripe::class);
});

it('exposes stripe channels', function () {
    $channels = (new Stripe)->getChannels();

    expect($channels->keys()->all())->toContain('card', 'link', 'alipay', 'wechat_pay');
});

it('returns the checkout url for stripe payments', function () {
    $payment = createStripePayment([
        'payment_response' => ['url' => 'https://checkout.stripe.com/c/pay/cs_test_123'],
    ]);

    expect($payment->getStripeCheckoutUrl())->toBe('https://checkout.stripe.com/c/pay/cs_test_123');
});

it('returns null checkout url for non stripe payments', function () {
    $paymentMethod = PaymentMethod::create([
        'name' => 'GoPay',
        'vendor' => PaymentVendor::Midtrans,
        'channel' => 'gopay',
        'is_active' => true,
    ]);

    $payment = Payment::create([
        'paymentable_type' => 'App\\Models\\Order',
        'paymentable_id' => 1,
        'payment_method_id' => $paymentMethod->id,
        'payment_code' => 'INV-'.uniqid(),
        'amount' => 100,
        'status' => PaymentStatus::PENDING,
        'payment_response' => ['url' => 'https://example.com'],
    ]);

    expect($payment->getStripeCheckoutUrl())->toBeNull();
});

it('rejects stripe webhooks with an invalid signature', function () {
    config()->set('payment-module.stripe_webhook_secret', 'whsec_test');

    $this->postJson('/webhooks/stripe', ['type' => 'checkout.session.completed'])
        ->assertStatus(400)
        ->assertJson(['status' => 'error']);
});

it('marks the payment as paid on checkout session completed', function () {
    config()->set('payment-module.stripe_webhook_secret', 'whsec_test');

    $payment = createStripePayment();

    $payload = json_encode([
        'id' => 'evt_test',
        'object' => 'event',
        'type' => 'checkout.session.completed',
        'data' => [
            'object' => [
                'object' => 'checkout.session',
                'payment_status' => 'paid',
                'client_reference_id' => $payment->payment_code,
            ],
        ],
    ]);

    $this->call('POST', '/webhooks/stripe', [], [], [], [
        'HTTP_STRIPE_SIGNATURE' => stripeSignatureHeader($payload, 'whsec_test'),
        'CONTENT_TYPE' => 'application/json',
    ], $payload)
        ->assertOk()
        ->assertJson(['status' => 'success']);

    expect($payment->fresh()->status)->toBe(PaymentStatus::PAID);
});

it('marks the payment as failed on checkout session expired', function () {
    config()->set('payment-module.stripe_webhook_secret', 'whsec_test');

    $payment = createStripePayment();

    $payload = json_encode([
        'id' => 'evt_test',
        'object' => 'event',
        'type' => 'checkout.session.expired',
        'data' => [
            'object' => [
                'object' => 'checkout.session',
                'client_reference_id' => $payment->payment_code,
            ],
        ],
    ]);

    $this->call('POST', '/webhooks/stripe', [], [], [], [
        'HTTP_STRIPE_SIGNATURE' => stripeSignatureHeader($payload, 'whsec_test'),
        'CONTENT_TYPE' => 'application/json',
    ], $payload)
        ->assertOk()
        ->assertJson(['status' => 'success']);

    expect($payment->fresh()->status)->toBe(PaymentStatus::FAILED);
});

it('ignores unrelated stripe events', function () {
    config()->set('payment-module.stripe_webhook_secret', 'whsec_test');

    $payload = json_encode([
        'id' => 'evt_test',
        'object' => 'event',
        'type' => 'payment_intent.created',
        'data' => ['object' => ['object' => 'payment_intent']],
    ]);

    $this->call('POST', '/webhooks/stripe', [], [], [], [
        'HTTP_STRIPE_SIGNATURE' => stripeSignatureHeader($payload, 'whsec_test'),
        'CONTENT_TYPE' => 'application/json',
    ], $payload)
        ->assertOk()
        ->assertJson(['status' => 'ignored']);
});
