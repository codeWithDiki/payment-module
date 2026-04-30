<?php

namespace CodeWithDiki\PaymentModule\Resources\PaymentMethods\Schemas;

use CodeWithDiki\PaymentModule\Enums\PaymentVendor;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class PaymentMethodForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make("Payment Method Information")
                    ->components([
                        FileUpload::make("image_url")
                            ->label("Image")
                            ->image()
                            ->directory("payment-methods"),
                        TextInput::make("name")
                            ->required()
                            ->lazy()
                            ->afterStateUpdated(fn (string $state, callable $set) => $set("slug", str()->slug($state)))
                            ->label("Name"),
                        Select::make("payment_method_group_id")
                            ->label("Payment Method Group")
                            ->options(function() {
                                $paymentMethodGroupClass = config("payment-module.payment_method_group_class");
                                return $paymentMethodGroupClass::isActive()->pluck("name", "id");
                            })
                            ->nullable(),
                        Select::make("vendor")
                            ->options(config("payment-module.vendor_enum_class", PaymentVendor::class))
                            ->lazy()
                            ->required()
                            ->label("Vendor"),
                        Select::make("channel")
                            ->required()
                            ->reactive()
                            ->options(function($get){
                                if($get('vendor') === null) {
                                    return [];
                                }

                                $processor = app($get('vendor')?->getPaymentProcessorClass());
                                return $processor->getChannels() ?? [];
                            })
                            ->label("Channel"),
                        Textarea::make("description")
                            ->label("Description"),
                        Toggle::make("is_active")
                            ->label("Is Active")
                            ->default(false),
                    ])
                    ->columns(1)
                    ->aside()
            ])
            ->columns(1);
    }
}
