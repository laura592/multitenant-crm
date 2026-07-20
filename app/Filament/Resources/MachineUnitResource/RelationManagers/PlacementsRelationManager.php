<?php

namespace App\Filament\Resources\MachineUnitResource\RelationManagers;

use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;

/**
 * Storico sola lettura: gli spostamenti si creano solo tramite l'azione
 * "Sposta" sulla lista principale (MachineUnit::moveTo()), mai qui a mano,
 * per non rompere l'invariante "un solo posizionamento aperto alla volta".
 */
class PlacementsRelationManager extends RelationManager
{
    protected static string $relationship = 'placements';

    protected static ?string $title = 'Storico posizionamenti';

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('id')
            ->columns([
                Tables\Columns\TextColumn::make('customer.company_name')->label('Cliente')->placeholder('Magazzino'),
                Tables\Columns\TextColumn::make('placed_at')->label('Dal')->dateTime('d/m/Y H:i'),
                Tables\Columns\TextColumn::make('removed_at')->label('Al')->dateTime('d/m/Y H:i')->placeholder('In corso'),
                Tables\Columns\TextColumn::make('notes')->label('Note')->limit(50),
            ])
            ->defaultSort('placed_at', 'desc');
    }
}
