<?php

use CodeWithDiki\PaymentModule\Enums\PaymentInstructionType;
use CodeWithDiki\PaymentModule\Enums\PaymentStatus;
use CodeWithDiki\PaymentModule\Enums\PaymentVendor;
use CodeWithDiki\PaymentModule\Models\Payment;
use CodeWithDiki\PaymentModule\Models\PaymentMethod;

function instructionPayment(PaymentVendor $vendor, string $channel, array $response): Payment
{
    $method = PaymentMethod::create([
        'name' => $vendor->value.' '.$channel,
        'vendor' => $vendor,
        'channel' => $channel,
        'is_active' => true,
    ]);

    return Payment::create([
        'paymentable_type' => 'App\\Models\\Order',
        'paymentable_id' => 1,
        'payment_method_id' => $method->id,
        'payment_code' => 'INV-'.uniqid(),
        'amount' => 100000,
        'total_amount' => 104400,
        'status' => PaymentStatus::PENDING,
        'payment_response' => $response,
    ]);
}

it('reads a virtual account number from any gateway through one shape', function (PaymentVendor $vendor, string $channel, array $response) {
    $instruction = instructionPayment($vendor, $channel, $response)->instruction();

    expect($instruction->type)->toBe(PaymentInstructionType::VirtualAccount)
        ->and($instruction->vendor)->toBe($vendor->value)
        ->and($instruction->channel)->toBe($channel)
        ->and($instruction->amount)->toBe(104400.0)
        ->and($instruction->virtual_account_number)->toBe('8808123');
})->with([
    [PaymentVendor::Doku, 'VIRTUAL_ACCOUNT_BCA', ['virtualAccountData' => ['virtualAccountNo' => '8808123']]],
    [PaymentVendor::Xendit, 'BCA', ['account_number' => '8808123']],
    [PaymentVendor::Midtrans, 'bca', ['status_code' => '201', 'va_numbers' => [['bank' => 'bca', 'va_number' => '8808123']]]],
    [PaymentVendor::Midtrans, 'permata', ['status_code' => '201', 'permata_va_number' => '8808123']],
]);

it('reads a qr payload from any gateway through one shape', function (PaymentVendor $vendor, string $channel, array $response, ?string $string, ?string $url) {
    $instruction = instructionPayment($vendor, $channel, $response)->instruction();

    expect($instruction->type)->toBe(PaymentInstructionType::Qr)
        ->and($instruction->qr_string)->toBe($string)
        ->and($instruction->qr_url)->toBe($url);
})->with([
    // DOKU and Xendit return the EMV string; Midtrans returns an image URL
    [PaymentVendor::Doku, 'QRIS', ['qrContent' => '00020101'], '00020101', null],
    [PaymentVendor::Xendit, 'QRIS', ['qr_string' => '00020101'], '00020101', null],
    [PaymentVendor::Midtrans, 'qris', ['status_code' => '201', 'actions' => [['name' => 'generate-qr-code', 'url' => 'https://api.midtrans.com/qr']]], null, 'https://api.midtrans.com/qr'],
]);

it('reads a redirect url from any gateway through one shape', function (PaymentVendor $vendor, string $channel, array $response) {
    $instruction = instructionPayment($vendor, $channel, $response)->instruction();

    expect($instruction->type)->toBe(PaymentInstructionType::EWallet)
        ->and($instruction->redirect_url)->toBe('https://checkout.test/pay');
})->with([
    [PaymentVendor::Doku, 'EMONEY_DANA_SNAP', ['webRedirectUrl' => 'https://checkout.test/pay']],
    [PaymentVendor::Xendit, 'ID_SHOPEEPAY', ['actions' => ['desktop_web_checkout_url' => 'https://checkout.test/pay']]],
    [PaymentVendor::Midtrans, 'gopay', ['status_code' => '201', 'actions' => [['name' => 'deeplink-redirect', 'url' => 'https://checkout.test/pay']]]],
    [PaymentVendor::Stripe, 'card', ['url' => 'https://checkout.test/pay']],
]);

it('has no instruction for an offline channel', function () {
    expect(instructionPayment(PaymentVendor::Offline, 'bank_transfer', [])->instruction())->toBeNull();
});

it('has no instruction for a rejected midtrans charge', function () {
    expect(instructionPayment(PaymentVendor::Midtrans, 'bca', ['status_code' => '402'])->instruction())->toBeNull();
});

it('keeps the deprecated per-vendor accessors scoped to their own vendor', function () {
    $doku = instructionPayment(PaymentVendor::Doku, 'QRIS', ['qrContent' => '00020101']);

    expect($doku->getDokuQrString())->toBe('00020101')
        ->and($doku->getMidtransVirtualAccountNumber())->toBeNull()
        ->and($doku->getStripeCheckoutUrl())->toBeNull();
});
