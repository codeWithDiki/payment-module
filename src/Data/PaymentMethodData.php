<?php

namespace CodeWithDiki\PaymentModule\Data;

use CodeWithDiki\PaymentModule\Enums\PaymentVendor;

class PaymentMethodData extends \Spatie\LaravelData\Data
{
    public function __construct(
        public string $name,
        public PaymentVendor $vendor,
        public string $channel,
        public bool $is_active = false,
        public ?string $image_url = null,
        public ?string $description = null,
        public ?string $meta_data = null,
    ) {
    }
}