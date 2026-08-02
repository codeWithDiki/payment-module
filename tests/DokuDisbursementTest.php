<?php

use CodeWithDiki\PaymentModule\Data\DisbursementData;
use CodeWithDiki\PaymentModule\Enums\DisbursementStatus;
use CodeWithDiki\PaymentModule\Enums\PaymentVendor;
use CodeWithDiki\PaymentModule\Facades\PaymentModule;
use CodeWithDiki\PaymentModule\Models\Disbursement;
use CodeWithDiki\PaymentModule\Supports\Disbursement\DokuKirim;
use CodeWithDiki\PaymentModule\Supports\Doku\SnapClient;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Spatie\WebhookClient\Models\WebhookCall;

function makeDokuDisbursementData(array $overrides = []): DisbursementData
{
    return new DisbursementData(...array_merge([
        'vendor' => PaymentVendor::Doku,
        'disbursement_code' => 'DISB-'.uniqid(),
        'amount' => 100000,
        'beneficiary_name' => 'Budi Santoso',
        'beneficiary_account' => '1234567890',
        // DOKU uses numeric bank codes; 014 is BCA
        'beneficiary_bank' => '014',
        'beneficiary_email' => 'budi@email.com',
        'beneficiary_phone' => '628121212121',
        'notes' => 'Withdrawal payout',
    ], $overrides));
}

function dokuSenderConfig(): void
{
    dokuCredentials();
    config()->set('payment-module.doku_sender_name', 'Toko Makmur');
    config()->set('payment-module.doku_sender_phone', '628111111111');
    config()->set('payment-module.doku_sender_personal_id', '3175000000000001');
}

function dokuFakePayout(array $inquiry, array $transfer, int $inquiryStatus = 200, int $transferStatus = 200): void
{
    dokuFake([
        'https://api-sandbox.doku.com/snap/v1.1/emoney/bank-account-inquiry' => Http::response($inquiry, $inquiryStatus),
        'https://api-sandbox.doku.com/snap/v1.1/emoney/transfer-bank' => Http::response($transfer, $transferStatus),
    ]);
}

it('maps the doku vendor to the kirim doku disbursement processor', function () {
    expect(PaymentVendor::Doku->getDisbursementProcessorClass())->toBe(DokuKirim::class);
});

it('inquires the account then sends the payout and stores the reference', function () {
    dokuSenderConfig();
    dokuFakePayout(
        inquiry: [
            'responseCode' => '2001600',
            'beneficiaryAccountName' => 'BUDI SANTOSO',
            'additionalInfo' => ['sessionId' => 'sess-123'],
        ],
        transfer: [
            'responseCode' => '2001700',
            'referenceNo' => 'doku-ref-1',
        ],
    );

    $disbursement = PaymentModule::createDisbursement(makeDokuDisbursementData());

    Http::assertSent(fn (Request $request) => str_contains($request->url(), '/bank-account-inquiry')
        && $request['beneficiaryBankCode'] === '014'
        && $request['beneficiaryAccountNumber'] === '1234567890');

    Http::assertSent(function (Request $request) use ($disbursement) {
        if (! str_contains($request->url(), '/transfer-bank')) {
            return false;
        }

        return $request->hasHeader('Authorization', 'Bearer token-abc')
            // The sessionId from the inquiry is mandatory on the transfer
            && $request['sessionId'] === 'sess-123'
            && $request['partnerReferenceNo'] === $disbursement->disbursement_code
            && $request['amount']['value'] === '100000.00'
            && $request['additionalInfo']['beneficiaryFirstName'] === 'Budi'
            && $request['additionalInfo']['beneficiaryLastName'] === 'Santoso'
            && $request['additionalInfo']['beneficiaryPhoneNumber'] === '628121212121'
            // Prefers the name the bank confirmed over the one we were given
            && $request['additionalInfo']['beneficiaryAccountName'] === 'BUDI SANTOSO'
            && $request['additionalInfo']['senderFirstName'] === 'Toko'
            && $request['additionalInfo']['senderPersonalIdType'] === 'KTP';
    });

    $disbursement->refresh();

    expect($disbursement->reference_no)->toBe('doku-ref-1')
        ->and($disbursement->status)->toBe(DisbursementStatus::PROCESSED)
        ->and($disbursement->beneficiary_phone)->toBe('628121212121');
});

