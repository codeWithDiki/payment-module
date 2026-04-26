<?php

namespace CodeWithDiki\PaymentModule\Resources\Payments\Schemas;

use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Schema;

class PaymentInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextEntry::make('paymentMethod.name')
                    ->label('Payment method'),
                TextEntry::make('paymentable_type'),
                TextEntry::make('paymentable_id')
                    ->numeric(),
                TextEntry::make('payment_code'),
                TextEntry::make('amount')
                    ->numeric(),
                TextEntry::make('payment_headers')
                    ->placeholder('-')
                    ->formatStateUsing(fn ($state) => is_array($state) ? json_encode($state, JSON_PRETTY_PRINT) : $state)
                    ->columnSpanFull(),
                TextEntry::make('payment_payload')
                    ->placeholder('-')
                    ->formatStateUsing(fn ($state) => is_array($state) ? json_encode($state, JSON_PRETTY_PRINT) : $state)
                    ->columnSpanFull(),
                TextEntry::make('payment_response')
                    ->placeholder('-')
                    ->formatStateUsing(fn ($state) => is_array($state) ? json_encode($state, JSON_PRETTY_PRINT) : $state)
                    ->columnSpanFull(),
                TextEntry::make('customer_name')
                    ->placeholder('-'),
                TextEntry::make('customer_email')
                    ->placeholder('-'),
                TextEntry::make('customer_phone')
                    ->placeholder('-'),
                TextEntry::make('customer_address')
                    ->placeholder('-'),
                TextEntry::make('customer_custom_data')
                    ->placeholder('-')
                    ->columnSpanFull(),
                TextEntry::make('status')
                    ->badge(),
                TextEntry::make('paid_at')
                    ->dateTime()
                    ->placeholder('-'),
                TextEntry::make('created_at')
                    ->dateTime()
                    ->placeholder('-'),
                TextEntry::make('updated_at')
                    ->dateTime()
                    ->placeholder('-'),
            ]);
    }
}
