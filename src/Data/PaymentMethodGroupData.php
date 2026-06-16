<?php

namespace CodeWithDiki\PaymentModule\Data;

class PaymentMethodGroupData
{
    public function __construct(
        public string $name = '',
        public string $slug = '',
        public bool $is_active = false,
        public ?string $image_url = null,
    ) {}
}
