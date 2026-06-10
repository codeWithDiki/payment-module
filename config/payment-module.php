<?php

// config for VendorName/Skeleton

use CodeWithDiki\PaymentModule\Enums\PaymentVendor;
use CodeWithDiki\PaymentModule\Events\PaymentCreated;
use CodeWithDiki\PaymentModule\Listeners\ProcessingPaymentGateway;
use CodeWithDiki\PaymentModule\Models\Payment;
use CodeWithDiki\PaymentModule\Models\PaymentMethod;
use CodeWithDiki\PaymentModule\Models\PaymentMethodGroup;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;

return [
    /** Models Classes */
    'payment_method_class' => PaymentMethod::class,
    'payment_method_group_class' => PaymentMethodGroup::class,
    'payment_class' => Payment::class,

    /** Midtrans Config */
    'midtrans_server_key' => env('MIDTRANS_SERVER_KEY', ''),
    'midtrans_client_key' => env('MIDTRANS_CLIENT_KEY', ''),
    'midtrans_is_production' => env('MIDTRANS_IS_PRODUCTION', false),
    'midtrans_is_sanitized' => env('MIDTRANS_IS_SANITIZED', true),
    'midtrans_is_3ds' => env('MIDTRANS_IS_3DS', false),

    /** Stripe Config */
    'stripe_secret_key' => env('STRIPE_SECRET_KEY', ''),
    'stripe_publishable_key' => env('STRIPE_PUBLISHABLE_KEY', ''),
    'stripe_webhook_secret' => env('STRIPE_WEBHOOK_SECRET', ''),
    'stripe_currency' => env('STRIPE_CURRENCY', 'usd'),
    // {payment_code} in these URLs is replaced with the payment's code when the checkout session is created
    'stripe_success_url' => env('STRIPE_SUCCESS_URL', ''),
    'stripe_cancel_url' => env('STRIPE_CANCEL_URL', ''),

    'vendor_enum_class' => PaymentVendor::class,

    'webhook' => [
        'prefix' => 'webhooks',
        'without_middleware' => [VerifyCsrfToken::class],
    ],

    'listeners' => [
        PaymentCreated::class => [
            ProcessingPaymentGateway::class,
        ],
    ],
];
