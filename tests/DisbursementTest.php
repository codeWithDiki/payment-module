<?php

use CodeWithDiki\PaymentModule\Data\DisbursementData;
use CodeWithDiki\PaymentModule\Enums\DisbursementStatus;
use CodeWithDiki\PaymentModule\Enums\PaymentVendor;
use CodeWithDiki\PaymentModule\Exceptions\DisbursementNotSupportedException;
use CodeWithDiki\PaymentModule\Facades\PaymentModule;
use CodeWithDiki\PaymentModule\Models\Disbursement;
use CodeWithDiki\PaymentModule\Supports\Disbursement\MidtransIris;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Spatie\WebhookClient\Models\WebhookCall;

function makeDisbursementData(array $overrides = []): DisbursementData
{
    return new DisbursementData(...array_merge([
        'vendor' => PaymentVendor::Midtrans,
        'disbursement_code' => 'DISB-'.uniqid(),
        'amount' => 100000,
        'beneficiary_name' => 'Budi Santoso',
        'beneficiary_account' => '1234567890',
        'beneficiary_bank' => 'bca',
        'beneficiary_email' => 'budi@email.com',
        'notes' => 'Withdrawal payout',
    ], $overrides));
}

function irisSignature(string $payload, string $merchantKey): string
{
    return hash('sha512', $payload.$merchantKey);
}

it('maps vendors to disbursement processors', function () {
    expect(PaymentVendor::Midtrans->getDisbursementProcessorClass())->toBe(MidtransIris::class)
        ->and(PaymentVendor::Stripe->getDisbursementProcessorClass())->toBeNull()
        ->and(PaymentVendor::Offline->getDisbursementProcessorClass())->toBeNull();
});

it('throws when creating a disbursement for an unsupported vendor', function () {
    PaymentModule::createDisbursement(makeDisbursementData([
        'vendor' => PaymentVendor::Stripe,
    ]));
})->throws(DisbursementNotSupportedException::class);

it('creates a midtrans payout and stores the reference number', function () {
    config()->set('payment-module.midtrans_iris_creator_key', 'creator-key');

    Http::fake([
        'https://app.sandbox.midtrans.com/iris/api/v1/payouts' => Http::response([
            'payouts' => [
                ['status' => 'queued', 'reference_no' => 'REF-123'],
            ],
        ], 201),
    ]);

    $disbursement = PaymentModule::createDisbursement(makeDisbursementData());

    Http::assertSent(function (Request $request) use ($disbursement) {
        return str_contains($request->url(), '/iris/api/v1/payouts')
            && $request->hasHeader('X-Idempotency-Key', $disbursement->disbursement_code)
            && $request->hasHeader('Authorization', 'Basic '.base64_encode('creator-key:'))
            && $request['payouts'][0]['beneficiary_bank'] === 'bca'
            && $request['payouts'][0]['amount'] === '100000.00';
    });

    $disbursement->refresh();

    expect($disbursement->reference_no)->toBe('REF-123')
        ->and($disbursement->status)->toBe(DisbursementStatus::QUEUED);
});

it('marks the disbursement as failed when the payout request is rejected by midtrans', function () {
    Http::fake([
        'https://app.sandbox.midtrans.com/iris/api/v1/payouts' => Http::response([
            'error_message' => 'An error occurred when creating payouts',
        ], 400),
    ]);

    $disbursement = PaymentModule::createDisbursement(makeDisbursementData());

    $disbursement->refresh();

    expect($disbursement->status)->toBe(DisbursementStatus::FAILED)
        ->and($disbursement->error_message)->toBe('An error occurred when creating payouts');
});

it('approves a queued disbursement through the approver api', function () {
    config()->set('payment-module.midtrans_iris_approver_key', 'approver-key');

    Http::fake([
        'https://app.sandbox.midtrans.com/iris/api/v1/payouts/approve' => Http::response([
            'status' => 'ok',
        ]),
    ]);

    $disbursement = Disbursement::create([
        'disbursement_code' => 'DISB-'.uniqid(),
        'reference_no' => 'REF-123',
        'vendor' => PaymentVendor::Midtrans,
        'beneficiary_name' => 'Budi Santoso',
        'beneficiary_account' => '1234567890',
        'beneficiary_bank' => 'bca',
        'amount' => 100000,
        'status' => DisbursementStatus::QUEUED,
    ]);

    PaymentModule::approveDisbursement($disbursement);

    Http::assertSent(function (Request $request) {
        return str_contains($request->url(), '/payouts/approve')
            && $request->hasHeader('Authorization', 'Basic '.base64_encode('approver-key:'))
            && $request['reference_nos'] === ['REF-123'];
    });

    expect($disbursement->fresh()->status)->toBe(DisbursementStatus::APPROVED);
});

