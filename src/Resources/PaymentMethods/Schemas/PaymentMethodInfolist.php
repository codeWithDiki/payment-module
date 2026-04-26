<?php

namespace CodeWithDiki\PaymentModule\Resources\PaymentMethods\Schemas;

use Filament\Infolists\Components\IconEntry;
use Filament\Infolists\Components\ImageEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Schema;

class PaymentMethodInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextEntry::make('paymentMethodGroup.name')
                    ->placeholder('-'),
                TextEntry::make('name'),
                TextEntry::make('vendor')
                    ->badge(),
                TextEntry::make('channel'),
                TextEntry::make('description')
                    ->placeholder('-'),
                ImageEntry::make('image_url')
                    ->placeholder('-'),
                TextEntry::make('meta_data')
                    ->placeholder('-')
                    ->columnSpanFull(),
                IconEntry::make('is_active')
                    ->boolean(),
                TextEntry::make('created_at')
                    ->dateTime()
                    ->placeholder('-'),
                TextEntry::make('updated_at')
                    ->dateTime()
                    ->placeholder('-'),
            ]);
    }
}
