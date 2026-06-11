<?php

namespace CodeWithDiki\PaymentModule\Data;

use CodeWithDiki\PaymentModule\Enums\PaymentVendor;
use Illuminate\Database\Eloquent\Model;
use Spatie\LaravelData\Data;

class DisbursementData extends Data
{
    public function __construct(
        public PaymentVendor $vendor,
        public string $disbursement_code,
        public float $amount,
        public string $beneficiary_name,
        public string $beneficiary_account,
        public string $beneficiary_bank,
        public ?string $beneficiary_email = null,
        public ?string $notes = null,
        public ?Model $disbursable = null,
    ) {}
}
