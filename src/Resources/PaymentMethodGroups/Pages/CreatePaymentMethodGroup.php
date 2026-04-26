<?php

namespace CodeWithDiki\PaymentModule\Resources\PaymentMethodGroups\Pages;

use CodeWithDiki\PaymentModule\Resources\PaymentMethodGroups\PaymentMethodGroupResource;
use Filament\Resources\Pages\CreateRecord;

class CreatePaymentMethodGroup extends CreateRecord
{
    protected static string $resource = PaymentMethodGroupResource::class;
}
