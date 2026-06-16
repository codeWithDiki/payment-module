<?php

namespace CodeWithDiki\PaymentModule\Data;

use CodeWithDiki\PaymentModule\Enums\PaymentVendor;
use Spatie\LaravelData\Data;

class PaymentMethodData extends Data
{
    public function __construct(
        public string $name,
        public PaymentVendor $vendor,
        public string $channel,
        public bool $is_active = false,
        public ?string $image_url = null,
        public ?string $description = null,
        public ?string $meta_data = null,
        public float $fee_flat = 0,
        public float $fee_percentage = 0,
    ) {}
}
