<?php

use CodeWithDiki\PaymentModule\Data\PaymentData;
use CodeWithDiki\PaymentModule\Enums\PaymentStatus;
use CodeWithDiki\PaymentModule\Enums\PaymentVendor;
use CodeWithDiki\PaymentModule\Facades\PaymentModule;
use CodeWithDiki\PaymentModule\Models\Payment;
use CodeWithDiki\PaymentModule\Models\PaymentMethod;
use CodeWithDiki\PaymentModule\Models\PaymentMethodGroup;
use CodeWithDiki\PaymentModule\Supports\PaymentMethod\Paypal;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

function paypalMethod(): PaymentMethod
{
    return PaymentMethod::create([
        'name' => 'PayPal',
        'vendor' => PaymentVendor::Paypal,
        'channel' => 'paypal',
        'is_active' => true,
    ]);
}

function paypalPaymentable(): PaymentMethodGroup
{
    return PaymentMethodGroup::create([
        'name' => 'Order',
        'slug' => 'order-'.uniqid(),
        'is_active' => true,
    ]);
}

/** Creates a pending PayPal payment and runs the gateway listener synchronously. */
function paypalPayment(): Payment
{
    return PaymentModule::createPayment(new PaymentData(
        paymentable: paypalPaymentable(),
        payment_method_id: paypalMethod()->id,
        payment_code: 'BUY-'.uniqid(),
        amount: 150,
        status: PaymentStatus::PENDING,
    ));
}

function paypalTokenResponse(): array
{
    return [
        'https://api-m.sandbox.paypal.com/v1/oauth2/token' => Http::response(['access_token' => 'pp-token']),
        'https://api-m.sandbox.paypal.com/v2/checkout/orders' => Http::response([
            'id' => 'ORDER-1',
            'status' => 'PAYER_ACTION_REQUIRED',
            'links' => [
                ['rel' => 'payer-action', 'href' => 'https://sandbox.paypal.com/checkoutnow?token=ORDER-1'],
            ],
        ], 201),
    ];
}

function paypalConfig(): void
{
    config()->set('payment-module.paypal_client_id', 'client-id');
    config()->set('payment-module.paypal_client_secret', 'client-secret');
}

it('maps the paypal vendor to the paypal processor', function () {
    expect(PaymentVendor::Paypal->getPaymentProcessorClass())->toBe(Paypal::class);
});

it('sends the configured credentials to the token endpoint', function () {
    paypalConfig();

    Http::fake(paypalTokenResponse());

    paypalPayment();

    Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/v1/oauth2/token')
        && $request->hasHeader('Authorization', 'Basic '.base64_encode('client-id:client-secret'))
        && str_contains($request->body(), 'grant_type=client_credentials'));
});

it('creates the order with the bearer token and stores the payer-action link', function () {
    paypalConfig();

    Http::fake(paypalTokenResponse());

    $payment = paypalPayment();

    Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/v2/checkout/orders')
        && $request->hasHeader('Authorization', 'Bearer pp-token')
        && $request['intent'] === 'CAPTURE'
        && $request['purchase_units'][0]['amount']['value'] === '150.00'
        && $request['purchase_units'][0]['reference_id'] === $payment->payment_code);

    expect($payment->fresh()->payment_response['id'])->toBe('ORDER-1')
        ->and($payment->fresh()->getPaymentInstruction($payment->fresh())->redirect_url)
        ->toBe('https://sandbox.paypal.com/checkoutnow?token=ORDER-1');
});

it('fails loudly when paypal credentials are missing', function () {
    // Regression: env() inside a published config is only evaluated on config:build, so an
    // app whose config/payment-module.php predates the paypal_client_id keys got an empty
    // client id and PayPal answered AUTHENTICATION_FAILURE with no hint of the cause.
    config()->set('payment-module.paypal_client_id', '');
    config()->set('payment-module.paypal_client_secret', '');

    Http::fake(paypalTokenResponse());

    expect(fn () => paypalPayment())->toThrow(RuntimeException::class, 'not configured');

    Http::assertNothingSent();
});

it('throws instead of storing an error body when paypal rejects the order', function () {
    paypalConfig();

    Http::fake([
        'https://api-m.sandbox.paypal.com/v1/oauth2/token' => Http::response(['access_token' => 'pp-token']),
        'https://api-m.sandbox.paypal.com/v2/checkout/orders' => Http::response([
            'name' => 'AUTHENTICATION_FAILURE',
            'message' => 'Authentication failed due to invalid authentication credentials or a missing Authorization header.',
        ], 401),
    ]);

    expect(fn () => paypalPayment())->toThrow(\Illuminate\Http\Client\RequestException::class);
});
