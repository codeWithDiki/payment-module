<?php

namespace CodeWithDiki\PaymentModule\Resources\Disbursements;

use BackedEnum;
use CodeWithDiki\PaymentModule\Enums\DisbursementStatus;
use CodeWithDiki\PaymentModule\Facades\PaymentModule;
use CodeWithDiki\PaymentModule\Models\Disbursement;
use CodeWithDiki\PaymentModule\Resources\Disbursements\Pages\ListDisbursements;
use CodeWithDiki\PaymentModule\Resources\Disbursements\Pages\ViewDisbursement;
use CodeWithDiki\PaymentModule\Resources\Disbursements\Schemas\DisbursementForm;
use CodeWithDiki\PaymentModule\Resources\Disbursements\Schemas\DisbursementInfolist;
use CodeWithDiki\PaymentModule\Resources\Disbursements\Tables\DisbursementsTable;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

class DisbursementResource extends Resource
{
    protected static ?string $model = Disbursement::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBanknotes;

    protected static null|\UnitEnum|string $navigationGroup = 'Payment Management';

    protected static ?string $recordTitleAttribute = 'disbursement_code';

    public static function getModel(): string
    {
        return config('payment-module.disbursement_class', Disbursement::class);
    }

    public static function form(Schema $schema): Schema
    {
        return DisbursementForm::configure($schema);
    }

    public static function infolist(Schema $schema): Schema
    {
        return DisbursementInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return DisbursementsTable::configure($table);
    }

    public static function getApproveAction(): Action
    {
        return Action::make('approve')
            ->label('Approve')
            ->icon(Heroicon::OutlinedCheckCircle)
            ->color('success')
            ->requiresConfirmation()
            ->visible(fn (Disbursement $record) => $record->status === DisbursementStatus::QUEUED)
            ->action(function (Disbursement $record) {
                try {
                    PaymentModule::approveDisbursement($record);

                    Notification::make()
                        ->title('Disbursement approved')
                        ->success()
                        ->send();
                } catch (\Throwable $e) {
                    Notification::make()
                        ->title('Failed to approve disbursement')
                        ->body($e->getMessage())
                        ->danger()
                        ->send();
                }
            });
    }

    public static function getRejectAction(): Action
    {
        return Action::make('reject')
            ->label('Reject')
            ->icon(Heroicon::OutlinedXCircle)
            ->color('danger')
            ->requiresConfirmation()
            ->visible(fn (Disbursement $record) => $record->status === DisbursementStatus::QUEUED)
            ->schema([
                Textarea::make('reject_reason')
                    ->label('Reject reason')
                    ->required(),
            ])
            ->action(function (Disbursement $record, array $data) {
                try {
                    PaymentModule::rejectDisbursement($record, $data['reject_reason'] ?? null);

                    Notification::make()
                        ->title('Disbursement rejected')
                        ->success()
                        ->send();
                } catch (\Throwable $e) {
                    Notification::make()
                        ->title('Failed to reject disbursement')
                        ->body($e->getMessage())
                        ->danger()
                        ->send();
                }
            });
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
            'index' => ListDisbursements::route('/'),
            'view' => ViewDisbursement::route('/{record}'),
        ];
    }
}
