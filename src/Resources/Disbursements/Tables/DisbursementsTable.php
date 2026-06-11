<?php

namespace CodeWithDiki\PaymentModule\Resources\Disbursements\Tables;

use CodeWithDiki\PaymentModule\Resources\Disbursements\DisbursementResource;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class DisbursementsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('disbursement_code')
                    ->searchable(),
                TextColumn::make('reference_no')
                    ->searchable(),
                TextColumn::make('vendor')
                    ->badge()
                    ->searchable(),
                TextColumn::make('beneficiary_name')
                    ->searchable(),
                TextColumn::make('beneficiary_bank')
                    ->searchable(),
                TextColumn::make('beneficiary_account')
                    ->searchable(),
                TextColumn::make('amount')
                    ->numeric()
                    ->sortable(),
                TextColumn::make('status')
                    ->badge()
                    ->searchable(),
                TextColumn::make('completed_at')
                    ->dateTime()
                    ->sortable(),
                TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('updated_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                //
            ])
            ->recordActions([
                ViewAction::make(),
                DisbursementResource::getApproveAction(),
                DisbursementResource::getRejectAction(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([

                ]),
            ]);
    }
}
