<?php

namespace CodeWithDiki\PaymentModule\Resources\PaymentMethodGroups\Pages;

use CodeWithDiki\PaymentModule\Resources\PaymentMethodGroups\PaymentMethodGroupResource;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;

class ViewPaymentMethodGroup extends ViewRecord
{
    protected static string $resource = PaymentMethodGroupResource::class;

    protected function getHeaderActions(): array
    {
        return [
            EditAction::make(),
        ];
    }
}
