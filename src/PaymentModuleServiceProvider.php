<?php

namespace CodeWithDiki\PaymentModule;

use CodeWithDiki\PaymentModule\Events\PaymentCreated;
use CodeWithDiki\PaymentModule\Listeners\ProcessingPaymentGateway;
use Illuminate\Support\Facades\Event;
use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;

class PaymentModuleServiceProvider extends PackageServiceProvider
{
    public function configurePackage(Package $package): void
    {
        /*
         * This class is a Package Service Provider
         *
         * More info: https://github.com/spatie/laravel-package-tools
         */

        Event::listen(PaymentCreated::class, ProcessingPaymentGateway::class);

        $package
            ->name('payment-module')
            ->hasConfigFile()
            ->hasViews()
            ->hasMigration('create_payment_module_table');
    }
}
