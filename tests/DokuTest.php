<?php

use CodeWithDiki\PaymentModule\Data\PaymentData;
use CodeWithDiki\PaymentModule\Enums\PaymentStatus;
use CodeWithDiki\PaymentModule\Enums\PaymentVendor;
use CodeWithDiki\PaymentModule\Facades\PaymentModule;
use CodeWithDiki\PaymentModule\Models\Payment;
use CodeWithDiki\PaymentModule\Models\PaymentMethod;
use CodeWithDiki\PaymentModule\Models\PaymentMethodGroup;
use CodeWithDiki\PaymentModule\Supports\Doku\SnapClient;
use CodeWithDiki\PaymentModule\Supports\PaymentMethod\Doku;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Spatie\WebhookClient\Models\WebhookCall;

function dokuMethod(string $channel, array $metaData = ['partner_service_id' => '19008']): PaymentMethod
{
    return PaymentMethod::create([
        'name' => 'DOKU '.$channel,
        'vendor' => PaymentVendor::Doku,
        'channel' => $channel,
        'is_active' => true,
        'fee_flat' => 4400,
        'fee_percentage' => 0,
        'meta_data' => $metaData,
    ]);
}

function dokuPaymentable(): PaymentMethodGroup
{
    return PaymentMethodGroup::create([
        'name' => 'Order',
        'slug' => 'order-'.uniqid(),
        'is_active' => true,
    ]);
}

function dokuCreatePayment(string $channel): Payment
{
    return PaymentModule::createPayment(new PaymentData(
        paymentable: dokuPaymentable(),
        payment_method_id: dokuMethod($channel)->id,
        payment_code: 'INV-'.uniqid(),
        amount: 100000,
        status: PaymentStatus::PENDING,
    ));
}

/** A payment that already exists, used by the webhook tests so no gateway call fires. */
function dokuPayment(float $totalAmount): Payment
{
    return Payment::create([
        'paymentable_type' => 'App\\Models\\Order',
        'paymentable_id' => 1,
        'payment_method_id' => dokuMethod('VIRTUAL_ACCOUNT_BCA')->id,
        'payment_code' => 'INV-'.uniqid(),
        'amount' => $totalAmount,
        'total_amount' => $totalAmount,
        'status' => PaymentStatus::PENDING,
    ]);
}

it('maps the doku vendor to the doku processor', function () {
    expect(PaymentVendor::Doku->getPaymentProcessorClass())->toBe(Doku::class);
});

it('exposes doku channels', function () {
    $channels = (new Doku(new SnapClient))->getChannels();

    expect($channels->keys()->all())->toContain('VIRTUAL_ACCOUNT_BCA', 'EMONEY_OVO', 'QRIS');
});

it('builds the snap string to sign and signature exactly as doku documents it', function () {
    // Both fixtures are taken verbatim from developers.doku.com
    $stringToSign = SnapClient::stringToSign('POST', '/x', 'tok', '{"a":1}', '2024-03-26T16:01:41+07:00');

    expect($stringToSign)->toBe('POST:/x:tok:'.hash('sha256', '{"a":1}').':2024-03-26T16:01:41+07:00')
        ->and(SnapClient::symmetricSignature($stringToSign, 'secret'))
        ->toBe(base64_encode(hash_hmac('sha512', $stringToSign, 'secret', true)));
});

it('creates a closed virtual account billing the total amount', function () {
    dokuCredentials();
    dokuFake([
        'https://api-sandbox.doku.com/virtual-accounts/*' => Http::response([
            'responseCode' => '2002700',
            'virtualAccountData' => ['virtualAccountNo' => '190080000123'],
        ]),
    ]);

    $payment = dokuCreatePayment('VIRTUAL_ACCOUNT_BCA');

    Http::assertSent(function (Request $request) {
        if (! str_contains($request->url(), '/transfer-va/create-va')) {
            return false;
        }

        return $request->hasHeader('Authorization', 'Bearer token-abc')
            && $request->hasHeader('CHANNEL-ID', 'H2H')
            && $request->header('X-SIGNATURE') !== []
            && $request['partnerServiceId'] === '   19008'
            && $request['virtualAccountTrxType'] === 'C'
            && $request['additionalInfo']['channel'] === 'VIRTUAL_ACCOUNT_BCA'
            // total = amount (100000) + flat fee (4400)
            && $request['totalAmount']['value'] === '104400.00';
    });

    expect($payment->fresh()->getDokuVirtualAccountNumber())->toBe('190080000123');
});

