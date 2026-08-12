<?php

namespace CodeWithDiki\PaymentModule\Data;

use CodeWithDiki\PaymentModule\Enums\PaymentInstructionType;
use Spatie\LaravelData\Data;

/**
 * One shape for every gateway's charge response, so a client renders a payment by its
 * type instead of by its vendor. Each processor fills only the fields its type uses:
 *
 * - Qr             -> qr_string (render client side) and/or qr_url (image)
 * - EWallet        -> redirect_url
 * - VirtualAccount -> virtual_account_number
 */
class PaymentInstruction extends Data
{
    public function __construct(
        public PaymentInstructionType $type,
        // Vendor as its backed value, not the enum: the enum class is swappable via
        // payment-module.vendor_enum_class, so a custom one still fits here.
        public string $vendor,
        public string $channel,
        public float $amount,
        public ?string $qr_string = null,
        public ?string $qr_url = null,
        public ?string $redirect_url = null,
        public ?string $virtual_account_number = null,
    ) {}
}
