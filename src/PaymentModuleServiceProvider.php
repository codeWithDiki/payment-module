<?php

namespace CodeWithDiki\PaymentModule;

use CodeWithDiki\PaymentModule\Events\PaymentCreated;
use CodeWithDiki\PaymentModule\Listeners\ProcessingPaymentGateway;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Route;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
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

        $package
            ->name('payment-module')
            ->hasConfigFile()
            ->hasViews()
            ->hasMigration('create_payment_module_table');
    }

    public function bootingPackage()
    {
        $listeners = config('payment-module.listeners', [
            PaymentCreated::class => [
                ProcessingPaymentGateway::class
            ]
        ]);

        foreach($listeners as $event => $eventListeners)
        {
            foreach($eventListeners as $listener)
            {
                Event::listen($event, $listener);
            }
        }

        Route::prefix(config('payment-module.webhook.prefix', 'webhooks'))
            ->withoutMiddleware(config('payment-module.webhook.without_middleware', [VerifyCsrfToken::class]))
            ->group(function () {
                Route::post('midtrans', [Controllers\WebhookController::class, 'midtrans']);
            });
    }
    
}
