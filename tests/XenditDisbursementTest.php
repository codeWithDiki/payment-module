<?php

use CodeWithDiki\PaymentModule\Data\DisbursementData;
use CodeWithDiki\PaymentModule\Enums\DisbursementStatus;
use CodeWithDiki\PaymentModule\Enums\PaymentVendor;
use CodeWithDiki\PaymentModule\Facades\PaymentModule;
use CodeWithDiki\PaymentModule\Models\Disbursement;
use CodeWithDiki\PaymentModule\Supports\Disbursement\XenditDisbursement;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Spatie\WebhookClient\Models\WebhookCall;

function makeXenditDisbursementData(array $overrides = []): DisbursementData
{
    return new DisbursementData(...array_merge([
        'vendor' => PaymentVendor::Xendit,
        'disbursement_code' => 'DISB-'.uniqid(),
        'amount' => 100000,
        'beneficiary_name' => 'Budi Santoso',
        'beneficiary_account' => '1234567890',
        'beneficiary_bank' => 'BCA',
        'beneficiary_email' => 'budi@email.com',
        'notes' => 'Withdrawal payout',
    ], $overrides));
}

it('maps the xendit vendor to the xendit disbursement processor', function () {
    expect(PaymentVendor::Xendit->getDisbursementProcessorClass())->toBe(XenditDisbursement::class);
});

it('sends the payout to xendit and stores the reference on creation', function () {
    config()->set('payment-module.xendit_secret_key', 'xnd_secret');

    Http::fake([
        'https://api.xendit.co/disbursements' => Http::response([
            'id' => 'disb_xnd_1',
            'status' => 'PENDING',
        ], 200),
    ]);

    $disbursement = PaymentModule::createDisbursement(makeXenditDisbursementData());

    Http::assertSent(function (Request $request) use ($disbursement) {
        return str_contains($request->url(), '/disbursements')
            && $request->hasHeader('X-IDEMPOTENCY-KEY', $disbursement->disbursement_code)
            && $request->hasHeader('Authorization', 'Basic '.base64_encode('xnd_secret:'))
            && $request['bank_code'] === 'BCA'
            && $request['account_holder_name'] === 'Budi Santoso'
            && $request['account_number'] === '1234567890'
            && (float) $request['amount'] === 100000.0;
    });

    $disbursement->refresh();

    expect($disbursement->reference_no)->toBe('disb_xnd_1')
        ->and($disbursement->status)->toBe(DisbursementStatus::PROCESSED);
});

it('marks the disbursement failed when xendit rejects the payout', function () {
    config()->set('payment-module.xendit_secret_key', 'xnd_secret');

    Http::fake([
        'https://api.xendit.co/disbursements' => Http::response([
            'error_code' => 'INSUFFICIENT_BALANCE',
            'message' => 'Insufficient balance',
        ], 400),
    ]);

    $disbursement = PaymentModule::createDisbursement(makeXenditDisbursementData());

    $disbursement->refresh();

    expect($disbursement->status)->toBe(DisbursementStatus::FAILED)
        ->and($disbursement->error_message)->toBe('Insufficient balance');
});

it('does not support approve or reject for xendit disbursements', function () {
    $processor = new XenditDisbursement;

    $disbursement = new Disbursement(['disbursement_code' => 'DISB-1']);

    expect(fn () => $processor->approveDisbursement($disbursement))->toThrow(BadMethodCallException::class)
        ->and(fn () => $processor->rejectDisbursement($disbursement))->toThrow(BadMethodCallException::class);
});

it('completes the disbursement on a xendit completed callback', function () {
    config()->set('payment-module.xendit_webhook_token', 'verify-token');

    $disbursement = Disbursement::create([
        'disbursement_code' => 'DISB-'.uniqid(),
        'reference_no' => 'disb_xnd_2',
        'vendor' => PaymentVendor::Xendit,
        'beneficiary_name' => 'Budi',
        'beneficiary_account' => '123',
        'beneficiary_bank' => 'BCA',
        'amount' => 100000,
        'status' => DisbursementStatus::PROCESSED,
    ]);

    $payload = json_encode([
        'id' => 'disb_xnd_2',
        'external_id' => $disbursement->disbursement_code,
        'status' => 'COMPLETED',
        'amount' => 100000,
    ]);

    $this->call('POST', '/webhooks/xendit/disbursement', [], [], [], [
        'HTTP_X_CALLBACK_TOKEN' => 'verify-token',
        'CONTENT_TYPE' => 'application/json',
    ], $payload)->assertOk();

    $disbursement->refresh();

    expect($disbursement->status)->toBe(DisbursementStatus::COMPLETED)
        ->and($disbursement->completed_at)->not->toBeNull()
        ->and(WebhookCall::count())->toBe(1);
});

it('rejects xendit disbursement callbacks with an invalid token', function () {
    config()->set('payment-module.xendit_webhook_token', 'verify-token');

    $this->call('POST', '/webhooks/xendit/disbursement', [], [], [], [
        'HTTP_X_CALLBACK_TOKEN' => 'nope',
        'CONTENT_TYPE' => 'application/json',
    ], json_encode(['id' => 'disb_xnd_2', 'status' => 'COMPLETED']))
        ->assertStatus(500);

    expect(WebhookCall::count())->toBe(0);
});
