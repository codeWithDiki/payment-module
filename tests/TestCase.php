<?php

namespace CodeWithDiki\PaymentModule\Tests;

use CodeWithDiki\PaymentModule\PaymentModuleServiceProvider;
use Illuminate\Database\Eloquent\Factories\Factory;
use Orchestra\Testbench\TestCase as Orchestra;
use Spatie\WebhookClient\WebhookClientServiceProvider;

class TestCase extends Orchestra
{
    protected function setUp(): void
    {
        parent::setUp();

        Factory::guessFactoryNamesUsing(
            fn (string $modelName) => 'CodeWithDiki\\PaymentModule\\Database\\Factories\\'.class_basename($modelName).'Factory'
        );
    }

    protected function getPackageProviders($app)
    {
        return [
            WebhookClientServiceProvider::class,
            PaymentModuleServiceProvider::class,
        ];
    }

    public function getEnvironmentSetUp($app)
    {
        config()->set('database.default', 'testing');

        (include __DIR__.'/../database/migrations/create_payment_module_table.php.stub')->up();
        (include __DIR__.'/../database/migrations/create_payment_module_disbursements_table.php.stub')->up();
        (include __DIR__.'/../vendor/spatie/laravel-webhook-client/database/migrations/create_webhook_calls_table.php.stub')->up();
        (include __DIR__.'/../vendor/spatie/laravel-webhook-client/database/migrations/add_attachments_to_webhook_calls_table.php.stub')->up();
    }
}