it('fails the disbursement and skips the transfer when the account inquiry is rejected', function () {
    dokuSenderConfig();
    dokuFakePayout(
        inquiry: ['responseCode' => '4041601', 'responseMessage' => 'Invalid Account'],
        transfer: ['referenceNo' => 'should-not-be-used'],
        inquiryStatus: 404,
    );

    $disbursement = PaymentModule::createDisbursement(makeDokuDisbursementData());

    Http::assertNotSent(fn (Request $request) => str_contains($request->url(), '/transfer-bank'));

    $disbursement->refresh();

    expect($disbursement->status)->toBe(DisbursementStatus::FAILED)
        ->and($disbursement->error_message)->toBe('Invalid Account')
        ->and($disbursement->reference_no)->toBeNull();
});

it('marks the disbursement failed when the transfer is rejected', function () {
    dokuSenderConfig();
    dokuFakePayout(
        inquiry: ['responseCode' => '2001600', 'additionalInfo' => ['sessionId' => 'sess-123']],
        transfer: ['responseCode' => '4031702', 'responseMessage' => 'Insufficient Funds'],
        transferStatus: 403,
    );

    $disbursement = PaymentModule::createDisbursement(makeDokuDisbursementData());

    $disbursement->refresh();

    expect($disbursement->status)->toBe(DisbursementStatus::FAILED)
        ->and($disbursement->error_message)->toBe('Insufficient Funds')
        ->and($disbursement->error_code)->toBe('4031702');
});

it('does not support approve or reject for kirim doku payouts', function () {
    $processor = new DokuKirim(new SnapClient);

    $disbursement = new Disbursement(['disbursement_code' => 'DISB-1']);

    expect(fn () => $processor->approveDisbursement($disbursement))->toThrow(BadMethodCallException::class)
        ->and(fn () => $processor->rejectDisbursement($disbursement))->toThrow(BadMethodCallException::class);
});

it('completes the disbursement on a successful payout notification', function () {
    dokuCredentials();

    $disbursement = Disbursement::create([
        'disbursement_code' => 'DISB-'.uniqid(),
        'reference_no' => 'doku-ref-2',
        'vendor' => PaymentVendor::Doku,
        'beneficiary_name' => 'Budi',
        'beneficiary_account' => '123',
        'beneficiary_bank' => '014',
        'amount' => 100000,
        'status' => DisbursementStatus::PROCESSED,
    ]);

    dokuPostWebhook('/webhooks/doku/disbursement', [
        'originalReferenceNo' => 'doku-ref-2',
        'originalPartnerReferenceNo' => $disbursement->disbursement_code,
        'latestTransactionStatus' => '00',
        'transactionStatusDesc' => 'Success',
    ])->assertOk();

    $disbursement->refresh();

    expect($disbursement->status)->toBe(DisbursementStatus::COMPLETED)
        ->and($disbursement->completed_at)->not->toBeNull()
        ->and(WebhookCall::count())->toBe(1);
});

it('fails the disbursement on a failed payout notification and records the reason', function () {
    dokuCredentials();

    $disbursement = Disbursement::create([
        'disbursement_code' => 'DISB-'.uniqid(),
        'reference_no' => 'doku-ref-3',
        'vendor' => PaymentVendor::Doku,
        'beneficiary_name' => 'Budi',
        'beneficiary_account' => '123',
        'beneficiary_bank' => '014',
        'amount' => 100000,
        'status' => DisbursementStatus::PROCESSED,
    ]);

    dokuPostWebhook('/webhooks/doku/disbursement', [
        'originalReferenceNo' => 'doku-ref-3',
        'latestTransactionStatus' => '06',
        'transactionStatusDesc' => 'Beneficiary account closed',
    ])->assertOk();

    $disbursement->refresh();

    expect($disbursement->status)->toBe(DisbursementStatus::FAILED)
        ->and($disbursement->error_message)->toBe('Beneficiary account closed');
});

it('rejects doku disbursement notifications with an invalid signature', function () {
    dokuCredentials();

    dokuPostWebhook('/webhooks/doku/disbursement', [
        'originalReferenceNo' => 'doku-ref-2',
        'latestTransactionStatus' => '00',
    ], 'nope')->assertStatus(500);

    expect(WebhookCall::count())->toBe(0);
});
