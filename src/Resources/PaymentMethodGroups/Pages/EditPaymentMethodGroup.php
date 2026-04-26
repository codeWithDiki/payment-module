<?php

namespace CodeWithDiki\PaymentModule\Resources\PaymentMethodGroups\Pages;

use CodeWithDiki\PaymentModule\Resources\PaymentMethodGroups\PaymentMethodGroupResource;
use Filament\Actions\DeleteAction;
use Filament\Actions\ViewAction;
use Filament\Resources\Pages\EditRecord;

class EditPaymentMethodGroup extends EditRecord
{
    protected static string $resource = PaymentMethodGroupResource::class;

    protected function getHeaderActions(): array
    {
        return [
            ViewAction::make(),
            DeleteAction::make(),
        ];
    }
}
