<?php

namespace CodeWithDiki\PaymentModule\Resources\PaymentMethods\Pages;

use CodeWithDiki\PaymentModule\Resources\PaymentMethods\PaymentMethodResource;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;

class ViewPaymentMethod extends ViewRecord
{
    protected static string $resource = PaymentMethodResource::class;

    protected function getHeaderActions(): array
    {
        return [
            EditAction::make(),
        ];
    }
}
