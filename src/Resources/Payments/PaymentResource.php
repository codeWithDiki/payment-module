<?php

namespace CodeWithDiki\PaymentModule\Resources\Payments;

use BackedEnum;
use CodeWithDiki\PaymentModule\Enums\PaymentStatus;
use CodeWithDiki\PaymentModule\Facades\PaymentModule;
use CodeWithDiki\PaymentModule\Models\Payment;
use CodeWithDiki\PaymentModule\Resources\Payments\Pages\ListPayments;
use CodeWithDiki\PaymentModule\Resources\Payments\Pages\ViewPayment;
use CodeWithDiki\PaymentModule\Resources\Payments\Schemas\PaymentForm;
use CodeWithDiki\PaymentModule\Resources\Payments\Schemas\PaymentInfolist;
use CodeWithDiki\PaymentModule\Resources\Payments\Tables\PaymentsTable;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Log;

class PaymentResource extends Resource
{
    protected static ?string $model = Payment::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCurrencyDollar;

    protected static null|\UnitEnum|string $navigationGroup = 'Payment Management';

    protected static ?string $recordTitleAttribute = 'payment_code';

    public static function getModel(): string
    {
        return config('payment-module.payment_class', Payment::class);
    }

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

    /**
     * Manual settlement for offline channels (bank transfer, convenience store, ...),
     * which have no gateway to confirm the payment for us.
     *
     * Deliberately hidden for gateway vendors: marking a Midtrans or Stripe payment paid
     * by hand would settle an order the gateway never actually collected money for.
     */
    public static function getConfirmAction(): Action
    {
        return Action::make('confirm')
            ->label('Confirm Payment')
            ->icon(Heroicon::OutlinedCheckCircle)
            ->color('success')
            ->requiresConfirmation()
            ->modalDescription('Only confirm once you have verified the funds were received. This marks the payment as paid and dispatches PaymentPaid.')
            ->visible(fn (Payment $record) => $record->canBeConfirmedManually())
            ->action(function (Payment $record) {
                // visible() only hides the button. Re-check the invariant here so a forged
                // action call cannot settle a gateway payment the gateway never collected.
                if (! $record->canBeConfirmedManually()) {
                    Notification::make()
                        ->title('This payment cannot be confirmed manually')
                        ->danger()
                        ->send();

                    return;
                }

                try {
                    PaymentModule::setPaymentStatus($record, PaymentStatus::PAID);

                    Notification::make()
                        ->title('Payment confirmed')
                        ->success()
                        ->send();
                } catch (\Throwable $e) {
                    Log::error('Manual payment confirmation failed', [
                        'payment_id' => $record->id,
                        'exception' => $e,
                    ]);

                    Notification::make()
                        ->title('Failed to confirm payment')
                        ->body('An unexpected error occurred. Please try again or contact support.')
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
            'index' => ListPayments::route('/'),
            'view' => ViewPayment::route('/{record}'),
        ];
    }
}
