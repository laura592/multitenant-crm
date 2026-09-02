<?php

namespace App\Filament\Pages;

use App\Models\EurekaPartitaAperta;
use App\Support\DisplayName;
use App\Support\OutsideLivewireRender;
use Barryvdh\DomPDF\Facade\Pdf;
use Filament\Actions;
use Filament\Facades\Filament;
use Filament\Pages\Page;
use Filament\Tables;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

/**
 * Lo scaduto clienti, per chi deve telefonare e farsi pagare.
 *
 * UNA RIGA PER CLIENTE, non una per fattura: chi sollecita fa una telefonata
 * e parla di tutte le fatture insieme. La prima versione elencava le 333
 * partite singole ed era inutilizzabile — otto pagine da scorrere per capire
 * chi chiamare. Le fatture del singolo cliente stanno in DettaglioScaduto.
 *
 * Pagina e non Resource: la tabella è un'aggregazione (GROUP BY anagrafica) e
 * l'astrazione Resource di Filament assume una riga per record, tanto che
 * Table::getModel() esplode su una query raggruppata. Qui la query è
 * esplicita e sotto controllo.
 *
 * Ordine di default per PESO (importo × giorni di ritardo), non per importo:
 * 500 € fermi da un anno vengono prima di 2.000 € scaduti la settimana
 * scorsa, perché è la telefonata più urgente.
 *
 * Esclusi in modo permanente, non con un filtro: le scritture di apertura
 * del nuovo gestionale (riconoscibili dal numero di fattura mancante: sono
 * un saldo riportato, non un credito verso una fattura precisa) e i
 * fornitori.
 *
 * L'importo e' NETTO delle note di credito, come il saldo del dettaglio:
 * al telefono si chiede quello che il cliente deve davvero, non il lordo
 * delle fatture. Prima la riga sommava solo le partite positive e diceva
 * 300 EUR a un cliente che ne doveva 250, e il dettaglio lo smentiva.
 * Chi ha piu' credito che debito sparisce dall'elenco: non c'e' niente da
 * chiedergli. Il riquadro in testa invece tiene lordo e crediti separati di
 * proposito (vedi ScadutoOverviewWidget): li' e' un totale di cassa, qui e'
 * una telefonata.
 */
class ScadutoClienti extends Page implements HasTable
{
    use InteractsWithTable;

    protected static ?string $navigationIcon = 'heroicon-o-phone-arrow-up-right';

    protected static ?string $navigationGroup = 'Amministrazione';

    protected static ?int $navigationSort = 1;

    protected static ?string $navigationLabel = 'Scaduto clienti';

    protected static ?string $title = 'Scaduto clienti';

    protected static string $view = 'filament.pages.scaduto-clienti';

