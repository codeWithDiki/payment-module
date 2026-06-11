<?php

namespace CodeWithDiki\PaymentModule\Resources\Disbursements\Pages;

use CodeWithDiki\PaymentModule\Resources\Disbursements\DisbursementResource;
use Filament\Resources\Pages\ViewRecord;

class ViewDisbursement extends ViewRecord
{
    protected static string $resource = DisbursementResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DisbursementResource::getApproveAction(),
            DisbursementResource::getRejectAction(),
        ];
    }
}
