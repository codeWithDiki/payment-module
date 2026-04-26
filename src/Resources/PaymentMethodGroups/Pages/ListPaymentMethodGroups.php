<?php

namespace CodeWithDiki\PaymentModule\Resources\PaymentMethodGroups\Pages;

use CodeWithDiki\PaymentModule\Resources\PaymentMethodGroups\PaymentMethodGroupResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListPaymentMethodGroups extends ListRecords
{
    protected static string $resource = PaymentMethodGroupResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