it('creates an ewallet charge for ewallet channels', function () {
    dokuCredentials();
    dokuFake([
        'https://api-sandbox.doku.com/direct-debit/*' => Http::response(['responseCode' => '2005400']),
    ]);

    dokuCreatePayment('EMONEY_OVO');

    Http::assertSent(fn (Request $request) => str_contains($request->url(), '/debit/payment-host-to-host')
        && $request['additionalInfo']['channel'] === 'EMONEY_OVO'
        && $request['amount']['value'] === '104400.00');
});

it('creates a dynamic qr code for the qris channel', function () {
    dokuCredentials();
    dokuFake([
        'https://api-sandbox.doku.com/snap-adapter/*' => Http::response([
            'responseCode' => '2004700',
            'qrContent' => '00020101021226',
        ]),
    ]);

    $payment = dokuCreatePayment('QRIS');

    Http::assertSent(fn (Request $request) => str_contains($request->url(), '/qr/qr-mpm-generate')
        && $request['amount']['value'] === '104400.00'
        && $request['merchantId'] === 'MCH-0001-1079'
        // All three are mandatory; DOKU answers 4004702 when any is missing
        && $request['terminalId'] === 'TERM01'
        && $request['additionalInfo']['postalCode'] === '40115'
        && $request['additionalInfo']['feeType'] === '1'
        // QRIS is host-to-host and carries no additionalInfo.channel
        && $request->hasHeader('CHANNEL-ID', 'H2H')
        && ! isset($request['additionalInfo']['channel']));

    expect($payment->fresh()->getDokuQrString())->toBe('00020101021226');
});

it('reuses the cached access token across payments', function () {
    dokuCredentials();
    dokuFake([
        'https://api-sandbox.doku.com/virtual-accounts/*' => Http::response([
            'responseCode' => '2002700',
            'virtualAccountData' => ['virtualAccountNo' => '190080000123'],
        ]),
    ]);

    dokuCreatePayment('VIRTUAL_ACCOUNT_BCA');
    dokuCreatePayment('VIRTUAL_ACCOUNT_BCA');

    $tokenCalls = 0;

    Http::assertSent(function (Request $request) use (&$tokenCalls) {
        if (str_contains($request->url(), SnapClient::TOKEN_PATH)) {
            $tokenCalls++;
        }

        return true;
    });

    expect($tokenCalls)->toBe(1);
});

it('fails the payment when the doku charge is rejected', function () {
    dokuCredentials();
    dokuFake([
        'https://api-sandbox.doku.com/virtual-accounts/*' => Http::response([
            'responseCode' => '4002701',
            'responseMessage' => 'Invalid Field Format',
        ], 400),
    ]);

    $payment = dokuCreatePayment('VIRTUAL_ACCOUNT_BCA');

    expect($payment->fresh()->status)->toBe(PaymentStatus::FAILED)
        ->and($payment->fresh()->payment_response['responseCode'])->toBe('4002701');
});

it('records the request and the response body when doku rejects the token request', function () {
    dokuCredentials();
    Http::fake([
        'https://api-sandbox.doku.com/authorization/v1/access-token/b2b' => Http::response(
            '<html>Unauthorized</html>',
            401,
        ),
    ]);

    $payment = dokuCreatePayment('VIRTUAL_ACCOUNT_BCA')->fresh();

    expect($payment->status)->toBe(PaymentStatus::FAILED)
        ->and($payment->payment_response['status'])->toBe(401)
        ->and($payment->payment_response['body'])->toContain('Unauthorized')
        ->and($payment->payment_headers['X-CLIENT-KEY'])->toBe('MCH-0001-1079')
        ->and($payment->payment_payload)->toBe(['grantType' => 'client_credentials']);
});

it('records the signed request headers and payload without leaking the bearer token', function () {
    dokuCredentials();
    dokuFake([
        'https://api-sandbox.doku.com/virtual-accounts/*' => Http::response([
            'responseCode' => '4012700',
            'responseMessage' => 'Unauthorized. Invalid Signature',
        ], 401),
    ]);

    $payment = dokuCreatePayment('VIRTUAL_ACCOUNT_BCA')->fresh();

    expect($payment->status)->toBe(PaymentStatus::FAILED)
        ->and($payment->payment_response['responseCode'])->toBe('4012700')
        ->and($payment->payment_headers['Authorization'])->toBe('Bearer [redacted]')
        ->and($payment->payment_headers['X-SIGNATURE'])->not->toBeEmpty()
        ->and($payment->payment_headers['CHANNEL-ID'])->toBe('H2H')
        ->and($payment->payment_payload['trxId'])->toBe($payment->payment_code);
});

