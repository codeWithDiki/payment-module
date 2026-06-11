<?php

namespace CodeWithDiki\PaymentModule\Resources\Disbursements\Pages;

use CodeWithDiki\PaymentModule\Resources\Disbursements\DisbursementResource;
use Filament\Resources\Pages\ListRecords;

class ListDisbursements extends ListRecords
{
    protected static string $resource = DisbursementResource::class;

    protected function getHeaderActions(): array
    {
        return [

        ];
    }
}
