<?php

namespace CodeWithDiki\PaymentModule\Resources\Disbursements\Schemas;

use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Schema;

class DisbursementInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextEntry::make('disbursement_code'),
                TextEntry::make('reference_no')
                    ->placeholder('-'),
                TextEntry::make('vendor')
                    ->badge(),
                TextEntry::make('disbursable_type')
                    ->placeholder('-'),
                TextEntry::make('disbursable_id')
                    ->numeric()
                    ->placeholder('-'),
                TextEntry::make('beneficiary_name'),
                TextEntry::make('beneficiary_account'),
                TextEntry::make('beneficiary_bank'),
                TextEntry::make('beneficiary_email')
                    ->placeholder('-'),
                TextEntry::make('amount')
                    ->numeric(),
                TextEntry::make('notes')
                    ->placeholder('-'),
                TextEntry::make('status')
                    ->badge(),
                TextEntry::make('disbursement_payload')
                    ->placeholder('-')
                    ->formatStateUsing(fn ($state) => is_array($state) ? json_encode($state, JSON_PRETTY_PRINT) : $state)
                    ->columnSpanFull(),
                TextEntry::make('disbursement_response')
                    ->placeholder('-')
                    ->formatStateUsing(fn ($state) => is_array($state) ? json_encode($state, JSON_PRETTY_PRINT) : $state)
                    ->columnSpanFull(),
                TextEntry::make('error_code')
                    ->placeholder('-'),
                TextEntry::make('error_message')
                    ->placeholder('-'),
                TextEntry::make('completed_at')
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
