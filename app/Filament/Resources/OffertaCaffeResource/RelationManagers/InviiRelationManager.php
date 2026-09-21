<?php

namespace App\Filament\Resources\OffertaCaffeResource\RelationManagers;

use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

/** Lo storico degli invii dell'offerta: si legge soltanto. */
class InviiRelationManager extends RelationManager
{
    protected static string $relationship = 'emails';

    protected static ?string $title = 'Invii';

    public function isReadOnly(): bool
    {
        return true;
    }

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return true;
    }

    public function table(Table $table): Table
    {
        return $table
            ->emptyStateHeading('Non ancora inviata')
            ->columns([
                Tables\Columns\TextColumn::make('created_at')->label('Inviata il')->dateTime('d/m/Y H:i'),
                Tables\Columns\TextColumn::make('recipient_email')->label('A'),
                Tables\Columns\TextColumn::make('cc_email')->label('CC')->placeholder('—'),
                Tables\Columns\TextColumn::make('inviata_con')->label('Insieme a')->placeholder('da sola'),
                Tables\Columns\TextColumn::make('user.name')->label('Da')->placeholder('—'),
                Tables\Columns\TextColumn::make('status')
                    ->label('Esito')
                    ->badge()
                    ->formatStateUsing(fn (string $state) => $state === 'sent' ? 'Inviata' : 'Fallita')
                    ->color(fn (string $state) => $state === 'sent' ? 'success' : 'danger')
                    ->tooltip(fn ($record) => $record->error_message),
            ]);
    }
}
