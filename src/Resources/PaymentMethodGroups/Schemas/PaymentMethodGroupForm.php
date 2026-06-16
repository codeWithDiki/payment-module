<?php

namespace CodeWithDiki\PaymentModule\Resources\PaymentMethodGroups\Schemas;

use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class PaymentMethodGroupForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Group Information')
                    ->components([
                        FileUpload::make('image_url')
                            ->label('Image')
                            ->image()
                            ->directory('payment-method-groups'),
                        TextInput::make('name')
                            ->required()
                            ->lazy()
                            ->afterStateUpdated(fn (string $state, callable $set) => $set('slug', str()->slug($state)))
                            ->label('Name'),
                        TextInput::make('slug')
                            ->required()
                            ->label('Slug'),
                        Toggle::make('is_active')
                            ->label('Is Active')
                            ->default(false),
                    ])
                    ->columns(1)
                    ->aside(),
            ])
            ->columns(1);
    }
}
