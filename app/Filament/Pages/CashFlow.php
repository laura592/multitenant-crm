<?php

namespace App\Filament\Pages;

use App\Filament\Widgets\Contabilita\CashflowMensileWidget;
use App\Filament\Widgets\Contabilita\CashflowOverviewWidget;
use App\Models\EurekaCashflowVoce;
use Filament\Facades\Filament;
use Filament\Pages\Page;
use Filament\Tables;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

/**
 * Quando i soldi entrano e quando escono.
 *
 * È l'unica schermata contabile che guarda AVANTI. Lo scaduto dice cosa è
 * già andato storto, le analisi dicono cos'è successo: qui si vede se fra
 * due mesi ci sono i soldi per pagare i fornitori.
 *
 * Sotto il grafico c'è l'elenco delle singole voci, perché un totale
 * mensile senza il "da cosa viene" non si può né controllare né usare per
 * decidere. Il filtro sul mese è la via naturale: si guarda il grafico, si
 * vede un mese storto, lo si apre.
 *
 * Le voci NON sono collegabili ai clienti del CRM: /contabilita/cashflow/dettaglio
 * dà solo la ragione sociale come testo libero, senza id anagrafica. Per
 * partire da un cliente e arrivare al suo esposto ci sono le partite aperte.
 */
class CashFlow extends Page implements HasTable
{
    // Senza HasPageShield una Page Filament e' accessibile a chiunque sia
    // autenticato: qui sono previsioni di cassa, l'omissione sarebbe una fuga.
    use InteractsWithTable;

    protected static ?string $navigationIcon = 'heroicon-o-arrow-trending-up';

    protected static ?string $navigationGroup = 'Amministrazione';

    protected static ?int $navigationSort = 3;

    protected static ?string $navigationLabel = 'Cash flow';

    protected static ?string $title = 'Cash flow';

    protected static ?string $slug = 'cash-flow';

    protected static string $view = 'filament.pages.cash-flow';

    /**
     * Solo staff master.
     *
     * Non un permesso nella matrice dei ruoli ma un cancello nel codice
     * (indicazione dell'utente, 02/09/2026): sono numeri contabili
     * dell'azienda, e "chi puo' vederli" non e' una casella che ha senso
     * spuntare per un ruolo — o sei staff master o non li vedi. Stessa
     * forma di TenantResource::canViewAny().
     *
     * Per questo la pagina esce anche dalla matrice di Shield (vedi
     * config/filament-shield.php, exclude.pages): lasciarci una casella
     * che non cambia niente e' peggio che non averla.
     */
    public static function canAccess(): bool
    {
        return (bool) auth()->user()?->is_super_admin;
    }

    public static function shouldRegisterNavigation(): bool
    {
        return static::canAccess();
    }

    public function getSubheading(): ?string
    {
        return 'Previsione da scadenziario e documenti aperti su Eureka. I dati si aggiornano con eureka:import-kpi-contabili.';
    }

    protected function getHeaderWidgets(): array
    {
        return [
            CashflowOverviewWidget::class,
            CashflowMensileWidget::class,
        ];
    }

    public function getHeaderWidgetsColumns(): int|string|array
    {
        return 1;
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(fn (): Builder => EurekaCashflowVoce::query()
                ->where('tenant_id', Filament::getTenant()?->id))
            ->columns([
                Tables\Columns\TextColumn::make('data_scadenza')
                    ->label('Scadenza')
                    ->date('d/m/Y')
                    ->sortable()
                    ->placeholder('—'),

                Tables\Columns\TextColumn::make('descrizione')
                    ->label('Chi')
                    ->searchable()
                    ->wrap()
                    ->weight('medium')
                    // Numero e data del documento, saltando i pezzi che
                    // mancano. Un trim(..., ' del ') sembrava piu' corto ma
                    // e' un'altra cosa: toglie i CARATTERI d, e, l e spazio
                    // dalle estremita', quindi funzionava per caso finche'
                    // il numero cominciava per cifra.
                    ->description(fn (EurekaCashflowVoce $r) => implode(' ', array_filter([
                        filled($r->numero) ? "n. {$r->numero}" : null,
                        $r->data_documento?->format('del d/m/Y'),
                    ]))),

                Tables\Columns\TextColumn::make('tipo')
                    ->label('Da cosa')
                    ->badge()
                    // Le sigle di Eureka non dicono niente a chi legge: FTC
                    // e' una scadenza di fattura cliente, OC un ordine
                    // cliente. Un badge "FTC" costringerebbe a tenere a
                    // mente una legenda che non c'e'.
                    ->formatStateUsing(fn (?string $state) => match ($state) {
                        'FTC' => 'Fattura cliente',
                        'FTF' => 'Fattura fornitore',
                        'OC' => 'Ordine cliente',
                        'OF' => 'Ordine fornitore',
                        'BC' => 'Bolla cliente',
                        'BF' => 'Bolla fornitore',
                        default => $state ?: '—',
                    })
                    // Le fatture sono impegni presi, ordini e bolle sono
                    // previsioni: il colore lo dice senza doverlo spiegare.
                    ->color(fn (EurekaCashflowVoce $r) => $r->daFattura() ? 'primary' : 'gray'),

                Tables\Columns\TextColumn::make('importo')
                    ->label('Importo')
                    ->money('EUR')
                    ->alignEnd()
                    ->sortable()
                    ->weight('bold')
                    // Il segno dell'importo E' il verso. Il colore lo
                    // raddoppia, ma il numero resta leggibile da solo:
                    // -982,69 e' un'uscita anche in bianco e nero.
                    ->color(fn (EurekaCashflowVoce $r) => $r->eEntrata() ? 'success' : 'danger')
                    ->summarize(Tables\Columns\Summarizers\Sum::make()->label('Saldo')->money('EUR')),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('mese')
                    ->label('Mese')
                    ->options(fn (): array => EurekaCashflowVoce::query()
                        ->where('tenant_id', Filament::getTenant()?->id)
                        ->orderBy('anno')
                        ->orderBy('mese')
                        ->get(['anno', 'mese'])
                        ->unique(fn (EurekaCashflowVoce $v) => $v->anno.'-'.$v->mese)
                        ->mapWithKeys(fn (EurekaCashflowVoce $v) => [
                            $v->anno.'-'.$v->mese => ucfirst(mb_substr(
                                Carbon::create($v->anno, $v->mese, 1)->translatedFormat('F'),
                                0,
                                3,
                            )).' '.$v->anno,
                        ])
                        ->all())
                    ->query(function (Builder $query, array $data): Builder {
                        if (blank($data['value'] ?? null)) {
                            return $query;
                        }

                        [$anno, $mese] = explode('-', (string) $data['value']);

                        return $query->where('anno', (int) $anno)->where('mese', (int) $mese);
                    }),

                Tables\Filters\SelectFilter::make('verso')
                    ->label('Verso')
                    ->options(['entrate' => 'Solo entrate', 'uscite' => 'Solo uscite'])
                    ->query(fn (Builder $query, array $data): Builder => match ($data['value'] ?? null) {
                        'entrate' => $query->where('importo', '>', 0),
                        'uscite' => $query->where('importo', '<', 0),
                        default => $query,
                    }),
            ])
            ->defaultSort('data_scadenza')
            ->paginated([25, 50, 100])
            ->emptyStateHeading('Nessuna previsione')
            ->emptyStateDescription('Nessuna scadenza o documento aperto nel periodo. I dati si aggiornano con eureka:import-kpi-contabili.');
    }
}