it('rejects doku webhooks with an invalid signature', function () {
    dokuCredentials();

    dokuPostWebhook('/webhooks/doku', ['trxId' => 'INV-1'], 'not-a-valid-signature')
        ->assertStatus(500);

    expect(WebhookCall::count())->toBe(0);
});

it('rejects doku webhooks when the client secret is not configured', function () {
    config()->set('payment-module.doku_client_secret', '');

    $payment = dokuPayment(104400);

    // A signature crafted with the empty secret would pass a naive validator
    dokuPostWebhook('/webhooks/doku', ['trxId' => $payment->payment_code])->assertStatus(500);

    expect($payment->fresh()->status)->toBe(PaymentStatus::PENDING)
        ->and(WebhookCall::count())->toBe(0);
});

it('marks the payment paid on a virtual account notification', function () {
    dokuCredentials();

    $payment = dokuPayment(104400);

    dokuPostWebhook('/webhooks/doku', [
        'partnerServiceId' => '   19008',
        'virtualAccountNo' => '190080000123',
        'trxId' => $payment->payment_code,
        'paidAmount' => ['value' => '104400.00', 'currency' => 'IDR'],
    ])->assertOk();

    expect($payment->fresh()->status)->toBe(PaymentStatus::PAID)
        ->and(WebhookCall::count())->toBe(1);
});

it('marks the payment paid on a succeeded ewallet notification', function () {
    dokuCredentials();

    $payment = dokuPayment(104400);

    dokuPostWebhook('/webhooks/doku', [
        'originalPartnerReferenceNo' => $payment->payment_code,
        'latestTransactionStatus' => '00',
        'transactionStatusDesc' => 'Success',
        'amount' => ['value' => '104400.00', 'currency' => 'IDR'],
    ])->assertOk();

    expect($payment->fresh()->status)->toBe(PaymentStatus::PAID);
});

it('marks the payment failed on a failed ewallet notification', function () {
    dokuCredentials();

    $payment = dokuPayment(104400);

    dokuPostWebhook('/webhooks/doku', [
        'originalPartnerReferenceNo' => $payment->payment_code,
        'latestTransactionStatus' => '06',
        'transactionStatusDesc' => 'Failed',
        'amount' => ['value' => '104400.00', 'currency' => 'IDR'],
    ])->assertOk();

    expect($payment->fresh()->status)->toBe(PaymentStatus::FAILED);
});

it('leaves the payment pending on a pending notification', function () {
    dokuCredentials();

    $payment = dokuPayment(104400);

    dokuPostWebhook('/webhooks/doku', [
        'originalPartnerReferenceNo' => $payment->payment_code,
        'latestTransactionStatus' => '03',
        'amount' => ['value' => '104400.00', 'currency' => 'IDR'],
    ])->assertOk();

    expect($payment->fresh()->status)->toBe(PaymentStatus::PENDING);
});

it('ignores a doku notification when the amount does not match', function () {
    dokuCredentials();

    $payment = dokuPayment(104400);

    // Gateway reports only the base amount, not amount + fee
    dokuPostWebhook('/webhooks/doku', [
        'trxId' => $payment->payment_code,
        'paidAmount' => ['value' => '100000.00', 'currency' => 'IDR'],
    ])->assertOk();

    expect($payment->fresh()->status)->toBe(PaymentStatus::PENDING);
});

it('verifies the notification signature against the cached access token', function () {
    dokuCredentials();
    Cache::put('payment-module.doku.access_token', 'live-token', 600);

    $payment = dokuPayment(104400);

    // dokuPostWebhook signs with SnapClient::cachedAccessToken(), which is now 'live-token'
    dokuPostWebhook('/webhooks/doku', [
        'trxId' => $payment->payment_code,
        'paidAmount' => ['value' => '104400.00', 'currency' => 'IDR'],
    ])->assertOk();

    expect($payment->fresh()->status)->toBe(PaymentStatus::PAID);
});
