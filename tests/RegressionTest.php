<?php

use CodeWithDiki\PaymentModule\Data\PaymentData;
use CodeWithDiki\PaymentModule\Enums\DisbursementStatus;
use CodeWithDiki\PaymentModule\Enums\PaymentStatus;
use CodeWithDiki\PaymentModule\Enums\PaymentVendor;
use CodeWithDiki\PaymentModule\Events\PaymentPaid;
use CodeWithDiki\PaymentModule\Facades\PaymentModule;
use CodeWithDiki\PaymentModule\Models\Disbursement;
use CodeWithDiki\PaymentModule\Models\Payment;
use CodeWithDiki\PaymentModule\Models\PaymentMethod;
use CodeWithDiki\PaymentModule\Models\PaymentMethodGroup;
use Illuminate\Auth\GenericUser;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;

it('returns the active payment methods of a group', function () {
    $group = PaymentMethodGroup::create(['name' => 'VA', 'slug' => 'va-'.uniqid(), 'is_active' => true]);

    PaymentMethod::create([
        'payment_method_group_id' => $group->id,
        'name' => 'BCA', 'vendor' => PaymentVendor::Offline, 'channel' => 'offline', 'is_active' => true,
    ]);
    PaymentMethod::create([
        'payment_method_group_id' => $group->id,
        'name' => 'BNI (off)', 'vendor' => PaymentVendor::Offline, 'channel' => 'offline', 'is_active' => false,
    ]);

    expect(PaymentModule::getPaymentMethodsByGroupId($group->id))->toHaveCount(1)
        ->and(PaymentModule::getPaymentMethodsByGroupId(999999))->toHaveCount(0);
});

it('fails the payment when the xendit charge is rejected', function () {
    Http::fake(['https://api.xendit.co/*' => Http::response(['error_code' => 'INVALID', 'message' => 'nope'], 400)]);
    config()->set('payment-module.xendit_secret_key', 'k');

    $method = PaymentMethod::create([
        'name' => 'BCA VA', 'vendor' => PaymentVendor::Xendit, 'channel' => 'BCA', 'is_active' => true,
    ]);
    $group = PaymentMethodGroup::create(['name' => 'Order', 'slug' => 'o-'.uniqid(), 'is_active' => true]);

    $payment = PaymentModule::createPayment(new PaymentData(
        paymentable: $group,
        payment_method_id: $method->id,
        payment_code: 'INV-'.uniqid(),
        amount: 1000,
        status: PaymentStatus::PENDING,
    ));

    expect($payment->fresh()->status)->toBe(PaymentStatus::FAILED)
        ->and($payment->fresh()->payment_response['error_code'])->toBe('INVALID');
});

it('keeps idr fees whole but allows minor units on decimal stripe currencies', function () {
    $idr = PaymentMethod::create([
        'name' => 'GoPay', 'vendor' => PaymentVendor::Midtrans, 'channel' => 'gopay',
        'is_active' => true, 'fee_flat' => 0, 'fee_percentage' => 2.9,
    ]);
    $card = PaymentMethod::create([
        'name' => 'Card', 'vendor' => PaymentVendor::Stripe, 'channel' => 'card',
        'is_active' => true, 'fee_flat' => 0, 'fee_percentage' => 2.9,
    ]);

    // 2.9% of 10001 IDR = 290.029 -> whole rupiah, Midtrans rejects fractions
    expect($idr->calculateFee(10001))->toBe(290.0);

    // 2.9% of $10 = $0.29, which used to round away to zero
    config()->set('payment-module.stripe_currency', 'usd');
    expect($card->calculateFee(10))->toBe(0.29);

    // JPY is zero-decimal, so it behaves like IDR
    config()->set('payment-module.stripe_currency', 'jpy');
    expect($card->calculateFee(10))->toBe(0.0);
});

it('records the approver who rejected a payout', function () {
    config()->set('payment-module.midtrans_iris_approver_key', 'approver-key');
    Http::fake(['https://app.sandbox.midtrans.com/iris/api/v1/payouts/reject' => Http::response(['status' => 'ok'])]);

    $disbursement = Disbursement::create([
        'disbursement_code' => 'DISB-'.uniqid(), 'reference_no' => 'REF-9',
        'vendor' => PaymentVendor::Midtrans, 'beneficiary_name' => 'Budi',
        'beneficiary_account' => '123', 'beneficiary_bank' => 'bca', 'amount' => 100000,
        'status' => DisbursementStatus::QUEUED, 'created_by' => 5,
    ]);

    $this->actingAs(new GenericUser(['id' => 9]));

    PaymentModule::rejectDisbursement($disbursement, 'invalid account');

    expect($disbursement->fresh()->status)->toBe(DisbursementStatus::REJECTED)
        ->and($disbursement->fresh()->approved_by)->toBe('9');
});

it('does not dispatch twice when a concurrent transition already settled the payment', function () {
    Event::fake([PaymentPaid::class]);

    $method = PaymentMethod::create([
        'name' => 'GoPay', 'vendor' => PaymentVendor::Midtrans, 'channel' => 'gopay', 'is_active' => true,
    ]);

    $payment = Payment::create([
        'paymentable_type' => 'App\\Models\\Order', 'paymentable_id' => 1,
        'payment_method_id' => $method->id, 'payment_code' => 'INV-'.uniqid(),
        'amount' => 1000, 'fee' => 0, 'total_amount' => 1000, 'status' => PaymentStatus::PENDING,
    ]);

    // A parallel worker settles the row while this instance still holds a PENDING snapshot
    Payment::whereKey($payment->id)->update(['status' => PaymentStatus::PAID]);

    PaymentModule::setPaymentStatus($payment, PaymentStatus::PAID);

    Event::assertNotDispatched(PaymentPaid::class);
    expect($payment->status)->toBe(PaymentStatus::PAID);
});
