<?php

namespace CodeWithDiki\PaymentModule\Resources\Payments\Schemas;

use CodeWithDiki\PaymentModule\Enums\PaymentStatus;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;

class PaymentForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Select::make('payment_method_id')
                    ->relationship('paymentMethod', 'name')
                    ->required(),
                TextInput::make('paymentable_type')
                    ->required(),
                TextInput::make('paymentable_id')
                    ->required()
                    ->numeric(),
                TextInput::make('payment_code')
                    ->required(),
                TextInput::make('amount')
                    ->required()
                    ->numeric(),
                Textarea::make('payment_headers')
                    ->columnSpanFull(),
                Textarea::make('payment_payload')
                    ->columnSpanFull(),
                Textarea::make('payment_response')
                    ->columnSpanFull(),
                TextInput::make('customer_name'),
                TextInput::make('customer_email')
                    ->email(),
                TextInput::make('customer_phone')
                    ->tel(),
                TextInput::make('customer_address'),
                Textarea::make('customer_custom_data')
                    ->columnSpanFull(),
                Select::make('status')
                    ->options(PaymentStatus::class)
                    ->required(),
                DateTimePicker::make('paid_at'),
            ]);
    }
}
