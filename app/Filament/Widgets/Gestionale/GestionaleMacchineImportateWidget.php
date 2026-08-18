<?php

namespace App\Filament\Widgets\Gestionale;

use App\Models\MachineUnit;
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget as BaseWidget;

/**
 * Macchine create automaticamente dal sync notturno (art_installati, vedi
 * GestionaleSyncRunner::importInstalledMachines()) — sola visualizzazione,
 * nessuna azione Conferma/Scarta: sono gia' MachineUnit vere, serve solo a
 * vedere cosa e' arrivato di recente.
 */
class GestionaleMacchineImportateWidget extends BaseWidget
{
    protected static ?string $heading = 'Macchine importate di recente da Eureka';

    protected int|string|array $columnSpan = 1;

    // Vedi GestionaleDaRivedereWidget per il perche'.
    protected static bool $isLazy = false;

    public static function canView(): bool
    {
        return static::baseQuery()->exists();
    }

    private static function baseQuery()
    {
        return MachineUnit::query()
            ->where('source', MachineUnit::SOURCE_EUREKA)
            ->where('created_at', '>=', now()->subDays(7));
    }

    public function table(Table $table): Table
    {
        return $table
            ->queryStringIdentifier('macchineImportate')
            ->query(static::baseQuery()->latest('created_at'))
            ->columns([
                Tables\Columns\TextColumn::make('currentCustomer.full_name')->label('Cliente')->placeholder('—'),
                Tables\Columns\TextColumn::make('serial_number')->label('Matricola'),
                Tables\Columns\TextColumn::make('display_name')->label('Modello'),
                Tables\Columns\TextColumn::make('created_at')->label('Importata il')->date(),
            ])
            ->paginated([10, 25, 50]);
    }
}
