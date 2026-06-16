<?php

use CodeWithDiki\PaymentModule\Enums\DisbursementStatus;
use CodeWithDiki\PaymentModule\Enums\PaymentStatus;
use CodeWithDiki\PaymentModule\Enums\PaymentVendor;
use CodeWithDiki\PaymentModule\Events\PaymentPaid;
use CodeWithDiki\PaymentModule\Exceptions\DisbursementApprovalDeniedException;
use CodeWithDiki\PaymentModule\Facades\PaymentModule;
use CodeWithDiki\PaymentModule\Models\Disbursement;
use CodeWithDiki\PaymentModule\Models\Payment;
use CodeWithDiki\PaymentModule\Models\PaymentMethod;
use Illuminate\Auth\GenericUser;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Spatie\WebhookClient\Models\WebhookCall;

function securityMidtransPayment(float $amount, float $totalAmount): Payment
{
    $method = PaymentMethod::create([
        'name' => 'GoPay',
        'vendor' => PaymentVendor::Midtrans,
        'channel' => 'gopay',
        'is_active' => true,
    ]);

    return Payment::create([
        'paymentable_type' => 'App\\Models\\Order',
        'paymentable_id' => 1,
        'payment_method_id' => $method->id,
        'payment_code' => 'INV-'.uniqid(),
        'amount' => $amount,
        'fee' => $totalAmount - $amount,
        'total_amount' => $totalAmount,
        'status' => PaymentStatus::PENDING,
    ]);
}

function midtransSignedPayload(Payment $payment, string $grossAmount, string $serverKey, string $status = 'settlement'): array
{
    $statusCode = '200';

    return [
        'order_id' => $payment->payment_code,
        'status_code' => $statusCode,
        'gross_amount' => $grossAmount,
        'transaction_status' => $status,
        'signature_key' => hash('sha512', $payment->payment_code.$statusCode.$grossAmount.$serverKey),
    ];
}

// A1 - empty secret
it('rejects a midtrans webhook when the server key is not configured', function () {
    config()->set('payment-module.midtrans_server_key', '');

    $payment = securityMidtransPayment(150000, 150000);

    // Signature crafted with the empty key would pass a naive validator
    $payload = midtransSignedPayload($payment, '150000.00', '');

    $this->postJson('/webhooks/midtrans', $payload)->assertStatus(500);

    expect($payment->fresh()->status)->toBe(PaymentStatus::PENDING)
        ->and(WebhookCall::count())->toBe(0);
});

// A2 - amount verification
it('ignores a midtrans paid webhook when the amount does not match the total', function () {
    config()->set('payment-module.midtrans_server_key', 'server-key');

    $payment = securityMidtransPayment(150000, 154400);

    // Gateway reports only the base amount, not amount + fee
    $payload = midtransSignedPayload($payment, '150000.00', 'server-key');

    $this->postJson('/webhooks/midtrans', $payload)->assertOk();

    expect($payment->fresh()->status)->toBe(PaymentStatus::PENDING);
});

it('marks the payment paid when the midtrans amount matches the total', function () {
    config()->set('payment-module.midtrans_server_key', 'server-key');

    $payment = securityMidtransPayment(150000, 154400);

    $payload = midtransSignedPayload($payment, '154400.00', 'server-key');

    $this->postJson('/webhooks/midtrans', $payload)->assertOk();

    expect($payment->fresh()->status)->toBe(PaymentStatus::PAID);
});

// A3 - idempotency
it('does not re-dispatch events for a payment already in a terminal status', function () {
    Event::fake([PaymentPaid::class]);

    $payment = securityMidtransPayment(1000, 1000);
    $payment->update(['status' => PaymentStatus::PAID]);

    PaymentModule::setPaymentStatus($payment, PaymentStatus::PAID);

    Event::assertNotDispatched(PaymentPaid::class);
});

// A4 - maker-approver separation of duties
it('prevents the maker from approving their own disbursement', function () {
    $disbursement = Disbursement::create([
        'disbursement_code' => 'DISB-'.uniqid(),
        'reference_no' => 'REF-1',
        'vendor' => PaymentVendor::Midtrans,
        'beneficiary_name' => 'Budi',
        'beneficiary_account' => '123',
        'beneficiary_bank' => 'bca',
        'amount' => 100000,
        'status' => DisbursementStatus::QUEUED,
        'created_by' => 5,
    ]);

    $this->actingAs(new GenericUser(['id' => 5]));

    expect(fn () => PaymentModule::approveDisbursement($disbursement))
        ->toThrow(DisbursementApprovalDeniedException::class);
});

it('allows a different approver to approve the disbursement', function () {
    config()->set('payment-module.midtrans_iris_approver_key', 'approver-key');

    Http::fake([
        'https://app.sandbox.midtrans.com/iris/api/v1/payouts/approve' => Http::response(['status' => 'ok']),
    ]);

    $disbursement = Disbursement::create([
        'disbursement_code' => 'DISB-'.uniqid(),
        'reference_no' => 'REF-2',
        'vendor' => PaymentVendor::Midtrans,
        'beneficiary_name' => 'Budi',
        'beneficiary_account' => '123',
        'beneficiary_bank' => 'bca',
        'amount' => 100000,
        'status' => DisbursementStatus::QUEUED,
        'created_by' => 5,
    ]);

    $this->actingAs(new GenericUser(['id' => 9]));

    PaymentModule::approveDisbursement($disbursement);

    expect($disbursement->fresh()->status)->toBe(DisbursementStatus::APPROVED)
        ->and($disbursement->fresh()->approved_by)->toBe('9');
});
