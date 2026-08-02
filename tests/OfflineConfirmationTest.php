<?php

use CodeWithDiki\PaymentModule\Data\PaymentData;
use CodeWithDiki\PaymentModule\Enums\PaymentStatus;
use CodeWithDiki\PaymentModule\Enums\PaymentVendor;
use CodeWithDiki\PaymentModule\Events\PaymentPaid;
use CodeWithDiki\PaymentModule\Facades\PaymentModule;
use CodeWithDiki\PaymentModule\Models\Payment;
use CodeWithDiki\PaymentModule\Models\PaymentMethod;
use CodeWithDiki\PaymentModule\Models\PaymentMethodGroup;
use Illuminate\Support\Facades\Event;

function offlineMethod(PaymentVendor $vendor = PaymentVendor::Offline, string $channel = 'bank_transfer'): PaymentMethod
{
    return PaymentMethod::create([
        'name' => 'Manual '.$channel,
        'vendor' => $vendor,
        'channel' => $channel,
        'is_active' => true,
    ]);
}

function offlinePayment(PaymentVendor $vendor = PaymentVendor::Offline): Payment
{
    return PaymentModule::createPayment(new PaymentData(
        paymentable: PaymentMethodGroup::create([
            'name' => 'Order',
            'slug' => 'order-'.uniqid(),
            'is_active' => true,
        ]),
        payment_method_id: offlineMethod($vendor)->id,
        payment_code: 'INV-'.uniqid(),
        amount: 100000,
        status: PaymentStatus::PENDING,
    ));
}

it('leaves an offline payment pending instead of confirming it automatically', function () {
    Event::fake([PaymentPaid::class]);

    $payment = offlinePayment();

    expect($payment->fresh()->status)->toBe(PaymentStatus::PENDING)
        ->and($payment->fresh()->paid_at)->toBeNull();

    Event::assertNotDispatched(PaymentPaid::class);
});

it('allows a pending offline payment to be confirmed by hand', function () {
    expect(offlinePayment()->canBeConfirmedManually())->toBeTrue();
});

it('does not allow a gateway payment to be confirmed by hand', function () {
    $payment = Payment::create([
        'paymentable_type' => 'App\\Models\\Order',
        'paymentable_id' => 1,
        'payment_method_id' => offlineMethod(PaymentVendor::Midtrans, 'gopay')->id,
        'payment_code' => 'INV-'.uniqid(),
        'amount' => 100000,
        'total_amount' => 100000,
        'status' => PaymentStatus::PENDING,
    ]);

    expect($payment->canBeConfirmedManually())->toBeFalse();
});

it('does not allow an already settled payment to be confirmed again', function () {
    $payment = offlinePayment();

    PaymentModule::setPaymentStatus($payment, PaymentStatus::PAID);

    expect($payment->fresh()->canBeConfirmedManually())->toBeFalse();
});

it('records paid_at and dispatches PaymentPaid when a payment is confirmed', function () {
    Event::fake([PaymentPaid::class]);

    $payment = offlinePayment();

    PaymentModule::setPaymentStatus($payment, PaymentStatus::PAID);

    expect($payment->fresh()->status)->toBe(PaymentStatus::PAID)
        ->and($payment->fresh()->paid_at)->not->toBeNull();

    Event::assertDispatched(PaymentPaid::class);
});

it('does not stamp paid_at when a payment fails', function () {
    $payment = offlinePayment();

    PaymentModule::setPaymentStatus($payment, PaymentStatus::FAILED);

    expect($payment->fresh()->status)->toBe(PaymentStatus::FAILED)
        ->and($payment->fresh()->paid_at)->toBeNull();
});
