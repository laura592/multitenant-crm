<?php

namespace App\Filament\Resources;

use App\Filament\Resources\CustomerResource\Pages;
use App\Models\Customer;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class CustomerResource extends Resource
{
    protected static ?string $model = Customer::class;

    protected static ?string $navigationIcon = 'heroicon-o-users';

    protected static ?string $navigationGroup = 'Vendite';

    protected static ?string $navigationLabel = 'Clienti';

    protected static ?string $modelLabel = 'Cliente';

    protected static ?string $pluralModelLabel = 'Clienti';

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make('Anagrafica')
                ->columns(2)
                ->schema([
                    Forms\Components\TextInput::make('first_name')->label('Nome')->maxLength(255),
                    Forms\Components\TextInput::make('last_name')->label('Cognome')->maxLength(255),
                    Forms\Components\TextInput::make('company_name')->label('Ragione sociale')->maxLength(255)->columnSpanFull(),
                    Forms\Components\TextInput::make('email')->label('Email')->email()->maxLength(255),
                    Forms\Components\TextInput::make('mobile')->label('Cellulare')->tel()->maxLength(255),
                ]),
            Forms\Components\Section::make('Indirizzo')
                ->columns(2)
                ->schema([
                    Forms\Components\TextInput::make('street')->label('Via')->maxLength(255)->columnSpanFull(),
                    Forms\Components\TextInput::make('postal_code')->label('CAP')->maxLength(10),
                    Forms\Components\TextInput::make('city')->label('Città')->maxLength(255),
                    Forms\Components\TextInput::make('province')->label('Provincia')->maxLength(255),
                ]),
            Forms\Components\Section::make('Dati fiscali')
                ->columns(3)
                ->schema([
                    Forms\Components\TextInput::make('tax_code')->label('Codice fiscale')->maxLength(255),
                    Forms\Components\TextInput::make('vat_number')->label('P.IVA')->maxLength(255),
                    Forms\Components\TextInput::make('sdi')->label('Codice SDI')->maxLength(255),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('company_name')->label('Ragione sociale')->searchable(),
                Tables\Columns\TextColumn::make('full_name')->label('Referente')->searchable(['first_name', 'last_name']),
                Tables\Columns\TextColumn::make('email')->label('Email')->searchable(),
                Tables\Columns\TextColumn::make('mobile')->label('Cellulare'),
                Tables\Columns\TextColumn::make('city')->label('Città'),
            ])
            ->filters([])
            ->actions([
                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make(),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListCustomers::route('/'),
            'create' => Pages\CreateCustomer::route('/create'),
            'edit' => Pages\EditCustomer::route('/{record}/edit'),
        ];
    }
}
