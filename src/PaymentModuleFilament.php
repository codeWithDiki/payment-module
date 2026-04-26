<?php

namespace CodeWithDiki\PaymentModule;

use CodeWithDiki\PaymentModule\Resources\PaymentMethodGroups\PaymentMethodGroupResource;
use CodeWithDiki\PaymentModule\Resources\PaymentMethods\PaymentMethodResource;
use CodeWithDiki\PaymentModule\Resources\Payments\PaymentResource;
use Filament\Contracts\Plugin;
use Filament\Panel;

class PaymentModuleFilament implements Plugin
{
    public function getId(): string
    {
        return 'payment-module';
    }

    public function register(Panel $panel): void
    {
        $panel
            ->resources([
                PaymentResource::class,
                PaymentMethodGroupResource::class,
                PaymentMethodResource::class
            ])
            ->pages([

            ]);
    }

    public function boot(Panel $panel): void
    {
        //
    }
}