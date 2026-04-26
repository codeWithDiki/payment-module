<?php

namespace CodeWithDiki\PaymentModule\Resources\PaymentMethods;

use CodeWithDiki\PaymentModule\Resources\PaymentMethods\Pages\CreatePaymentMethod;
use CodeWithDiki\PaymentModule\Resources\PaymentMethods\Pages\EditPaymentMethod;
use CodeWithDiki\PaymentModule\Resources\PaymentMethods\Pages\ListPaymentMethods;
use CodeWithDiki\PaymentModule\Resources\PaymentMethods\Pages\ViewPaymentMethod;
use CodeWithDiki\PaymentModule\Resources\PaymentMethods\Schemas\PaymentMethodForm;
use CodeWithDiki\PaymentModule\Resources\PaymentMethods\Schemas\PaymentMethodInfolist;
use CodeWithDiki\PaymentModule\Resources\PaymentMethods\Tables\PaymentMethodsTable;
use BackedEnum;
use CodeWithDiki\PaymentModule\Models\PaymentMethod;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

class PaymentMethodResource extends Resource
{
    protected static ?string $model = PaymentMethod::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCreditCard;
    
    protected static null|\UnitEnum|string $navigationGroup = 'Payment';

    protected static ?string $recordTitleAttribute = 'name';

    public static function form(Schema $schema): Schema
    {
        return PaymentMethodForm::configure($schema);
    }

    public static function infolist(Schema $schema): Schema
    {
        return PaymentMethodInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return PaymentMethodsTable::configure($table);
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
            'index' => ListPaymentMethods::route('/'),
            'create' => CreatePaymentMethod::route('/create'),
            'view' => ViewPaymentMethod::route('/{record}'),
            'edit' => EditPaymentMethod::route('/{record}/edit'),
        ];
    }
}
