<?php

namespace CodeWithDiki\PaymentModule\Resources\Payments\Pages;

use CodeWithDiki\PaymentModule\Resources\Payments\PaymentResource;
use Filament\Resources\Pages\ViewRecord;

class ViewPayment extends ViewRecord
{
    protected static string $resource = PaymentResource::class;

    protected function getHeaderActions(): array
    {
        return [

        ];
    }
}
