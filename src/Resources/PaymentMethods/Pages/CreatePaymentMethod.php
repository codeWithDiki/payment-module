<?php

namespace CodeWithDiki\PaymentModule\Resources\PaymentMethods\Pages;

use CodeWithDiki\PaymentModule\Resources\PaymentMethods\PaymentMethodResource;
use Filament\Resources\Pages\CreateRecord;

class CreatePaymentMethod extends CreateRecord
{
    protected static string $resource = PaymentMethodResource::class;
}