it('rejects payout webhooks with an invalid signature', function () {
    config()->set('payment-module.midtrans_iris_merchant_key', 'iris-merchant-key');

    $this->postJson('/webhooks/midtrans/payout', ['reference_no' => 'REF-123'])
        ->assertStatus(500);

    expect(WebhookCall::count())->toBe(0);
});

it('completes the disbursement on a completed payout notification', function () {
    config()->set('payment-module.midtrans_iris_merchant_key', 'iris-merchant-key');

    $disbursement = Disbursement::create([
        'disbursement_code' => 'DISB-'.uniqid(),
        'reference_no' => 'REF-123',
        'vendor' => PaymentVendor::Midtrans,
        'beneficiary_name' => 'Budi Santoso',
        'beneficiary_account' => '1234567890',
        'beneficiary_bank' => 'bca',
        'amount' => 100000,
        'status' => DisbursementStatus::PROCESSED,
    ]);

    $payload = json_encode([
        'reference_no' => 'REF-123',
        'amount' => '100000.0',
        'status' => 'completed',
        'updated_at' => now()->toIso8601String(),
    ]);

    $this->call('POST', '/webhooks/midtrans/payout', [], [], [], [
        'HTTP_IRIS_SIGNATURE' => irisSignature($payload, 'iris-merchant-key'),
        'CONTENT_TYPE' => 'application/json',
    ], $payload)
        ->assertOk()
        ->assertJson(['message' => 'ok']);

    $disbursement->refresh();

    expect($disbursement->status)->toBe(DisbursementStatus::COMPLETED)
        ->and($disbursement->completed_at)->not->toBeNull()
        ->and(WebhookCall::count())->toBe(1);
});

it('fails the disbursement on a failed payout notification with error details', function () {
    config()->set('payment-module.midtrans_iris_merchant_key', 'iris-merchant-key');

    $disbursement = Disbursement::create([
        'disbursement_code' => 'DISB-'.uniqid(),
        'reference_no' => 'REF-456',
        'vendor' => PaymentVendor::Midtrans,
        'beneficiary_name' => 'Budi Santoso',
        'beneficiary_account' => '1234567890',
        'beneficiary_bank' => 'bca',
        'amount' => 100000,
        'status' => DisbursementStatus::PROCESSED,
    ]);

    $payload = json_encode([
        'reference_no' => 'REF-456',
        'amount' => '100000.0',
        'status' => 'failed',
        'error_code' => 'INSUFFICIENT_BALANCE',
        'error_message' => 'Partner balance is not sufficient',
        'updated_at' => now()->toIso8601String(),
    ]);

    $this->call('POST', '/webhooks/midtrans/payout', [], [], [], [
        'HTTP_IRIS_SIGNATURE' => irisSignature($payload, 'iris-merchant-key'),
        'CONTENT_TYPE' => 'application/json',
    ], $payload)
        ->assertOk()
        ->assertJson(['message' => 'ok']);

    $disbursement->refresh();

    expect($disbursement->status)->toBe(DisbursementStatus::FAILED)
        ->and($disbursement->error_code)->toBe('INSUFFICIENT_BALANCE')
        ->and($disbursement->error_message)->toBe('Partner balance is not sufficient');
});

it('ignores payout notifications with an unknown status', function () {
    config()->set('payment-module.midtrans_iris_merchant_key', 'iris-merchant-key');

    $payload = json_encode([
        'reference_no' => 'REF-123',
        'status' => 'something_new',
    ]);

    $this->call('POST', '/webhooks/midtrans/payout', [], [], [], [
        'HTTP_IRIS_SIGNATURE' => irisSignature($payload, 'iris-merchant-key'),
        'CONTENT_TYPE' => 'application/json',
    ], $payload)
        ->assertOk()
        ->assertJson(['message' => 'ok']);
});
