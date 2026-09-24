<?php

namespace App\Filament\Widgets;

use App\Filament\Resources\InterventoProgrammatoResource;
use App\Filament\Resources\ServiceReportResource;
use App\Models\InterventoProgrammato;
use Filament\Notifications\Notification;
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget as BaseWidget;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;

/**
 * "Il mio giro": dove devo andare oggi (24/09/2026).
 *
 * La programmazione ha una pagina sua, ma il tecnico la dashboard ce l'ha
 * gia' davanti quando entra — e quello che gli serve la mattina sono tre
 * righe, non un elenco da filtrare. Qui ci sono i suoi lavori di oggi piu'
 * quelli rimasti indietro, con i due bottoni che gli servono: fare il
 * rapportino, o spuntare e basta.
 *
 * Lo vede solo chi ha un giro assegnato: a chi il programma lo prepara serve
 * la pagina intera, non un riquadro da cinque righe.
 */
class IlMioGiroWidget extends BaseWidget
{
    // Sezione "Da fare adesso", accanto alle scadenze.
    protected static ?int $sort = 1;

    protected int|string|array $columnSpan = 'full';

    protected static ?string $heading = 'Il mio giro';

    public static function canView(): bool
    {
        $utente = Auth::user();

        if (! $utente?->can('viewAny', InterventoProgrammato::class)) {
            return false;
        }

        // Chi prepara il programma non ha un giro: userebbe la pagina.
        if ($utente->can('assegna', InterventoProgrammato::class)) {
            return false;
        }

        return true;
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(InterventoProgrammato::query()
                ->daFare()
                ->diTecnico((string) Auth::id())
                // Oggi, piu' quello che e' rimasto indietro: un lavoro di ieri
                // non fatto non deve sparire dalla vista.
                ->whereDate('data', '<=', Carbon::today())
                ->ordinato()
                ->limit(10))
            ->columns([
                Tables\Columns\TextColumn::make('data')
                    ->label('Giorno')
                    ->date('d/m/Y')
                    ->description(fn (InterventoProgrammato $r) => $r->momento === InterventoProgrammato::MOMENTO_GIORNATA
                        ? null
                        : InterventoProgrammato::momenti()[$r->momento] ?? null)
                    ->color(fn (InterventoProgrammato $r) => $r->inRitardo() ? 'danger' : null),
                Tables\Columns\TextColumn::make('customer.company_name')
                    ->label('Dove')
                    ->formatStateUsing(fn ($state, InterventoProgrammato $r) => $r->etichetta())
                    ->description(fn (InterventoProgrammato $r) => $r->customer?->city)
                    ->wrap(),
                Tables\Columns\TextColumn::make('tipo')
                    ->label('Lavoro')
                    ->badge()
                    ->formatStateUsing(fn (string $state) => InterventoProgrammato::tipi()[$state] ?? $state)
                    ->color(fn (string $state) => match ($state) {
                        InterventoProgrammato::TIPO_CHIUSURA => 'warning',
                        InterventoProgrammato::TIPO_APERTURA => 'success',
                        InterventoProgrammato::TIPO_RITIRO => 'danger',
                        default => 'gray',
                    }),
                Tables\Columns\TextColumn::make('note')
                    ->label('Note')
                    ->limit(40)
                    ->placeholder('—')
                    ->visibleFrom('md'),
            ])
            ->actions([
                Tables\Actions\Action::make('rapportino')
                    ->label('Fai il rapportino')
                    ->icon('heroicon-o-document-text')
                    ->visible(fn (InterventoProgrammato $r) => $r->customer_id !== null)
                    ->url(fn (InterventoProgrammato $r) => ServiceReportResource::getUrl('create', array_filter([
                        'customer_id' => $r->customer_id,
                        'machine_unit_id' => $r->machine_unit_id,
                        'intervention_type' => $r->tipoRapportino(),
                        'programmato_id' => $r->id,
                    ]))),
                Tables\Actions\Action::make('fatto')
                    ->label('Fatto')
                    ->icon('heroicon-o-check-circle')
                    ->color('success')
                    ->requiresConfirmation()
                    ->modalHeading('Segnare come fatto?')
                    ->modalDescription(fn (InterventoProgrammato $r) => $r->etichetta())
                    ->action(function (InterventoProgrammato $r) {
                        $r->segnaFatto();

                        Notification::make()->title('Segnato come fatto.')->success()->send();
                    }),
            ])
            ->recordUrl(fn (InterventoProgrammato $r) => InterventoProgrammatoResource::getUrl('edit', ['record' => $r]))
            ->emptyStateHeading('Niente in programma per oggi')
            ->paginated(false);
    }
}
