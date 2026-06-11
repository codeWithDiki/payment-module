<?php

namespace CodeWithDiki\PaymentModule\Supports\Disbursement\Contracts;

use CodeWithDiki\PaymentModule\Models\Disbursement;

interface DisbursementProcessor
{
    public function processDisbursement(Disbursement $disbursement): void;

    public function approveDisbursement(Disbursement $disbursement): void;

    public function rejectDisbursement(Disbursement $disbursement, ?string $reason = null): void;
}
