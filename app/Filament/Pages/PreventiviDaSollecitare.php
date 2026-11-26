<?php

namespace App\Filament\Pages;

use App\Filament\Concerns\ApreStampeInNuovaScheda;
use App\Filament\Resources\QuoteGroupResource;
use App\Filament\Resources\QuoteResource;
use App\Models\Quote;
use App\Models\Tenant;
use App\Support\DisplayName;
use App\Support\OutsideLivewireRender;
use App\Support\Pdf\StampaTemporanea;
use Barryvdh\DomPDF\Facade\Pdf;
use BezhanSalleh\FilamentShield\Traits\HasPageShield;
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
 * I preventivi mandati e mai risposti: chi richiamare per avere un si' o
 * un no.
 *
 * UNA RIGA PER TRATTATIVA, non una per preventivo. Un'offerta con sei
 * alternative sono sei righe nella tabella dei preventivi ma una sola
 * telefonata, e sommandole l'elenco mentirebbe di sei volte sul valore in
 * ballo. Del gruppo si mostra l'alternativa piu' cara: e' il massimo che
 * quella trattativa puo' valere, non la somma di sei macchine che il
 * cliente comprera' una sola.
 *
 * Pagina e non Resource, per lo stesso motivo di ScadutoClienti: la query
 * e' un'aggregazione (GROUP BY trattativa) e Table::getModel() di Filament
 * assume una riga per record.
 *
 * Ci sono DENTRO anche gli scaduti, non solo quelli ancora validi. Uno
 * stato "scaduto" vuol dire che la nostra offerta e' decaduta, non che il
 * cliente ha detto no: la telefonata serve ancora, e anzi serve di piu'.
 * Se la pagina mostrasse solo gli "inviato", il giorno dopo il primo giro
 * di preventivi:scadi si svuoterebbe da sola — proprio mentre il lavoro
 * da fare era al massimo.
 *
 * Ordine di default per PESO (importo × giorni di silenzio), come lo
 * scaduto clienti: 3.000 EUR fermi da otto mesi vengono prima di 20.000
 * mandati la settimana scorsa, perche' e' la telefonata piu' urgente. Il
 * preventivo di ieri non va richiamato, va aspettato.
 *
 * I giorni di silenzio si contano dall'ULTIMO INVIO, non dalla data del
 * preventivo: se un'offerta di marzo e' stata rimandata a ottobre, il
 * cliente tace da ottobre. Gli invii stanno in quote_emails (il singolo
 * preventivo) e quote_group_emails (l'offerta con alternative), e valgono
 * solo quelli andati a buon fine: una mail "failed" non e' un contatto.
 *
 * Fuori restano le bozze (mai uscite di qui, non c'e' niente da
 * sollecitare) e tutto cio' che una risposta l'ha avuta.
 */
class PreventiviDaSollecitare extends Page implements HasTable
{
    use ApreStampeInNuovaScheda, HasPageShield, InteractsWithTable;

    protected static ?string $navigationIcon = 'heroicon-o-chat-bubble-left-right';

    protected static ?string $navigationGroup = 'Vendite';

    protected static ?int $navigationSort = 4;

    protected static ?string $navigationLabel = 'Da sollecitare';

    protected static ?string $title = 'Preventivi da sollecitare';

    protected static string $view = 'filament.pages.preventivi-da-sollecitare';

    protected static ?string $slug = 'da-sollecitare';

    /**
     * Sotto questa soglia non si telefona: un preventivo di tre giorni non
     * e' un silenzio, e' un cliente che sta ancora pensando. Sette giorni
     * perche' il 67% dei si' arriva entro una settimana (vedi
     * Quote::GIORNI_VALIDITA): dopo, l'attesa comincia a voler dire
     * qualcosa.
     */
    public const GIORNI_MINIMI = 7;

    public function getSubheading(): ?string
    {
        return 'Chi richiamare per avere un sì o un no, in ordine di urgenza. '
            .'Una riga per trattativa: le alternative della stessa offerta sono una sola telefonata.';
    }

    /**
     * La chiave della trattativa: il gruppo se il preventivo sta in
     * un'offerta con alternative, altrimenti se stesso.
     */
    private const TRATTATIVA = 'COALESCE(quotes.quote_group_id, quotes.id)';

    /**
     * L'ultimo invio andato a buon fine della trattativa.
     *
     * Le mail stanno in due tabelle — quelle del singolo preventivo e
     * quelle dell'offerta di gruppo — e l'ultimo contatto e' la piu'
     * recente fra le due. Il fondo finto serve perche' GREATEST torna NULL
     * se uno degli argomenti e' NULL (e una trattativa ha quasi sempre una
     * sola delle due); il NULLIF lo ritrasforma in NULL dopo
     * l'aggregazione, cosi' "mai inviato" resta distinguibile da "inviato
     * nell'anno mille".
     */
    private const MAI = "'1000-01-01 00:00:00'";

    /*
     * Le espressioni qui sotto sono MySQL (GREATEST, DATEDIFF) e non
     * pretendono di essere portabili: la suite di test, l'unica cosa che
     * girasse su SQLite, e' stata tolta di proposito (432ec7bb). Scrivere
     * due rami per un motore che nessuno usa sarebbe codice che nessuno
     * esegue.
     */

    private static function ultimoInvio(): string
    {
        $singolo = '(SELECT MAX(qe.created_at) FROM quote_emails qe'
            ." WHERE qe.quote_id = quotes.id AND qe.status = 'sent' AND qe.deleted_at IS NULL)";

        $gruppo = '(SELECT MAX(qge.created_at) FROM quote_group_emails qge'
            ." WHERE qge.quote_group_id = quotes.quote_group_id AND qge.status = 'sent' AND qge.deleted_at IS NULL)";

        $singolo = "COALESCE({$singolo}, ".self::MAI.')';
        $gruppo = "COALESCE({$gruppo}, ".self::MAI.')';

        return "NULLIF(MAX(GREATEST({$singolo}, {$gruppo})), ".self::MAI.')';
    }

    /**
     * Da quando il cliente tace: l'ultimo invio se lo conosciamo, la data
     * del preventivo altrimenti. I preventivi importati e quelli mandati a
     * mano fuori dal CRM non hanno una mail in archivio, e scartarli
     * vorrebbe dire non richiamare proprio i piu' vecchi.
     */
    private static function silenzioDa(): string
    {
        return 'COALESCE('.self::ultimoInvio().', MIN(quotes.date))';
    }

    /**
     * Ordinamento per peso: importo × giorni di silenzio. Resta a livello
     * di query per poter ordinare e paginare lato database; la data di
     * riferimento e' un parametro invece di CURDATE() per portabilita' e
     * per rendere la query deterministica a parita' di giorno.
     */
    private static function ordinamentoPerPeso(): string
    {
        return 'MAX(quotes.total) * DATEDIFF(?, '.self::silenzioDa().') DESC';
    }

    /**
     * Il tenant della pagina.
     *
     * instanceof e non un truthy: Filament::getTenant() e' tipizzato
     * ?Model, e senza il controllo l'analisi statica non sa che quel Model
     * ha un id (stessa ragione di App\Models\Concerns\BelongsToTenant).
     * Senza tenant la query non trova niente, che e' il verso giusto in cui
     * sbagliare: meglio una pagina vuota che i preventivi di un'altra
     * azienda.
     */
    private static function tenantId(): ?string
    {
        $tenant = Filament::getTenant();

        return $tenant instanceof Tenant ? $tenant->id : null;
    }

    /** Giorni interi trascorsi da una data, senza la parte decimale. */
    private static function giorni(mixed $data): int
    {
        return (int) Carbon::parse($data)->diffInDays(now());
    }

    /**
     * Il numero da citare al telefono: quello dell'offerta se ci sono
     * alternative, quello del preventivo se e' da solo. Piu', quando sono
     * piu' di una, quante sono: serve a sapere su cosa si sta chiedendo
     * una risposta.
     */
    private static function riferimento(mixed $record): string
    {
        $parti = [$record->numero_gruppo ?: $record->numero];

        if (($alternative = (int) $record->alternative) > 1) {
            $parti[] = $alternative.' alternative';
        }

        if (! $record->ancora_valido) {
            $parti[] = $record->scadenza
                ? 'scaduto il '.Carbon::parse($record->scadenza)->format('d/m/Y')
                : 'scaduto';
        }

        return implode(' · ', $parti);
    }

    /**
     * Che cosa gli abbiamo mandato e quando. "Mai inviato dal CRM" non e'
     * un rimprovero: i preventivi di prima dell'archivio mail, e quelli
     * consegnati a mano, sono legittimi — solo, la data del silenzio la
     * prendiamo dal preventivo e non dalla mail.
     */
    private static function contatto(mixed $record): string
    {
        if (! $record->ultimo_invio) {
            return 'mai inviato dal CRM · del '
                .($record->data_invio ? Carbon::parse($record->data_invio)->format('d/m/Y') : '—');
        }

        $invio = Carbon::parse($record->ultimo_invio);
        $data = $record->data_invio ? Carbon::parse($record->data_invio) : null;

        // Un reinvio si nomina: al telefono cambia la frase da dire
        // ("come da nostra mail del..." invece di "le avevamo mandato").
        return $data && $invio->toDateString() !== $data->toDateString()
            ? 'rimandato il '.$invio->format('d/m/Y').' · del '.$data->format('d/m/Y')
            : 'inviato il '.$invio->format('d/m/Y');
    }

    /** L'indirizzo dove e' finito l'ultimo invio, se c'e'. */
    private static function dove(mixed $record): ?string
    {
        return $record->ultimo_destinatario ?: null;
    }

    /**
     * Una riga della stampa: le stesse diciture dello schermo, perche' la
     * stampa e' quella lista e non una sua riscrittura.
     *
     * @return array<string, mixed>
     */
    private static function rigaStampata(mixed $record): array
    {
        return [
            'cliente' => DisplayName::titleCase($record->cliente),
            'riferimento' => self::riferimento($record),
            'contatto' => self::contatto($record),
            'dove' => self::dove($record),
            'importo' => (float) $record->importo,
            'giorni' => $record->silenzio_da ? self::giorni($record->silenzio_da) : null,
        ];
    }

    /**
     * Dove si va cliccando la riga: l'offerta se la trattativa ha
     * alternative, il preventivo singolo altrimenti. Aprire una delle sei
     * alternative al posto dell'offerta vorrebbe dire perdere di vista le
     * altre cinque.
     */
    private static function destinazione(mixed $record): string
    {
        return $record->quote_group_id
            ? QuoteGroupResource::getUrl('edit', ['record' => $record->quote_group_id])
            : QuoteResource::getUrl('edit', ['record' => $record->id]);
    }

    protected function getHeaderActions(): array
    {
        return [
            // La lista con cui si telefona, su carta: al telefono si segna
            // a penna. Esce quello che si vede a schermo, stesso ordine e
            // stessa ricerca, senza paginazione — stampare i primi 25 di
            // una lista ordinata per urgenza perderebbe per strada proprio
            // chi va richiamato.
            Actions\Action::make('stampa')
                ->label('Stampa')
                ->icon('heroicon-o-printer')
                ->color('gray')
                ->action(function () {
                    $righe = $this->getFilteredSortedTableQuery()->get()
                        ->map(fn (mixed $record) => self::rigaStampata($record))
                        ->all();

                    // Vedi App\Support\OutsideLivewireRender: il rendering
                    // parte da dentro un'azione Livewire e senza questo il
                    // PDF si porta dietro i commenti <!--[if BLOCK]-->
                    // attorno a ogni @if.
                    $pdf = OutsideLivewireRender::run(fn () => Pdf::loadView('pdf.preventivi-da-sollecitare', [
                        'righe' => $righe,
                        'tenant' => Filament::getTenant(),
                        'ricerca' => $this->getTableSearch(),
                        'data' => now()->format('d/m/Y'),
                        'totale' => array_sum(array_column($righe, 'importo')),
                        'attesaMassima' => $righe ? max(array_map(fn ($r) => $r['giorni'] ?? 0, $righe)) : null,
                    ]));

                    static::apriUrlInNuovaScheda(
                        StampaTemporanea::parcheggia(
                            $pdf->output(),
                            'preventivi-da-sollecitare-'.now()->format('Y-m-d').'.pdf',
                        ),
                        $this,
                    );
                }),
        ];
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(fn (): Builder => Quote::query()
                ->leftJoin('customers', 'customers.id', '=', 'quotes.customer_id')
                ->leftJoin('quote_groups', 'quote_groups.id', '=', 'quotes.quote_group_id')
                // Colonne nominate una per una: con due join un select *
                // lascerebbe che l'id dell'ultima tabella sovrascriva
                // quello della prima, e le righe punterebbero al cliente
                // invece che al preventivo.
                ->selectRaw('MIN(quotes.id) as id')
                ->selectRaw('MAX(quotes.quote_group_id) as quote_group_id')
                ->selectRaw('MAX(quotes.customer_id) as customer_id')
                ->selectRaw('MAX(customers.search_name) as cliente')
                ->selectRaw('MIN(quotes.number) as numero')
                ->selectRaw('MAX(quote_groups.number) as numero_gruppo')
                // Del gruppo si conta l'alternativa piu' cara, non la
                // somma: il cliente ne comprera' una.
                ->selectRaw('MAX(quotes.total) as importo')
                ->selectRaw('COUNT(*) as alternative')
                ->selectRaw('MIN(quotes.date) as data_invio')
                ->selectRaw('MAX(quotes.valid_until) as scadenza')
                // Basta un'alternativa ancora in validita' perche' la
                // trattativa non sia scaduta.
                ->selectRaw("MAX(CASE WHEN quotes.status = '".Quote::STATUS_INVIATO."' THEN 1 ELSE 0 END) as ancora_valido")
                ->selectRaw(self::ultimoInvio().' as ultimo_invio')
                ->selectRaw(self::silenzioDa().' as silenzio_da')
                ->selectRaw(self::ultimoDestinatario().' as ultimo_destinatario')
                ->where('quotes.tenant_id', self::tenantId())
                // Inviati e scaduti: tutto cio' che aspetta una risposta.
                // Le bozze non sono mai uscite, il resto ha risposto.
                ->whereIn('quotes.status', [Quote::STATUS_INVIATO, Quote::STATUS_SCADUTO])
                ->whereNull('customers.deleted_at')
                ->groupByRaw(self::TRATTATIVA)
                // La soglia si applica DOPO il raggruppamento perche' il
                // silenzio e' una proprieta' della trattativa, non del
                // singolo preventivo: un'alternativa aggiunta ieri a
                // un'offerta di marzo non azzera l'attesa.
                ->havingRaw(self::giorniDiSilenzioOltre(), [now()->toDateString()]))
            ->columns([
                Tables\Columns\TextColumn::make('cliente')
                    ->label('Cliente')
                    ->searchable(query: fn (Builder $query, string $search): Builder => $query
                        ->where(fn (Builder $q) => $q
                            ->where('customers.search_name', 'like', "%{$search}%")
                            ->orWhere('quotes.number', 'like', "%{$search}%")
                            ->orWhere('quote_groups.number', 'like', "%{$search}%")))
                    // Ogni colonna ordina con la propria espressione
                    // aggregata: la query e' raggruppata, e un ORDER BY
                    // sulla colonna nuda violerebbe ONLY_FULL_GROUP_BY.
                    ->sortable(query: fn (Builder $query, string $direction): Builder => $query->orderByRaw("MAX(customers.search_name) {$direction}"))
                    ->weight('medium')
                    ->formatStateUsing(fn (?string $state) => DisplayName::titleCase($state) ?: '—')
                    ->description(fn ($record) => self::riferimento($record)),

                Tables\Columns\TextColumn::make('importo')
                    ->label('In ballo')
                    ->sortable(query: fn (Builder $query, string $direction): Builder => $query->orderByRaw("MAX(quotes.total) {$direction}"))
                    ->money('EUR')
                    ->alignEnd()
                    ->weight('bold')
                    ->description(fn ($record) => self::dove($record), position: 'above'),

                Tables\Columns\TextColumn::make('silenzio_da')
                    ->label('Silenzio da')
                    // La colonna mostra GIORNI, la query ordina per DATA:
                    // le due scale sono invertite, quindi la direzione va
                    // ribaltata, o la freccia "crescente" mette in cima i
                    // piu' vecchi e sembra rotta.
                    ->sortable(query: fn (Builder $query, string $direction): Builder => $query->orderByRaw(self::silenzioDa().' '.($direction === 'asc' ? 'desc' : 'asc')))
                    ->badge()
                    ->formatStateUsing(fn ($state) => $state ? self::giorni($state).' giorni' : '—')
                    ->color(fn ($state) => match (true) {
                        ! $state => 'gray',
                        self::giorni($state) > 90 => 'danger',
                        self::giorni($state) > 30 => 'warning',
                        default => 'info',
                    })
                    ->description(fn ($record) => self::contatto($record)),
            ])
            ->actions([
                // Solo l'icona: la riga intera e' gia' un link (recordUrl),
                // e un'etichetta lunga quanto "Vedi preventivo" mangiava la
                // colonna del silenzio, che e' il motivo per cui si guarda
                // questa pagina.
                Tables\Actions\Action::make('apri')
                    ->label(fn (mixed $record) => $record->quote_group_id ? 'Vedi offerta' : 'Vedi preventivo')
                    ->tooltip(fn (mixed $record) => $record->quote_group_id ? 'Vedi offerta' : 'Vedi preventivo')
                    ->icon('heroicon-m-arrow-right')
                    ->iconButton()
                    ->url(fn (mixed $record) => self::destinazione($record)),
            ])
            // Il peso e' l'ordine di partenza, ma solo un default:
            // cliccando un'intestazione l'utente lo scavalca. Sta in
            // defaultSort e non nella query perche' un orderBy nella query
            // verrebbe prima e renderebbe la scelta dell'utente un
            // criterio secondario, cioe' inefficace.
            ->defaultSort(fn (Builder $query): Builder => $query->orderByRaw(self::ordinamentoPerPeso(), [now()->toDateString()]))
            ->recordUrl(fn ($record) => self::destinazione($record))
            ->emptyStateHeading('Nessuno da sollecitare')
            ->emptyStateDescription('Nessun preventivo aspetta una risposta da più di '.self::GIORNI_MINIMI.' giorni.')
            ->paginated([25, 50, 100]);
    }

    /**
     * L'indirizzo dell'ultimo invio, per sapere a chi si era scritto.
     *
     * Si prende con due sottoselect ordinate per data e non con un
     * MAX(recipient_email): il massimo alfabetico degli indirizzi non e'
     * l'indirizzo dell'ultima mail, e scrivere sotto all'importo un
     * indirizzo che non ha mai ricevuto niente e' peggio che non scrivere
     * nulla.
     *
     * Il MAX() esterno non serve a scegliere: dentro una trattativa le due
     * sottoselect danno lo stesso valore su tutte le righe del gruppo, ma
     * senza un aggregato ONLY_FULL_GROUP_BY rifiuterebbe la query.
     */
    private static function ultimoDestinatario(): string
    {
        // La trattativa, vista da dentro la sottoselect: si rifa' il
        // COALESCE perche' un aggregato del gruppo esterno qui dentro non
        // e' ammesso.
        $stessaTrattativa = 'COALESCE(q2.quote_group_id, q2.id) = '.self::TRATTATIVA;

        $singolo = 'SELECT qe.recipient_email FROM quote_emails qe'
            .' JOIN quotes q2 ON q2.id = qe.quote_id'
            ." WHERE {$stessaTrattativa} AND qe.status = 'sent' AND qe.deleted_at IS NULL"
            .' ORDER BY qe.created_at DESC LIMIT 1';

        $gruppo = 'SELECT qge.recipient_email FROM quote_group_emails qge'
            .' WHERE qge.quote_group_id = quotes.quote_group_id'
            ." AND qge.status = 'sent' AND qge.deleted_at IS NULL"
            .' ORDER BY qge.created_at DESC LIMIT 1';

        return "MAX(COALESCE(({$singolo}), ({$gruppo})))";
    }

    /**
     * La soglia dei giorni minimi, come espressione HAVING: si misura sul
     * silenzio della trattativa e non sulla data del singolo preventivo.
     */
    private static function giorniDiSilenzioOltre(): string
    {
        return 'DATEDIFF(?, '.self::silenzioDa().') >= '.self::GIORNI_MINIMI;
    }
}
