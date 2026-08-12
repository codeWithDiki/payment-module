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

    /** Doku Config */
    'doku_client_id' => env('DOKU_CLIENT_ID', ''),
    'doku_client_secret' => env('DOKU_CLIENT_SECRET', ''),
    // RSA private key whose public half is registered in the DOKU dashboard, used to sign
    // the B2B access token request. Either an inline PEM or a "file:///path/to/private.key".
    'doku_private_key' => env('DOKU_PRIVATE_KEY', ''),
    'doku_is_production' => env('DOKU_IS_PRODUCTION', false),

    /** Doku QRIS — both are mandatory on qr-mpm-generate and constant per merchant */
    // Alphanumeric only, 3 to 16 characters (no dashes or spaces)
    'doku_qris_terminal_id' => env('DOKU_QRIS_TERMINAL_ID', ''),
    // Postal code of the registered merchant address, numeric, max 5 digits
    'doku_qris_postal_code' => env('DOKU_QRIS_POSTAL_CODE', ''),

    /** Doku Payout (Kirim DOKU) sender identity — constant per merchant, required by transfer-bank */
    'doku_sender_name' => env('DOKU_SENDER_NAME', ''),
    // Sender account number in phone number format (customerNumber)
    'doku_sender_phone' => env('DOKU_SENDER_PHONE', ''),
    'doku_sender_personal_id' => env('DOKU_SENDER_PERSONAL_ID', ''),
    'doku_sender_personal_id_type' => env('DOKU_SENDER_PERSONAL_ID_TYPE', 'KTP'),
    'doku_sender_country_code' => env('DOKU_SENDER_COUNTRY_CODE', 'ID'),

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
