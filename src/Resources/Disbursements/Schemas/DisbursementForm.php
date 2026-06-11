<?php

namespace CodeWithDiki\PaymentModule\Resources\Disbursements\Schemas;

use CodeWithDiki\PaymentModule\Enums\DisbursementStatus;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;

class DisbursementForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('disbursement_code')
                    ->required(),
                TextInput::make('reference_no'),
                TextInput::make('vendor')
                    ->required(),
                TextInput::make('beneficiary_name')
                    ->required(),
                TextInput::make('beneficiary_account')
                    ->required(),
                TextInput::make('beneficiary_bank')
                    ->required(),
                TextInput::make('beneficiary_email')
                    ->email(),
                TextInput::make('amount')
                    ->required()
                    ->numeric(),
                TextInput::make('notes'),
                Select::make('status')
                    ->options(DisbursementStatus::class)
                    ->required(),
                Textarea::make('disbursement_payload')
                    ->columnSpanFull(),
                Textarea::make('disbursement_response')
                    ->columnSpanFull(),
                TextInput::make('error_code'),
                TextInput::make('error_message'),
                DateTimePicker::make('completed_at'),
            ]);
    }
}
