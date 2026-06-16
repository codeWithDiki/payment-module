<?php

namespace CodeWithDiki\PaymentModule;

use CodeWithDiki\PaymentModule\Events\DisbursementCreated;
use CodeWithDiki\PaymentModule\Events\PaymentCreated;
use CodeWithDiki\PaymentModule\Listeners\ProcessingDisbursementGateway;
use CodeWithDiki\PaymentModule\Listeners\ProcessingPaymentGateway;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Route;
use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;
use Spatie\WebhookClient\Models\WebhookCall;
use Spatie\WebhookClient\WebhookProfile\ProcessEverythingWebhookProfile;
use Spatie\WebhookClient\WebhookResponse\DefaultRespondsTo;

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
            ->hasMigrations([
                'create_payment_module_table',
                'create_payment_module_disbursements_table',
            ]);
    }

    public function bootingPackage()
    {
        $listeners = config('payment-module.listeners', [
            PaymentCreated::class => [
                ProcessingPaymentGateway::class,
            ],
            DisbursementCreated::class => [
                ProcessingDisbursementGateway::class,
            ],
        ]);

        foreach ($listeners as $event => $eventListeners) {
            foreach ($eventListeners as $listener) {
                Event::listen($event, $listener);
            }
        }

        $this->registerWebhookClientConfigs();

        Route::prefix(config('payment-module.webhook.prefix', 'webhooks'))
            ->withoutMiddleware(config('payment-module.webhook.without_middleware', [VerifyCsrfToken::class]))
            ->group(function () {
                Route::webhooks('midtrans', 'payment-module-midtrans');
                Route::webhooks('midtrans/payout', 'payment-module-midtrans-payout');
                Route::webhooks('stripe', 'payment-module-stripe');
                Route::webhooks('xendit', 'payment-module-xendit');
                Route::webhooks('xendit/disbursement', 'payment-module-xendit-disbursement');
            });
    }

    protected function registerWebhookClientConfigs(): void
    {
        $defaults = [
            // Signing secrets live in the payment-module config; each validator reads
            // them at runtime, so signing_secret is intentionally left empty here
            'signing_secret' => '',
            'webhook_profile' => ProcessEverythingWebhookProfile::class,
            'webhook_response' => DefaultRespondsTo::class,
            'webhook_model' => WebhookCall::class,
            'store_headers' => [],
            'store_attachments' => false,
        ];

        // The unpublished webhook-client config ships a default profile without a
        // process_webhook_job; such profiles make the config repository throw, so drop them
        $existingConfigs = collect(config('webhook-client.configs', []))
            ->filter(fn (array $config) => ! empty($config['process_webhook_job']))
            ->values()
            ->all();

        config()->set('webhook-client.configs', array_merge(
            $existingConfigs,
            [
                array_merge($defaults, [
                    'name' => 'payment-module-midtrans',
                    // Midtrans sends its signature in the body, the validator ignores this header
                    'signature_header_name' => 'Signature',
                    'signature_validator' => Webhooks\SignatureValidators\MidtransSignatureValidator::class,
                    'process_webhook_job' => Webhooks\Jobs\ProcessMidtransWebhookJob::class,
                ]),
                array_merge($defaults, [
                    'name' => 'payment-module-midtrans-payout',
                    'signature_header_name' => 'Iris-Signature',
                    'signature_validator' => Webhooks\SignatureValidators\MidtransPayoutSignatureValidator::class,
                    'process_webhook_job' => Webhooks\Jobs\ProcessMidtransPayoutWebhookJob::class,
                ]),
                array_merge($defaults, [
                    'name' => 'payment-module-stripe',
                    'signature_header_name' => 'Stripe-Signature',
                    'signature_validator' => Webhooks\SignatureValidators\StripeSignatureValidator::class,
                    'process_webhook_job' => Webhooks\Jobs\ProcessStripeWebhookJob::class,
                ]),
                array_merge($defaults, [
                    'name' => 'payment-module-xendit',
                    'signature_header_name' => 'x-callback-token',
                    'signature_validator' => Webhooks\SignatureValidators\XenditSignatureValidator::class,
                    'process_webhook_job' => Webhooks\Jobs\ProcessXenditWebhookJob::class,
                ]),
                array_merge($defaults, [
                    'name' => 'payment-module-xendit-disbursement',
                    'signature_header_name' => 'x-callback-token',
                    'signature_validator' => Webhooks\SignatureValidators\XenditSignatureValidator::class,
                    'process_webhook_job' => Webhooks\Jobs\ProcessXenditDisbursementWebhookJob::class,
                ]),
            ]
        ));
    }
}
