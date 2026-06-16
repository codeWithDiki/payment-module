<?php

use CodeWithDiki\PaymentModule\Data\PaymentData;
use CodeWithDiki\PaymentModule\Enums\PaymentStatus;
use CodeWithDiki\PaymentModule\Enums\PaymentVendor;
use CodeWithDiki\PaymentModule\Facades\PaymentModule;
use CodeWithDiki\PaymentModule\Models\Payment;
use CodeWithDiki\PaymentModule\Models\PaymentMethod;
use CodeWithDiki\PaymentModule\Models\PaymentMethodGroup;
use CodeWithDiki\PaymentModule\Supports\PaymentMethod\Xendit;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Spatie\WebhookClient\Models\WebhookCall;

function xenditMethod(string $channel): PaymentMethod
{
    return PaymentMethod::create([
        'name' => 'Xendit '.$channel,
        'vendor' => PaymentVendor::Xendit,
        'channel' => $channel,
        'is_active' => true,
        'fee_flat' => 4400,
        'fee_percentage' => 0,
    ]);
}

function xenditPaymentable(): PaymentMethodGroup
{
    return PaymentMethodGroup::create([
        'name' => 'Order',
        'slug' => 'order-'.uniqid(),
        'is_active' => true,
    ]);
}

it('maps the xendit vendor to the xendit processor', function () {
    expect(PaymentVendor::Xendit->getPaymentProcessorClass())->toBe(Xendit::class);
});

it('exposes xendit channels', function () {
    $channels = (new Xendit)->getChannels();

    expect($channels->keys()->all())->toContain('BCA', 'ID_OVO', 'QRIS');
});

it('creates a closed virtual account billing the total amount', function () {
    config()->set('payment-module.xendit_secret_key', 'xnd_secret');

    Http::fake([
        'https://api.xendit.co/callback_virtual_accounts' => Http::response([
            'id' => 'va_123',
            'account_number' => '8808123',
            'external_id' => 'INV-1',
        ]),
    ]);

    PaymentModule::createPayment(new PaymentData(
        paymentable: xenditPaymentable(),
        payment_method_id: xenditMethod('BCA')->id,
        payment_code: 'INV-VA-'.uniqid(),
        amount: 100000,
        status: PaymentStatus::PENDING,
    ));

    Http::assertSent(function (Request $request) {
        return str_contains($request->url(), '/callback_virtual_accounts')
            && $request->hasHeader('Authorization', 'Basic '.base64_encode('xnd_secret:'))
            && $request['bank_code'] === 'BCA'
            // total = amount (100000) + flat fee (4400)
            && (float) $request['expected_amount'] === 104400.0;
    });
});

it('creates an ewallet charge for ewallet channels', function () {
    config()->set('payment-module.xendit_secret_key', 'xnd_secret');

    Http::fake([
        'https://api.xendit.co/ewallets/charges' => Http::response(['id' => 'ewc_1', 'status' => 'PENDING']),
    ]);

    PaymentModule::createPayment(new PaymentData(
        paymentable: xenditPaymentable(),
        payment_method_id: xenditMethod('ID_OVO')->id,
        payment_code: 'INV-EW-'.uniqid(),
        amount: 100000,
        status: PaymentStatus::PENDING,
    ));

    Http::assertSent(fn (Request $request) => str_contains($request->url(), '/ewallets/charges')
        && $request['channel_code'] === 'ID_OVO'
        && (float) $request['amount'] === 104400.0);
});

it('creates a dynamic qr code for the qris channel', function () {
    config()->set('payment-module.xendit_secret_key', 'xnd_secret');

    Http::fake([
        'https://api.xendit.co/qr_codes' => Http::response(['id' => 'qr_1', 'qr_string' => '00020101']),
    ]);

    PaymentModule::createPayment(new PaymentData(
        paymentable: xenditPaymentable(),
        payment_method_id: xenditMethod('QRIS')->id,
        payment_code: 'INV-QR-'.uniqid(),
        amount: 100000,
        status: PaymentStatus::PENDING,
    ));

    Http::assertSent(fn (Request $request) => str_contains($request->url(), '/qr_codes')
        && $request['type'] === 'DYNAMIC'
        && (float) $request['amount'] === 104400.0);
});

function xenditPayment(float $totalAmount): Payment
{
    $method = PaymentMethod::create([
        'name' => 'Xendit BCA',
        'vendor' => PaymentVendor::Xendit,
        'channel' => 'BCA',
        'is_active' => true,
    ]);

    return Payment::create([
        'paymentable_type' => 'App\\Models\\Order',
        'paymentable_id' => 1,
        'payment_method_id' => $method->id,
        'payment_code' => 'INV-'.uniqid(),
        'amount' => $totalAmount,
        'total_amount' => $totalAmount,
        'status' => PaymentStatus::PENDING,
    ]);
}

it('rejects xendit webhooks with an invalid callback token', function () {
    config()->set('payment-module.xendit_webhook_token', 'verify-token');

    $this->call('POST', '/webhooks/xendit', [], [], [], [
        'HTTP_X_CALLBACK_TOKEN' => 'wrong-token',
        'CONTENT_TYPE' => 'application/json',
    ], json_encode(['external_id' => 'INV-1']))
        ->assertStatus(500);

    expect(WebhookCall::count())->toBe(0);
});

it('marks the payment paid on a virtual account callback', function () {
    config()->set('payment-module.xendit_webhook_token', 'verify-token');

    $payment = xenditPayment(104400);

    $payload = json_encode([
        'payment_id' => 'pay_1',
        'callback_virtual_account_id' => 'va_1',
        'external_id' => $payment->payment_code,
        'amount' => 104400,
    ]);

    $this->call('POST', '/webhooks/xendit', [], [], [], [
        'HTTP_X_CALLBACK_TOKEN' => 'verify-token',
        'CONTENT_TYPE' => 'application/json',
    ], $payload)->assertOk();

    expect($payment->fresh()->status)->toBe(PaymentStatus::PAID)
        ->and(WebhookCall::count())->toBe(1);
});

it('ignores a xendit callback when the amount does not match', function () {
    config()->set('payment-module.xendit_webhook_token', 'verify-token');

    $payment = xenditPayment(104400);

    $payload = json_encode([
        'payment_id' => 'pay_1',
        'external_id' => $payment->payment_code,
        'amount' => 100000,
    ]);

    $this->call('POST', '/webhooks/xendit', [], [], [], [
        'HTTP_X_CALLBACK_TOKEN' => 'verify-token',
        'CONTENT_TYPE' => 'application/json',
    ], $payload)->assertOk();

    expect($payment->fresh()->status)->toBe(PaymentStatus::PENDING);
});

it('marks the payment paid on a succeeded ewallet callback', function () {
    config()->set('payment-module.xendit_webhook_token', 'verify-token');

    $payment = xenditPayment(104400);

    $payload = json_encode([
        'event' => 'ewallet.capture',
        'data' => [
            'reference_id' => $payment->payment_code,
            'status' => 'SUCCEEDED',
            'charge_amount' => 104400,
        ],
    ]);

    $this->call('POST', '/webhooks/xendit', [], [], [], [
        'HTTP_X_CALLBACK_TOKEN' => 'verify-token',
        'CONTENT_TYPE' => 'application/json',
    ], $payload)->assertOk();

    expect($payment->fresh()->status)->toBe(PaymentStatus::PAID);
});
