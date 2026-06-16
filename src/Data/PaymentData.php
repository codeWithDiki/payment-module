<?php

namespace CodeWithDiki\PaymentModule\Data;

use CodeWithDiki\PaymentModule\Enums\PaymentStatus;
use Illuminate\Database\Eloquent\Model;
use Spatie\LaravelData\Data;

class PaymentData extends Data
{
    public function __construct(
        public Model $paymentable,
        public int $payment_method_id,
        public string $payment_code,
        public int $amount,
        public PaymentStatus $status,
        public ?string $customer_name = null,
        public ?string $customer_email = null,
        public ?string $customer_phone = null,
        public ?string $customer_address = null,
        public ?array $customer_custom_data = null,
        public ?string $payment_headers = null,
        public ?string $payment_payload = null,
        public ?string $payment_response = null,
    ) {}
}
