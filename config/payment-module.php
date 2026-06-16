<?php

// config for VendorName/Skeleton

use CodeWithDiki\PaymentModule\Enums\PaymentVendor;
use CodeWithDiki\PaymentModule\Events\DisbursementCreated;
use CodeWithDiki\PaymentModule\Events\PaymentCreated;
use CodeWithDiki\PaymentModule\Listeners\ProcessingDisbursementGateway;
use CodeWithDiki\PaymentModule\Listeners\ProcessingPaymentGateway;
use CodeWithDiki\PaymentModule\Models\Disbursement;
use CodeWithDiki\PaymentModule\Models\Payment;
use CodeWithDiki\PaymentModule\Models\PaymentMethod;
use CodeWithDiki\PaymentModule\Models\PaymentMethodGroup;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;

return [
    /** Models Classes */
    'payment_method_class' => PaymentMethod::class,
    'payment_method_group_class' => PaymentMethodGroup::class,
    'payment_class' => Payment::class,
    'disbursement_class' => Disbursement::class,

    /** Midtrans Config */
    'midtrans_server_key' => env('MIDTRANS_SERVER_KEY', ''),
    'midtrans_client_key' => env('MIDTRANS_CLIENT_KEY', ''),
    'midtrans_is_production' => env('MIDTRANS_IS_PRODUCTION', false),
    'midtrans_is_sanitized' => env('MIDTRANS_IS_SANITIZED', true),
    'midtrans_is_3ds' => env('MIDTRANS_IS_3DS', false),

    /** Midtrans Payout (Iris) Config */
    'midtrans_iris_creator_key' => env('MIDTRANS_IRIS_CREATOR_KEY', ''),
    'midtrans_iris_approver_key' => env('MIDTRANS_IRIS_APPROVER_KEY', ''),
    // Iris merchant key, used to verify the Iris-Signature webhook header
    'midtrans_iris_merchant_key' => env('MIDTRANS_IRIS_MERCHANT_KEY', ''),

    /** Stripe Config */
    'stripe_secret_key' => env('STRIPE_SECRET_KEY', ''),
    'stripe_publishable_key' => env('STRIPE_PUBLISHABLE_KEY', ''),
    'stripe_webhook_secret' => env('STRIPE_WEBHOOK_SECRET', ''),
    'stripe_currency' => env('STRIPE_CURRENCY', 'usd'),
    // {payment_code} in these URLs is replaced with the payment's code when the checkout session is created
    'stripe_success_url' => env('STRIPE_SUCCESS_URL', ''),
    'stripe_cancel_url' => env('STRIPE_CANCEL_URL', ''),

    /** Xendit Config */
    'xendit_secret_key' => env('XENDIT_SECRET_KEY', ''),
    // Callback verification token from the Xendit dashboard, sent in the x-callback-token header
    'xendit_webhook_token' => env('XENDIT_WEBHOOK_TOKEN', ''),
    'xendit_is_production' => env('XENDIT_IS_PRODUCTION', false),
    // Redirect URLs for e-wallet checkout; {payment_code} is replaced at charge time
    'xendit_success_redirect_url' => env('XENDIT_SUCCESS_REDIRECT_URL', ''),
    'xendit_failure_redirect_url' => env('XENDIT_FAILURE_REDIRECT_URL', ''),

    'vendor_enum_class' => PaymentVendor::class,

    'webhook' => [
        'prefix' => 'webhooks',
        'without_middleware' => [VerifyCsrfToken::class],
    ],

    'listeners' => [
        PaymentCreated::class => [
            ProcessingPaymentGateway::class,
        ],
        DisbursementCreated::class => [
            ProcessingDisbursementGateway::class,
        ],
    ],
];
