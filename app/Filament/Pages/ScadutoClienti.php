<?php

namespace App\Filament\Pages;

use App\Filament\Concerns\ApreStampeInNuovaScheda;
use App\Jobs\AggiornaSaldiEurekaJob;
use App\Models\EurekaPartitaAperta;
use App\Support\DisplayName;
use App\Support\OutsideLivewireRender;
use App\Support\Pdf\StampaTemporanea;
use Barryvdh\DomPDF\Facade\Pdf;
use BezhanSalleh\FilamentShield\Traits\HasPageShield;
use Filament\Actions;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Tables;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;

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
 * Esclusi in modo permanente, non con un filtro: i fornitori.
 *
 * Le partite SENZA numero di fattura invece ci sono, dal 23/09/2026. Prima
 * venivano scartate tutte perche' "non corrispondono a un credito verso un
 * documento preciso", ma sotto quella regola cadevano due cose diverse e
 * l'elenco sbagliava in entrambe le direzioni:
 *
 *  - i riporti di apertura del vecchio gestionale sono crediti veri, e
 *    qualcuno ci paga sopra (su Santa Sofia Venezia il riporto da 641,32 ha
 *    un incasso da 351,78 imputato): Cose Buone risultava dovere 261,08
 *    invece di 4.405,01, e nove clienti le cui partite sono solo riporti
 *    non comparivano affatto, per 22.230,25;
 *  - gli incassi che Eureka non abbina a una fattura precisa sono soldi
 *    gia' arrivati: la Strana Coppia figurava da chiamare per 335,74 mentre
 *    il gestionale la dava a credito di 118,01.
 *
 * Adesso l'elenco somma le stesse partite del dettaglio cliente
 * (DettaglioScaduto), che e' la schermata che si tiene aperta al telefono:
 * due pagine che per lo stesso cliente dicono numeri diversi sono un modo
 * sicuro di non farsi credere da nessuna delle due.
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
    use ApreStampeInNuovaScheda, InteractsWithTable;

    /**
     * Numeri contabili dell'azienda. Fino al 03/09/2026 il cancello era
     * is_super_admin nel codice: o eri staff master o non li vedevi, e la
     * pagina restava fuori dalla matrice di Shield.
     *
     * Dal 04/09/2026 e' un permesso come gli altri (indicazione
     * dell'utente: nella schermata dei privilegi ci deve essere tutto).
     * Chi ha is_super_admin passa comunque, per il Gate::before in
     * AppServiceProvider, quindi nessuno perde l'accesso: quello che
     * cambia e' che ora si puo' concedere a un ruolo.
     */
    use HasPageShield;

    protected static ?string $navigationIcon = 'heroicon-o-phone-arrow-up-right';

    protected static ?string $navigationGroup = 'Amministrazione';

    protected static ?int $navigationSort = 1;

    protected static ?string $navigationLabel = 'Scaduto clienti';

    protected static ?string $title = 'Scaduto clienti';

    protected static string $view = 'filament.pages.scaduto-clienti';

    protected static ?string $slug = 'scaduto';

    /**
     * Due letture dello stesso archivio.
     *
     * Spenta (il default) la pagina e' l'elenco delle telefonate: solo chi ha
     * qualcosa di gia' scaduto e resta a debito. Accesa mostra il SALDO di
     * ogni anagrafica con una partita aperta, scadenze future comprese e
     * crediti compresi.
     *
     * Serve perche' finora quei saldi non si vedevano da nessuna parte:
     * chi voleva sapere a quanto sta un cliente doveva aprire Eureka.
     * Ristorante alla Grigliata (273,52, ma in scadenza il 30/11) e Moka
     * Efti (244,00 a credito) non comparivano da nessuna parte nel CRM, pur
     * essendo scritti giusti nei nostri dati — bastava non poterli
     * guardare. Indicazione dell'utente, 23/09/2026.
     */
    public bool $tuttiISaldi = false;

    public function getSubheading(): ?string
    {
        return $this->tuttiISaldi
            ? 'Il saldo di ogni cliente con una partita aperta, scadenze future e crediti compresi. È il numero del gestionale, non l\'elenco delle telefonate.'
            : 'Chi chiamare, in ordine di urgenza. Importi al netto di note di credito e incassi non ancora imputati, riporti di apertura compresi: quello che il cliente deve davvero.';
    }

    /**
     * Partite senza numero di fattura: i riporti di apertura del nuovo
     * gestionale (a dare) e gli incassi che Eureka non ha abbinato a una
     * fattura precisa (ad avere). Fino al 23/09/2026 erano scartate tutte
     * e due, con lo stesso filtro — vedi il commento di classe.
     */
    private const SENZA_NUMERO = "(numero_fattura IS NULL OR numero_fattura = '')";

    /**
     * La data entro cui la partita andava pagata. Un riporto di apertura non
     * ha scadenza: vale la sua data, che e' il giorno in cui il saldo e'
     * stato riportato (01/01/2023), ed e' abbondantemente passata.
     */
    private const SCADENZA_EFFETTIVA = 'COALESCE(data_scadenza, data_fattura)';

    /**
     * La scadenza piu' vecchia si misura SOLO sul dare, ovunque la si usi:
     * in colonna, nell'ordinamento e nel peso. Da quando le note di credito
     * entrano nel gruppo, un MIN(data_scadenza) nudo poteva prendere la data
     * di una nota di credito e far sembrare ferma da anni una fattura di
     * ieri.
     */
    private const SCADENZA_PIU_VECCHIA = 'MIN(CASE WHEN saldo > 0 THEN '.self::SCADENZA_EFFETTIVA.' END)';

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
     * Da quanto aspetta quella riga, o quando scadra'. Una sola definizione
     * per la colonna e per la stampa: erano gia' due copie della stessa
     * frase, e con le scadenze future sarebbero diventate due copie dello
     * stesso "-68 giorni".
     */
    private static function attesa(mixed $piuVecchia): string
    {
        if (! $piuVecchia) {
            return '—';
        }

        return Carbon::parse($piuVecchia)->isFuture()
            ? 'scade il '.Carbon::parse($piuVecchia)->format('d/m/Y')
            : self::giorni($piuVecchia).' giorni';
    }

    /**
     * Di che cosa e' fatto il debito, sotto il nome del cliente: quante
     * fatture e, se c'e', il riporto di apertura. Il riporto va nominato
     * perche' al telefono non si puo' citare un numero di documento — si
     * dice "il saldo riportato dal vecchio gestionale".
     */
    private static function composizione(mixed $record): string
    {
        $parti = [];

        if (($fatture = (int) $record->fatture) > 0) {
            $parti[] = $fatture.' '.($fatture === 1 ? 'fattura' : 'fatture');
        }

        if ((float) $record->riporti > 0) {
            $parti[] = 'riporto di apertura '.self::euro($record->riporti);
        }

        return implode(' · ', $parti);
    }

    /**
     * Il conto in chiaro solo quando serve: se non c'e' niente ad avere,
     * netto e lordo coincidono e ripeterlo sarebbe rumore.
     *
     * Note di credito e incassi non imputati si nominano per quello che
     * sono: chiamare "nota di credito" un incasso farebbe cercare al
     * cliente un documento che non esiste.
     */
    private static function detrazioni(mixed $record): ?string
    {
        $crediti = abs((float) $record->crediti);

        if ($crediti === 0.0) {
            return null;
        }

        $incassi = abs((float) $record->incassi);

        $voce = match (true) {
            $incassi === 0.0 => 'di note di credito',
            $incassi >= $crediti => 'di incassi non imputati',
            default => 'fra note di credito e incassi non imputati',
        };

        return self::euro($record->lordo).' − '.self::euro($crediti).' '.$voce;
    }

    /**
     * Stampa dell'elenco: e' la lista con cui si telefona, e al telefono si
     * segna a penna. Esce quello che si vede a schermo — stesso ordine,
     * stessa ricerca (getFilteredSortedTableQuery), stesse diciture sotto i
     * nomi (composizione/detrazioni sono le stesse dei due TextColumn),
     * senza la paginazione: stampare solo i primi 25 di una lista ordinata
     * per urgenza vorrebbe dire perdere per strada proprio chi va
     * richiamato domani.
     */
    protected function getHeaderActions(): array
    {
        return [
            // Il cambio di modo sta in testata e non fra i filtri: un filtro
            // Filament aggiunge condizioni, mentre qui bisogna TOGLIERE
            // quelle che fanno dell'elenco una lista di telefonate (solo
            // scaduto, solo chi resta a debito).
            Actions\Action::make('modo')
                ->label(fn () => $this->tuttiISaldi ? 'Solo chi è scaduto' : 'Tutti i saldi')
                ->icon(fn () => $this->tuttiISaldi ? 'heroicon-o-phone-arrow-up-right' : 'heroicon-o-list-bullet')
                ->color('gray')
                ->action(function () {
                    $this->tuttiISaldi = ! $this->tuttiISaldi;
                    $this->resetTable();
                }),

            // Le partite girano due volte al giorno (vedi routes/console.php):
            // quando si sta telefonando ai clienti serve la fotografia di
            // adesso, non quella di stamattina (23/09/2026).
            Actions\Action::make('aggiornaSaldi')
                ->label('Aggiorna saldi da Eureka')
                ->icon('heroicon-o-arrow-path')
                ->color('gray')
                ->requiresConfirmation()
                ->modalHeading('Rileggere saldi e partite da Eureka?')
                ->modalDescription('Partite aperte, fatture e indicatori, come ogni notte. Ci vuole qualche minuto: ti avviso qui quando ha finito.')
                ->action(function () {
                    AggiornaSaldiEurekaJob::dispatch(Filament::getTenant(), Auth::user());

                    Notification::make()
                        ->title('Aggiornamento saldi avviato')
                        ->body('Verrai avvisato qui quando termina.')
                        ->success()
                        ->send();
                }),

            Actions\Action::make('stampa')
                ->label('Stampa')
                ->icon('heroicon-o-printer')
                ->color('gray')
                ->action(function () {
                    $righe = $this->getFilteredSortedTableQuery()->get()->map(fn ($record) => [
                        'cliente' => DisplayName::titleCase($record->ragione_sociale),
                        'composizione' => self::composizione($record),
                        'detrazioni' => self::detrazioni($record),
                        'scaduto' => (float) $record->scaduto,
                        'attesa' => self::attesa($record->piu_vecchia),
                        'giorni' => $record->piu_vecchia && ! Carbon::parse($record->piu_vecchia)->isFuture()
                            ? self::giorni($record->piu_vecchia)
                            : null,
                        'piu_vecchia' => $record->piu_vecchia ? Carbon::parse($record->piu_vecchia)->format('d/m/Y') : null,
                    ])->all();

                    // Vedi App\Support\OutsideLivewireRender: il rendering
                    // parte da dentro un'azione Livewire e senza questo il PDF
                    // si porta dietro i commenti <!--[if BLOCK]--> attorno a
                    // ogni @if.
                    $pdf = OutsideLivewireRender::run(fn () => Pdf::loadView('pdf.scaduto-clienti', [
                        'righe' => $righe,
                        'saldi' => $this->tuttiISaldi,
                        'tenant' => Filament::getTenant(),
                        'ricerca' => $this->getTableSearch(),
                        'data' => now()->format('d/m/Y'),
                        'totale' => array_sum(array_column($righe, 'scaduto')),
                        'attesaMassima' => $righe ? max(array_map(fn ($r) => $r['giorni'] ?? 0, $righe)) : null,
                    ]));

                    // In una scheda nuova, non scaricato: l'elenco resta
                    // dov'era con la sua ricerca, che e' anche quella che
                    // questa stampa segue.
                    static::apriUrlInNuovaScheda(
                        StampaTemporanea::parcheggia(
                            $pdf->output(),
                            ($this->tuttiISaldi ? 'saldi-clienti-' : 'scaduto-clienti-').now()->format('Y-m-d').'.pdf',
                        ),
                        $this,
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
                // Riporti e incassi non imputati si tengono a parte solo
                // per poterli NOMINARE sotto al totale: nel totale ci sono
                // gia' dentro.
                ->selectRaw('SUM(CASE WHEN saldo > 0 AND '.self::SENZA_NUMERO.' THEN saldo ELSE 0 END) as riporti')
                ->selectRaw('SUM(CASE WHEN saldo < 0 AND '.self::SENZA_NUMERO.' THEN saldo ELSE 0 END) as incassi')
                // Le fatture si contano solo sul dare e solo dove un numero
                // di documento c'e': una nota di credito non e' una fattura
                // da sollecitare, e un riporto non ha un numero da citare.
                ->selectRaw('SUM(CASE WHEN saldo > 0 AND NOT '.self::SENZA_NUMERO.' THEN 1 ELSE 0 END) as fatture')
                ->selectRaw(self::SCADENZA_PIU_VECCHIA.' as piu_vecchia')
                ->where('tenant_id', Filament::getTenant()?->id)
                ->where('tipo', EurekaPartitaAperta::TIPO_CLIENTE)
                // Del dare entra solo cio' che e' gia' scaduto, dell'avere
                // tutto: una nota di credito abbassa quello che il cliente
                // deve a prescindere dalla sua data. Nel modo "tutti i
                // saldi" non si taglia niente: il saldo e' il saldo.
                ->when(! $this->tuttiISaldi, fn (Builder $q) => $q
                    ->where(fn (Builder $q) => $q
                        ->where(fn (Builder $q) => $q
                            ->where('saldo', '>', 0)
                            ->whereRaw(self::SCADENZA_EFFETTIVA.' < ?', [now()->toDateString()]))
                        ->orWhere('saldo', '<', 0)))
                ->groupBy('gestionale_code')
                // Il netto positivo e' la condizione per comparire, e visto
                // che le partite positive qui dentro sono solo quelle
                // scadute garantisce anche che ce ne sia almeno una: chi ha
                // solo note di credito, o piu' credito che debito, non e'
                // qualcuno da chiamare. Di nuovo, non vale per i saldi: un
                // cliente a credito ha un saldo, e va visto.
                ->when(! $this->tuttiISaldi, fn (Builder $q) => $q->havingRaw('SUM(saldo) > 0')))
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
                    ->description(fn ($record) => self::composizione($record)),

                Tables\Columns\TextColumn::make('scaduto')
                    // La colonna e' la stessa somma, ma nei due modi dice
                    // due cose diverse: chiamarla "Scaduto" accanto a una
                    // fattura che scade fra due mesi sarebbe falso.
                    ->label(fn () => $this->tuttiISaldi ? 'Saldo' : 'Scaduto')
                    ->color(fn ($record) => (float) $record->scaduto < 0 ? 'success' : null)
                    ->sortable(query: fn (Builder $query, string $direction): Builder => $query->orderByRaw("SUM(saldo) {$direction}"))
                    ->money('EUR')
                    ->alignEnd()
                    ->weight('bold')
                    ->description(fn ($record) => self::detrazioni($record)),

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
                    // Con i saldi in vista entrano anche le scadenze future,
                    // e li' diffInDays() torna un numero negativo: "-68
                    // giorni" non lo legge nessuno. Una partita che deve
                    // ancora scadere dice quando scade.
                    ->formatStateUsing(fn ($state) => self::attesa($state))
                    ->color(fn ($state) => match (true) {
                        ! $state => 'gray',
                        Carbon::parse($state)->isFuture() => 'success',
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
            ->emptyStateHeading(fn () => $this->tuttiISaldi ? 'Nessuna partita aperta' : 'Nessuno scaduto')
            ->emptyStateDescription(fn () => $this->tuttiISaldi
                ? 'Nessun cliente ha partite aperte su Eureka. I dati si aggiornano con eureka:import-partite-aperte.'
                : 'Nessun cliente ha fatture scadute. I dati si aggiornano con eureka:import-partite-aperte.')
            ->paginated([25, 50, 100]);
    }
}