    protected static ?string $slug = 'scaduto';

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
        return 'Chi chiamare, in ordine di urgenza. Importi al netto delle note di credito; il riporto di apertura 2023 è escluso.';
    }

    /**
     * La scadenza piu' vecchia si misura SOLO sul dare, ovunque la si usi:
     * in colonna, nell'ordinamento e nel peso. Da quando le note di credito
     * entrano nel gruppo, un MIN(data_scadenza) nudo poteva prendere la data
     * di una nota di credito e far sembrare ferma da anni una fattura di
     * ieri.
     */
    private const SCADENZA_PIU_VECCHIA = 'MIN(CASE WHEN saldo > 0 THEN data_scadenza END)';

    /**
     * Ordinamento per peso: importo × giorni di ritardo.
     *
     * L'espressione dipende dal database perché l'aritmetica sulle date non
     * è portabile: MySQL (produzione) ha DATEDIFF, SQLite (test) ha
     * julianday. Il calcolo resta a livello di query e non in PHP perché
     * serve a ordinare e paginare lato database: farlo dopo il fetch
     * ordinerebbe solo la pagina corrente.
     *
     * La data di riferimento è passata come parametro invece di CURDATE()
     * per lo stesso motivo di portabilità, e in più rende la query
     * deterministica a parità di giorno.
     */
    private static function ordinamentoPerPeso(): string
    {
        $piuVecchia = self::SCADENZA_PIU_VECCHIA;

        return EurekaPartitaAperta::query()->getConnection()->getDriverName() === 'sqlite'
            ? "SUM(saldo) * (julianday(?) - julianday({$piuVecchia})) DESC"
            : "SUM(saldo) * DATEDIFF(?, {$piuVecchia}) DESC";
    }

    /** Importo in formato italiano, per le descrizioni sotto le colonne. */
    private static function euro(mixed $valore): string
    {
        return '€ '.number_format((float) $valore, 2, ',', '.');
    }

    /** Giorni interi trascorsi da una data, senza la parte decimale. */
    private static function giorni(mixed $data): int
    {
        return (int) Carbon::parse($data)->diffInDays(now());
    }

    /**
     * Stampa dell'elenco: e' la lista con cui si telefona, e al telefono si
     * segna a penna. Esce quello che si vede a schermo — stesso ordine,
     * stessa ricerca (getFilteredSortedTableQuery), senza la paginazione:
     * stampare solo i primi 25 di una lista ordinata per urgenza vorrebbe
     * dire perdere per strada proprio chi va richiamato domani.
     */
    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('stampa')
                ->label('Stampa')
                ->icon('heroicon-o-printer')
                ->color('gray')
                ->action(function () {
                    $righe = $this->getFilteredSortedTableQuery()->get()->map(fn ($record) => [
                        'cliente' => DisplayName::titleCase($record->ragione_sociale),
                        'fatture' => (int) $record->fatture,
                        'scaduto' => (float) $record->scaduto,
                        'lordo' => (float) $record->lordo,
                        'crediti' => abs((float) $record->crediti),
                        'giorni' => $record->piu_vecchia ? self::giorni($record->piu_vecchia) : null,
                        'piu_vecchia' => $record->piu_vecchia ? Carbon::parse($record->piu_vecchia)->format('d/m/Y') : null,
                    ])->all();

                    // Vedi App\Support\OutsideLivewireRender: il rendering
                    // parte da dentro un'azione Livewire e senza questo il PDF
                    // si porta dietro i commenti <!--[if BLOCK]--> attorno a
                    // ogni @if.
                    $pdf = OutsideLivewireRender::run(fn () => Pdf::loadView('pdf.scaduto-clienti', [
                        'righe' => $righe,
                        'tenant' => Filament::getTenant(),
                        'ricerca' => $this->getTableSearch(),
                        'data' => now()->format('d/m/Y'),
                        'totale' => array_sum(array_column($righe, 'scaduto')),
                        'attesaMassima' => $righe ? max(array_map(fn ($r) => $r['giorni'] ?? 0, $righe)) : null,
                    ]));

                    return response()->streamDownload(
                        fn () => print ($pdf->output()),
                        'scaduto-clienti-'.now()->format('Y-m-d').'.pdf',
                    );
                }),
        ];
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(fn (): Builder => EurekaPartitaAperta::query()
                // MIN(id) serve solo a dare a Filament una chiave di riga
                // valida: senza, le righe raggruppate avrebbero tutte la
                // stessa identità.
                ->selectRaw('MIN(id) as id, gestionale_code, MAX(customer_id) as customer_id, MAX(ragione_sociale) as ragione_sociale')
                // Lordo, crediti e netto arrivano dalla stessa riga: il
                // netto e' il numero da chiedere, gli altri due servono a
                // spiegarlo quando non coincidono.
                ->selectRaw('SUM(saldo) as scaduto')
                ->selectRaw('SUM(CASE WHEN saldo > 0 THEN saldo ELSE 0 END) as lordo')
                ->selectRaw('SUM(CASE WHEN saldo < 0 THEN saldo ELSE 0 END) as crediti')
                // Le fatture si contano e si datano solo sul dare: una nota
                // di credito non e' una fattura da sollecitare e non e' mai
                // la partita "ferma da piu' tempo".
                ->selectRaw('SUM(CASE WHEN saldo > 0 THEN 1 ELSE 0 END) as fatture')
                ->selectRaw(self::SCADENZA_PIU_VECCHIA.' as piu_vecchia')
                ->where('tenant_id', Filament::getTenant()?->id)
                ->where('tipo', EurekaPartitaAperta::TIPO_CLIENTE)
                // Si escludono le scritture di apertura del nuovo gestionale
                // riconoscendole dal NUMERO DI FATTURA mancante, non
                // dall'anno. Filtrare "anno > 2023" sembrava equivalente ma
                // buttava via anche le fatture vere del 2023: Pasti Fabio
                // aveva la 513 del 15/12/2023 da 1.658,83 EUR che spariva
                // dall'elenco, e il cliente risultava dovere 200 EUR invece
                // di 1.859,52.
                ->whereNotNull('numero_fattura')
                ->where('numero_fattura', '<>', '')
                // Del dare entra solo cio' che e' gia' scaduto, dell'avere
                // tutto: una nota di credito abbassa quello che il cliente
                // deve a prescindere dalla sua data.
                ->where(fn (Builder $q) => $q
                    ->where(fn (Builder $q) => $q
                        ->where('saldo', '>', 0)
                        ->whereNotNull('data_scadenza')
                        ->whereDate('data_scadenza', '<', now()))
                    ->orWhere('saldo', '<', 0))
                ->groupBy('gestionale_code')
                // Il netto positivo e' la condizione per comparire, e visto
                // che le partite positive qui dentro sono solo quelle
                // scadute garantisce anche che ce ne sia almeno una: chi ha
                // solo note di credito, o piu' credito che debito, non e'
                // qualcuno da chiamare.
                ->havingRaw('SUM(saldo) > 0'))
            ->columns([
                Tables\Columns\TextColumn::make('ragione_sociale')
                    ->label('Cliente')
                    ->searchable()
                    // Ogni colonna ordina con la propria espressione
                    // aggregata: la query e' raggruppata, quindi un
                    // ORDER BY sulla colonna nuda (quello che Filament
                    // genererebbe da solo) violerebbe ONLY_FULL_GROUP_BY.
                    ->sortable(query: fn (Builder $query, string $direction): Builder => $query->orderByRaw("MAX(ragione_sociale) {$direction}"))
                    ->weight('medium')
                    ->formatStateUsing(fn (?string $state) => DisplayName::titleCase($state))
                    ->description(fn ($record) => $record->fatture.' '.($record->fatture == 1 ? 'fattura' : 'fatture')),

                Tables\Columns\TextColumn::make('scaduto')
                    ->label('Scaduto')
                    ->sortable(query: fn (Builder $query, string $direction): Builder => $query->orderByRaw("SUM(saldo) {$direction}"))
                    ->money('EUR')
                    ->alignEnd()
                    ->weight('bold')
                    // Il conto in chiaro solo quando serve: se non ci sono
                    // note di credito netto e lordo coincidono e ripeterlo
                    // sarebbe rumore.
                    ->description(fn ($record) => (float) $record->crediti !== 0.0
                        ? self::euro($record->lordo).' − '.self::euro(abs((float) $record->crediti)).' di note di credito'
                        : null),

                Tables\Columns\TextColumn::make('piu_vecchia')
                    ->label('Ferma da')
                    // La colonna mostra GIORNI, la query ordina per DATA:
                    // le due scale sono invertite, quindi la direzione va
                    // ribaltata. Altrimenti la freccia "crescente" mette in
                    // cima le fatture ferme da piu' tempo e sembra rotta.
                    ->sortable(query: fn (Builder $query, string $direction): Builder => $query->orderByRaw(self::SCADENZA_PIU_VECCHIA.' '.($direction === 'asc' ? 'desc' : 'asc')))
                    ->badge()
                    // (int) non e' pignoleria: diffInDays() restituisce un
                    // float, quindi senza cast in colonna finisce
                    // "397.73471022177 giorni".
                    ->formatStateUsing(fn ($state) => $state ? self::giorni($state).' giorni' : '—')
                    ->color(fn ($state) => match (true) {
                        ! $state => 'gray',
                        self::giorni($state) > 180 => 'danger',
                        self::giorni($state) > 60 => 'warning',
                        default => 'info',
                    }),
            ])
            ->actions([
                Tables\Actions\Action::make('dettaglio')
                    ->label('Vedi fatture')
                    ->icon('heroicon-m-arrow-right')
                    ->url(fn ($record) => DettaglioScaduto::getUrl(['codice' => $record->gestionale_code])),
            ])
            // Il peso resta l'ordine di partenza, ma da qui in poi e' solo un
            // default: cliccando un'intestazione l'utente lo scavalca. Sta
            // in defaultSort e non piu' nella query perche' un orderBy nella
            // query verrebbe prima di quello scelto dall'utente, che
            // diventerebbe un criterio secondario e quindi inefficace.
            ->defaultSort(fn (Builder $query): Builder => $query->orderByRaw(self::ordinamentoPerPeso(), [now()->toDateString()]))
            ->recordUrl(fn ($record) => DettaglioScaduto::getUrl(['codice' => $record->gestionale_code]))
            ->emptyStateHeading('Nessuno scaduto')
            ->emptyStateDescription('Nessun cliente ha fatture scadute. I dati si aggiornano con eureka:import-partite-aperte.')
            ->paginated([25, 50, 100]);
    }
}
