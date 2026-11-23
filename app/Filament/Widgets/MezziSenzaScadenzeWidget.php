<?php

namespace App\Filament\Widgets;

use App\Filament\Resources\VehicleResource;
use App\Models\Vehicle;
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget as BaseWidget;
use Illuminate\Support\Facades\Auth;

/**
 * I mezzi a cui manca un documento obbligatorio.
 *
 * E' il rovescio di UpcomingDeadlinesWidget, che mostra le scadenze in
 * arrivo: quelle almeno esistono. Qui ci sono i mezzi per cui non e' mai
 * stata inserita nessuna assicurazione, nessun bollo o nessuna revisione
 * attiva — e che quindi non possono comparire in nessun promemoria, perche'
 * non c'e' niente da far scadere. Al 23/11/2026 erano cinque mezzi su
 * sette, e nessuno lo sapeva (Laura, 23/11/2026).
 *
 * Insieme a "Prossime scadenze", nella sezione "Da fare adesso": sono la
 * stessa domanda vista dai due lati, e separarle vorrebbe dire guardarne
 * una sola.
 */
class MezziSenzaScadenzeWidget extends BaseWidget
{
    protected static ?int $sort = 2;

    public static function canView(): bool
    {
        return Auth::user()->can('view_any_vehicle');
    }

    protected int|string|array $columnSpan = 1;

    protected static ?string $heading = 'Mezzi senza documenti in corso';

    public function table(Table $table): Table
    {
        return $table
            ->query(Vehicle::query()
                ->conScadenzeMancanti()
                // scadenzeMancanti() legge la relazione mezzo per mezzo:
                // senza eager load la dashboard fa una query per riga.
                ->with('deadlines')
                ->orderBy('plate'))
            ->columns([
                Tables\Columns\TextColumn::make('plate')
                    ->label('Targa')
                    ->weight('medium'),
                Tables\Columns\TextColumn::make('brand')
                    ->label('Mezzo')
                    ->wrap()
                    ->state(fn (Vehicle $record) => $record->descrizione()),
                // Il punto del widget: non "questo mezzo ha un problema" ma
                // QUALE documento manca, cioe' cosa bisogna andare a cercare.
                Tables\Columns\TextColumn::make('id')
                    ->label('Manca')
                    ->badge()
                    ->color('danger')
                    ->state(fn (Vehicle $record) => $record->etichetteScadenzeMancanti()),
            ])
            ->recordUrl(fn (Vehicle $record) => Auth::user()->can('update', $record)
                ? VehicleResource::getUrl('edit', ['record' => $record])
                : null)
            ->emptyStateHeading('Tutti i mezzi sono coperti')
            ->emptyStateDescription('Ogni mezzo ha assicurazione, bollo e revisione a sistema.')
            ->emptyStateIcon('heroicon-o-check-circle')
            ->paginated(false);
    }
}
