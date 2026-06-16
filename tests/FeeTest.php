<?php

use CodeWithDiki\PaymentModule\Data\PaymentData;
use CodeWithDiki\PaymentModule\Enums\PaymentStatus;
use CodeWithDiki\PaymentModule\Enums\PaymentVendor;
use CodeWithDiki\PaymentModule\Facades\PaymentModule;
use CodeWithDiki\PaymentModule\Models\PaymentMethod;
use CodeWithDiki\PaymentModule\Models\PaymentMethodGroup;

it('computes a flat plus percentage fee on the payment method', function () {
    $method = PaymentMethod::create([
        'name' => 'BCA VA',
        'vendor' => PaymentVendor::Offline,
        'channel' => 'offline',
        'is_active' => true,
        'fee_flat' => 2500,
        'fee_percentage' => 1.5,
    ]);

    // 2500 flat + 1.5% of 100000 (1500) = 4000
    expect($method->calculateFee(100000))->toBe(4000.0);
});

it('adds the payment method fee to the payment and bills the total', function () {
    $method = PaymentMethod::create([
        'name' => 'BCA VA',
        'vendor' => PaymentVendor::Offline,
        'channel' => 'offline',
        'is_active' => true,
        'fee_flat' => 4400,
        'fee_percentage' => 0,
    ]);

    $paymentable = PaymentMethodGroup::create([
        'name' => 'Order',
        'slug' => 'order-'.uniqid(),
        'is_active' => true,
    ]);

    $payment = PaymentModule::createPayment(new PaymentData(
        paymentable: $paymentable,
        payment_method_id: $method->id,
        payment_code: 'INV-'.uniqid(),
        amount: 100000,
        status: PaymentStatus::PENDING,
    ));

    expect($payment->fee)->toBe(4400.0)
        ->and($payment->total_amount)->toBe(104400.0)
        ->and($payment->amount)->toBe(100000.0)
        ->and($payment->billableAmount())->toBe(104400.0);
});

it('bills only the base amount when no fee is configured', function () {
    $method = PaymentMethod::create([
        'name' => 'Offline',
        'vendor' => PaymentVendor::Offline,
        'channel' => 'offline',
        'is_active' => true,
    ]);

    $paymentable = PaymentMethodGroup::create([
        'name' => 'Order',
        'slug' => 'order-'.uniqid(),
        'is_active' => true,
    ]);

    $payment = PaymentModule::createPayment(new PaymentData(
        paymentable: $paymentable,
        payment_method_id: $method->id,
        payment_code: 'INV-'.uniqid(),
        amount: 50000,
        status: PaymentStatus::PENDING,
    ));

    expect($payment->fee)->toBe(0.0)
        ->and($payment->total_amount)->toBe(50000.0);
});
