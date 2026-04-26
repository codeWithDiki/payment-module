<?php

namespace CodeWithDiki\PaymentModule\Resources\Payments;

use CodeWithDiki\PaymentModule\Resources\Payments\Pages\ListPayments;
use CodeWithDiki\PaymentModule\Resources\Payments\Pages\ViewPayment;
use CodeWithDiki\PaymentModule\Resources\Payments\Schemas\PaymentForm;
use CodeWithDiki\PaymentModule\Resources\Payments\Schemas\PaymentInfolist;
use CodeWithDiki\PaymentModule\Resources\Payments\Tables\PaymentsTable;
use BackedEnum;
use CodeWithDiki\PaymentModule\Models\Payment;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

class PaymentResource extends Resource
{
    protected static ?string $model = Payment::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCurrencyDollar;

    protected static null|\UnitEnum|string $navigationGroup = 'Payment';

    protected static ?string $recordTitleAttribute = 'payment_code';

    public static function form(Schema $schema): Schema
    {
        return PaymentForm::configure($schema);
    }

    public static function infolist(Schema $schema): Schema
    {
        return PaymentInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return PaymentsTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            //
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListPayments::route('/'),
            'view' => ViewPayment::route('/{record}'),
        ];
    }
}
