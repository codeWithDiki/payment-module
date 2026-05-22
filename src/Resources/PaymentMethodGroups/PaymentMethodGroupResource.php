<?php

namespace CodeWithDiki\PaymentModule\Resources\PaymentMethodGroups;

use CodeWithDiki\PaymentModule\Resources\PaymentMethodGroups\Pages\CreatePaymentMethodGroup;
use CodeWithDiki\PaymentModule\Resources\PaymentMethodGroups\Pages\EditPaymentMethodGroup;
use CodeWithDiki\PaymentModule\Resources\PaymentMethodGroups\Pages\ListPaymentMethodGroups;
use CodeWithDiki\PaymentModule\Resources\PaymentMethodGroups\Pages\ViewPaymentMethodGroup;
use CodeWithDiki\PaymentModule\Resources\PaymentMethodGroups\Schemas\PaymentMethodGroupForm;
use CodeWithDiki\PaymentModule\Resources\PaymentMethodGroups\Schemas\PaymentMethodGroupInfolist;
use CodeWithDiki\PaymentModule\Resources\PaymentMethodGroups\Tables\PaymentMethodGroupsTable;
use BackedEnum;
use CodeWithDiki\PaymentModule\Models\PaymentMethodGroup;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

class PaymentMethodGroupResource extends Resource
{
    protected static ?string $model = PaymentMethodGroup::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCircleStack;

    protected static null|\UnitEnum|string $navigationGroup = 'Payment Management';

    protected static ?string $recordTitleAttribute = 'name';

    public static function getModel() : string
    {
        return config('payment-module.payment_method_group_class', PaymentMethodGroup::class);
    }

    public static function form(Schema $schema): Schema
    {
        return PaymentMethodGroupForm::configure($schema);
    }

    public static function infolist(Schema $schema): Schema
    {
        return PaymentMethodGroupInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return PaymentMethodGroupsTable::configure($table);
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
            'index' => ListPaymentMethodGroups::route('/'),
            'create' => CreatePaymentMethodGroup::route('/create'),
            'view' => ViewPaymentMethodGroup::route('/{record}'),
            'edit' => EditPaymentMethodGroup::route('/{record}/edit'),
        ];
    }
}
