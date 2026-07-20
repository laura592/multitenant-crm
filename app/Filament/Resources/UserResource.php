<?php

namespace App\Filament\Resources;

use App\Filament\Resources\UserResource\Pages;
use App\Models\User;
use Filament\Facades\Filament;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

/**
 * Creazione/gestione utenti, riservata al ruolo "admin" e allo staff master
 * (is_super_admin) — vedi App\Support\RolePermissions. Prima di questa
 * risorsa non esisteva nessuna UI per creare utenti (solo tinker/seeder).
 */
class UserResource extends Resource
{
    protected static ?string $model = User::class;

    protected static ?string $navigationIcon = 'heroicon-o-user-group';

    protected static ?string $navigationGroup = 'Amministrazione';

    protected static ?string $navigationLabel = 'Utenti';

    protected static ?string $modelLabel = 'Utente';

    protected static ?string $pluralModelLabel = 'Utenti';

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make('Anagrafica')
                ->columns(2)
                ->schema([
                    Forms\Components\TextInput::make('name')->label('Nome')->required()->maxLength(255),
                    Forms\Components\TextInput::make('email')->label('Email')->email()->required()->maxLength(255)->unique(ignoreRecord: true),
                    Forms\Components\TextInput::make('password')
                        ->label('Password')
                        ->password()
                        ->revealable()
                        ->required(fn (string $context) => $context === 'create')
                        ->dehydrated(fn (?string $state) => filled($state))
                        ->dehydrateStateUsing(fn (string $state) => bcrypt($state))
                        ->maxLength(255)
                        ->helperText('Lascia vuoto per non modificarla.'),
                    Forms\Components\Select::make('roles')
                        ->label('Ruolo')
                        ->relationship('roles', 'name')
                        ->multiple()
                        ->preload()
                        ->required(),
                    Forms\Components\Hidden::make('tenant_id')->default(fn () => Filament::getTenant()?->id),
                    Forms\Components\Toggle::make('is_super_admin')
                        ->label('Staff master (accesso a tutti i tenant)')
                        ->visible(fn () => auth()->user()?->is_super_admin)
                        ->columnSpanFull(),
                ]),
            Forms\Components\Section::make('Contratto')
                ->columns(3)
                ->schema([
                    Forms\Components\TextInput::make('daily_contract_hours')->label('Ore giornaliere')->numeric(),
                    Forms\Components\TextInput::make('weekly_contract_hours')->label('Ore settimanali')->numeric(),
                    Forms\Components\TextInput::make('annual_leave_days')->label('Giorni ferie annui')->numeric(),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('name')->label('Nome')->searchable(),
                Tables\Columns\TextColumn::make('email')->label('Email')->searchable(),
                Tables\Columns\TextColumn::make('roles.name')->label('Ruolo')->badge(),
                Tables\Columns\IconColumn::make('is_super_admin')->label('Staff master')->boolean(),
                Tables\Columns\TextColumn::make('created_at')->label('Creato il')->date('d/m/Y'),
            ])
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
            'index' => Pages\ListUsers::route('/'),
            'create' => Pages\CreateUser::route('/create'),
            'edit' => Pages\EditUser::route('/{record}/edit'),
        ];
    }
}
