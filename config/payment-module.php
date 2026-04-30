<?php

// config for VendorName/Skeleton

use CodeWithDiki\PaymentModule\Events\PaymentCreated;

return [
    /** Models Classes */
    "payment_method_class" => \CodeWithDiki\PaymentModule\Models\PaymentMethod::class,
    "payment_method_group_class" => \CodeWithDiki\PaymentModule\Models\PaymentMethodGroup::class,
    "payment_class" => \CodeWithDiki\PaymentModule\Models\Payment::class,


    /** Midtrans Config */
    "midtrans_server_key" => env("MIDTRANS_SERVER_KEY", ""),
    "midtrans_client_key" => env("MIDTRANS_CLIENT_KEY", ""),
    "midtrans_is_production" => env("MIDTRANS_IS_PRODUCTION", false),
    "midtrans_is_sanitized" => env("MIDTRANS_IS_SANITIZED", true),
    "midtrans_is_3ds" => env("MIDTRANS_IS_3DS", false),

    "vendor_enum_class" => \CodeWithDiki\PaymentModule\Enums\PaymentVendor::class,

    "webhook" => [
        "prefix" => "webhooks",
        "without_middleware" => [\Illuminate\Foundation\Http\Middleware\VerifyCsrfToken::class]
    ],

    "listeners" => [
        PaymentCreated::class => [
            \CodeWithDiki\PaymentModule\Listeners\ProcessingPaymentGateway::class
        ]
    ]
];
