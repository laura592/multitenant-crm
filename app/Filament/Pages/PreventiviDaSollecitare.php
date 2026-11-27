<?php

namespace App\Filament\Pages;

use App\Filament\Concerns\ApreStampeInNuovaScheda;
use App\Filament\Resources\QuoteGroupResource;
use App\Filament\Resources\QuoteResource;
use App\Models\Quote;
use App\Models\QuoteContatto;
use App\Models\QuoteEmail;
use App\Models\Tenant;
use App\Support\DisplayName;
use App\Support\OutsideLivewireRender;
use App\Support\Pdf\StampaTemporanea;
use App\Support\Preventivi\Solleciti;
use Barryvdh\DomPDF\Facade\Pdf;
use BezhanSalleh\FilamentShield\Traits\HasPageShield;
use Filament\Actions;
use Filament\Facades\Filament;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Tables;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
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
 * I giorni di silenzio si contano dall'ULTIMA VOLTA CHE LI ABBIAMO
 * SENTITI, non dalla data del preventivo: se un'offerta di marzo e' stata
 * rimandata a ottobre, il cliente tace da ottobre. Valgono tre cose, la
 * piu' recente delle tre: l'invio del preventivo (quote_emails e
 * quote_group_emails, solo quelli andati a buon fine — una mail "failed"
 * non e' un contatto), la mail di sollecito e la telefonata
 * (quote_contatti). Le telefonate contano quanto le mail: una trattativa
 * sentita ieri a voce non va richiamata oggi.
 *
 * Dopo due solleciti senza risposta la trattativa esce dall'elenco
 * (QuoteContatto::SOLLECITI_MASSIMI, indicazione di Laura: "non posso
 * tormentarli per sempre"). Esce, non si chiude: lo stato resta "scaduto",
 * e l'interruttore "Anche i chiusi" la rimette a vedere.
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

    /**
     * Mostra anche le trattative su cui abbiamo gia' chiesto due volte.
     *
     * Normalmente escono dall'elenco: dopo due solleciti senza risposta si
     * smette (QuoteContatto::SOLLECITI_MASSIMI). Ma "fuori dall'elenco" non
     * vuol dire sparite, e capita di volerle rivedere -- prima di buttare
     * via una trattativa da ventimila euro, o quando il cliente ricompare
     * sei mesi dopo. Il filtro e' un interruttore e non una Filter di
     * Filament perche' non filtra: aggiunge.
     */
    public bool $ancheChiusi = false;

    public function getSubheading(): ?string
    {
        return 'Chi richiamare per avere un sì o un no, in ordine di urgenza. '
            .'Una riga per trattativa: le alternative della stessa offerta sono una sola telefonata.';
    }

    /**
     * La chiave della trattativa: il gruppo se il preventivo sta in
     * un'offerta con alternative, altrimenti se stesso.
     *
     * Si passa da quote_groups.id e non da quotes.quote_group_id perche' il
     * join esclude le offerte cancellate (vedi table()): di un preventivo
     * rimasto attaccato a un'offerta buttata via, la trattativa torna a
     * essere il preventivo stesso.
     *
     * Non e' teoria. Il 27/11/2026 in produzione ce n'erano due:
     * PRV-2026-0080 sotto OFF-2026-0018 (cancellata il 09/10) e
     * PRV-2026-0104 sotto OFF-2026-0023 (cancellata il 28/10), 25.064,60
     * EUR di imponibile in tutto, tutti e due in questo elenco. Il link al
     * cliente di un'offerta cancellata e' una pagina 404: il sollecito
     * avrebbe mandato a due clienti veri un invito a vedere il nulla.
     */
    private const TRATTATIVA = 'COALESCE(quote_groups.id, quotes.id)';

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
        // Il sollecito e' escluso di proposito: "ultimo invio" vuol dire
        // l'ultima volta che il cliente ha ricevuto l'offerta, e serve a
        // decidere che frase dire al telefono ("come da nostra mail
        // del..."). Una mail che chiedeva un si' o un no non ha rimandato
        // niente, e contarla qui sposterebbe la data del documento. Il
        // silenzio, invece, riparte anche dal sollecito: vedi silenzioDa().
        $nonSollecito = fn (string $alias) => " AND COALESCE({$alias}.tipo, 'preventivo') <> '".QuoteEmail::TIPO_SOLLECITO."'";

        $singolo = '(SELECT MAX(qe.created_at) FROM quote_emails qe'
            ." WHERE qe.quote_id = quotes.id AND qe.status = 'sent' AND qe.deleted_at IS NULL"
            .$nonSollecito('qe').')';

        $gruppo = '(SELECT MAX(qge.created_at) FROM quote_group_emails qge'
            ." WHERE qge.quote_group_id = quote_groups.id AND qge.status = 'sent' AND qge.deleted_at IS NULL"
            .$nonSollecito('qge').')';

        $singolo = "COALESCE({$singolo}, ".self::MAI.')';
        $gruppo = "COALESCE({$gruppo}, ".self::MAI.')';

        return "NULLIF(MAX(GREATEST({$singolo}, {$gruppo})), ".self::MAI.')';
    }

    /**
     * L'ultima volta che abbiamo chiesto una risposta, in qualunque modo:
     * la mail di sollecito o la telefonata.
     *
     * Serve perche' il CRM non vedeva le telefonate. Il 27/11/2026 Laura ha
     * chiamato il NATO Stability Policing Centre -- stanno ancora valutando
     * -- e il giorno dopo quella trattativa sarebbe tornata in cima
     * all'elenco, ferma da 67 giorni, come se nessuno l'avesse mai sentita.
     *
     * Il contatto sta sulla trattativa: quote_group_id per le offerte con
     * alternative, quote_id per il preventivo singolo (vedi QuoteContatto).
     * Il MAX() esterno non scegle niente -- dentro un gruppo la sottoselect
     * da' lo stesso valore su tutte le righe -- ma senza un aggregato
     * ONLY_FULL_GROUP_BY rifiuterebbe la query.
     */
    private static function ultimoContatto(): string
    {
        return 'MAX((SELECT MAX(qc.data) FROM quote_contatti qc'
            .' WHERE qc.deleted_at IS NULL'
            .' AND (qc.quote_group_id = quote_groups.id OR qc.quote_id = quotes.id)))';
    }

    /**
     * Quando e' partito l'ultimo sollecito.
     *
     * Diverso da ultimoContatto(), che e' il piu' recente di QUALUNQUE
     * contatto. La colonna "Chiesto" conta i soli solleciti, e scriverci
     * sotto la data di una telefonata faceva leggere "1 volta il
     * 26/11/2026" a chi aveva scritto a settembre e telefonato ieri.
     */
    private static function ultimoSollecito(): string
    {
        return 'MAX((SELECT MAX(qc.data) FROM quote_contatti qc'
            ." WHERE qc.deleted_at IS NULL AND qc.tipo = '".QuoteContatto::TIPO_SOLLECITO."'"
            .' AND (qc.quote_group_id = quote_groups.id OR qc.quote_id = quotes.id)))';
    }

    /**
     * Quante volte abbiamo gia' mandato la mail che chiede un si' o un no.
     *
     * Si contano solo i solleciti e non le telefonate: il tetto e' sulle
     * mail (vedi QuoteContatto::SOLLECITI_MASSIMI), perche' telefonare e'
     * una scelta che si fa guardando il cliente in faccia, mentre la mail
     * e' la cosa che diventa molestia se si ripete.
     */
    private static function solleciti(): string
    {
        return 'MAX((SELECT COUNT(*) FROM quote_contatti qc'
            ." WHERE qc.deleted_at IS NULL AND qc.tipo = '".QuoteContatto::TIPO_SOLLECITO."'"
            .' AND (qc.quote_group_id = quote_groups.id OR qc.quote_id = quotes.id)))';
    }

    /**
     * Da quando il cliente tace: l'ultima volta che gli abbiamo chiesto
     * qualcosa -- mail dell'offerta, sollecito o telefonata, la piu'
     * recente delle tre -- e, in mancanza di tutte, la data del preventivo.
     * I preventivi importati e quelli consegnati a mano non hanno una mail
     * in archivio, e scartarli vorrebbe dire non richiamare proprio i piu'
     * vecchi.
     *
     * Il GREATEST passa per il fondo finto perche' torna NULL se uno degli
     * argomenti e' NULL, e una trattativa ha quasi sempre una sola delle
     * tre cose.
     */
    private static function silenzioDa(): string
    {
        $invio = 'COALESCE('.self::ultimoInvio().', '.self::MAI.')';
        $contatto = 'COALESCE('.self::ultimoContatto().', '.self::MAI.')';

        return 'COALESCE(NULLIF(GREATEST('.$invio.', '.$contatto.'), '.self::MAI.'), MIN(quotes.date))';
    }

    /**
     * Ordinamento per peso: importo × giorni di silenzio. Resta a livello
     * di query per poter ordinare e paginare lato database; la data di
     * riferimento e' un parametro invece di CURDATE() per portabilita' e
     * per rendere la query deterministica a parita' di giorno.
     */
    private static function ordinamentoPerPeso(): string
    {
        return 'MAX(quotes.subtotal) * DATEDIFF(?, '.self::silenzioDa().') DESC';
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

    /**
     * Giorni di CALENDARIO trascorsi da una data.
     *
     * startOfDay su tutti e due i capi perche' l'HAVING della query usa
     * DATEDIFF, che conta i giorni di calendario: con diffInDays, che
     * conta le ore, una riga entrata in elenco a DATEDIFF 7 mostrava il
     * badge "6 giorni", cioe' un numero sotto la soglia che la pagina
     * stessa dichiara.
     */
    private static function giorni(mixed $data): int
    {
        return (int) Carbon::parse($data)->startOfDay()->diffInDays(now()->startOfDay());
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
     * Quante volte abbiamo gia' chiesto una risposta, a parole.
     *
     * "Mai" non e' un rimprovero: su quasi tutte le righe e' la verita', e
     * vuol dire che il primo sollecito e' ancora da mandare.
     */
    private static function chiesto(mixed $record): string
    {
        return match ((int) $record->solleciti) {
            0 => 'mai',
            1 => '1 volta',
            default => (int) $record->solleciti.' volte',
        };
    }

    /**
     * Quando l'abbiamo sentito l'ultima volta, se e' successo. Sotto il
     * conteggio: il numero dice quante, questa riga dice quando.
     */
    private static function quandoChiesto(mixed $record): ?string
    {
        return $record->ultimo_sollecito
            ? 'il '.Carbon::parse($record->ultimo_sollecito)->format('d/m/Y')
            : null;
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
            // Quante volte abbiamo gia' chiesto: al telefono cambia la
            // prima frase, e chi stampa la lista la legge prima di
            // comporre il numero.
            'chiesto' => (int) $record->solleciti > 0
                ? 'già sollecitato '.self::chiesto($record).' '.(self::quandoChiesto($record) ?? '')
                : null,
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
        // Si apre la VISUALIZZAZIONE, non la modifica — dell'offerta come
        // del preventivo: chi arriva da qui vuole sapere che cosa e' gia'
        // stato mandato e quando lo abbiamo sentito, e quelle due cose — lo
        // storico delle mail e i contatti — stanno sulla scheda in sola
        // lettura. Il form di modifica le nasconde.
        return $record->quote_group_id
            ? QuoteGroupResource::getUrl('view', ['record' => $record->quote_group_id])
            : QuoteResource::getUrl('view', ['record' => $record->id]);
    }

    protected function getHeaderActions(): array
    {
        return [
            // L'interruttore dei chiusi. Non e' una Filter di Filament
            // perche' non filtra: rimette dentro quello che la pagina
            // toglie da sola. Metterlo fra i filtri lo avrebbe fatto
            // sembrare uno dei tanti, e invece e' l'unico che cambia che
            // cosa vuol dire questa lista.
            Actions\Action::make('ancheChiusi')
                ->label(fn (): string => $this->ancheChiusi ? 'Nascondi i chiusi' : 'Anche i chiusi')
                // Dice "gia' sollecitate" e non "tutte": la soglia dei
                // sette giorni di silenzio resta accesa comunque, anche
                // con l'interruttore. Chi e' stato sentito ieri non
                // ricompare, ed e' giusto cosi'.
                ->tooltip(fn (): string => $this->ancheChiusi
                    ? 'Torna a vedere solo chi aspetta ancora una risposta'
                    : 'Rimetti nell\'elenco le trattative già sollecitate '.QuoteContatto::SOLLECITI_MASSIMI.' volte e ferme da almeno '.self::GIORNI_MINIMI.' giorni')
                ->icon(fn (): string => $this->ancheChiusi ? 'heroicon-o-eye-slash' : 'heroicon-o-eye')
                ->color('gray')
                ->action(function (): void {
                    $this->ancheChiusi = ! $this->ancheChiusi;
                    $this->resetPage();
                }),

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

    /**
     * Le trattative in attesa di risposta, una riga ciascuna.
     *
     * Le soglie — i giorni minimi di silenzio e il tetto dei due solleciti
     * — sono un parametro perche' servono a DECIDERE CHI MOSTRARE, non a
     * dire che cosa una trattativa e'. Quando Filament ri-risolve la riga
     * per aprire un modale (vedi resolveTableRecord) le soglie vanno
     * tolte, o il record sparisce proprio mentre ci si sta lavorando.
     */
    private function trattative(bool $conSoglie = true): Builder
    {
        return Quote::query()
            ->leftJoin('customers', 'customers.id', '=', 'quotes.customer_id')
            // La condizione sul cancellato sta nella ON e non in una
            // where: in una where il leftJoin diventerebbe un inner, e
            // i preventivi senza offerta — la maggioranza — sparirebbero
            // tutti dall'elenco.
            ->leftJoin('quote_groups', fn ($join) => $join
                ->on('quote_groups.id', '=', 'quotes.quote_group_id')
                ->whereNull('quote_groups.deleted_at'))
            // Colonne nominate una per una: con due join un select *
            // lascerebbe che l'id dell'ultima tabella sovrascriva
            // quello della prima, e le righe punterebbero al cliente
            // invece che al preventivo.
            ->selectRaw('MIN(quotes.id) as id')
            // Dal join e non da quotes: cosi' di un'offerta cancellata
            // torna NULL, la riga si comporta come un preventivo
            // singolo e il link che si manda al cliente e' il suo.
            ->selectRaw('MAX(quote_groups.id) as quote_group_id')
            ->selectRaw('MAX(quotes.customer_id) as customer_id')
            ->selectRaw('MAX(customers.search_name) as cliente')
            ->selectRaw('MIN(quotes.number) as numero')
            ->selectRaw('MAX(quote_groups.number) as numero_gruppo')
            // Del gruppo si conta l'alternativa piu' cara, non la
            // somma: il cliente ne comprera' una.
            //
            // IMPONIBILE, non il totale con l'IVA. I clienti sono
            // aziende: il numero di cui si parla al telefono e' sempre
            // quello senza IVA, ed e' quello che scrivono la mail del
            // preventivo, la pagina del cliente e il sollecito che
            // parte da qui ("per un importo di X + IVA"). Con il totale
            // questa pagina diceva 10.989,88 dove la mail diceva
            // 9.008,10, e l'ordine per peso metteva in cima chi ha piu'
            // IVA invece dell'affare piu' grosso.
            ->selectRaw('MAX(quotes.subtotal) as importo')
            ->selectRaw('COUNT(*) as alternative')
            ->selectRaw('MIN(quotes.date) as data_invio')
            ->selectRaw('MAX(quotes.valid_until) as scadenza')
            // Basta un'alternativa ancora in validita' perche' la
            // trattativa non sia scaduta.
            ->selectRaw("MAX(CASE WHEN quotes.status = '".Quote::STATUS_INVIATO."' THEN 1 ELSE 0 END) as ancora_valido")
            ->selectRaw(self::ultimoInvio().' as ultimo_invio')
            ->selectRaw(self::silenzioDa().' as silenzio_da')
            ->selectRaw(self::ultimoDestinatario().' as ultimo_destinatario')
            ->selectRaw(self::ultimoContatto().' as ultimo_contatto')
            ->selectRaw(self::solleciti().' as solleciti')
            ->selectRaw(self::ultimoSollecito().' as ultimo_sollecito')
            ->where('quotes.tenant_id', self::tenantId())
            // Inviati e scaduti: tutto cio' che aspetta una risposta.
            // Le bozze non sono mai uscite, il resto ha risposto.
            ->whereIn('quotes.status', [Quote::STATUS_INVIATO, Quote::STATUS_SCADUTO])
            ->whereNull('customers.deleted_at')
            ->groupByRaw(self::TRATTATIVA)
            // Le soglie si applicano DOPO il raggruppamento perche' il
            // silenzio e' una proprieta' della trattativa, non del
            // singolo preventivo: un'alternativa aggiunta ieri a
            // un'offerta di marzo non azzera l'attesa.
            ->when($conSoglie, fn (Builder $q): Builder => $q
                ->havingRaw(self::daMostrare(), [now()->toDateString()])
                // Il tetto: dopo due solleciti senza risposta la trattativa
                // esce di qui. Non si chiude e non diventa un rifiuto --
                // resta "scaduto", che un no non detto non si scrive in
                // bocca al cliente -- semplicemente smette di comparire
                // nell'elenco di chi richiamare.
                ->when(! $this->ancheChiusi, fn (Builder $b): Builder => $b
                    ->havingRaw(self::solleciti().' < '.QuoteContatto::SOLLECITI_MASSIMI)));
    }

    /**
     * La trattativa dietro una riga, quando Filament deve riaprirla.
     *
     * Filament di suo fa `getFilteredTableQuery()->find($key)`, e quella
     * query si porta dietro gli HAVING: basta che nel frattempo qualcuno
     * segni una telefonata — da un'altra scheda, o tu stesso un momento
     * prima — perche' il silenzio scenda sotto i sette giorni e il record
     * non si trovi piu'. Filament allora smonta l'azione in silenzio: il
     * modale non si apre, oppure si apre, scrivi la mail, premi "Manda" e
     * non succede NIENTE. Nessun errore, nessuna notifica.
     *
     * E c'e' un secondo guaio, piu' subdolo: `find($key)` filtra su un id
     * solo, quindi gli aggregati si ricalcolano su UNA riga. "2
     * alternative" diventa 1, l'importo quello di un preventivo solo, e il
     * conteggio dei solleciti perde quelli agganciati alle altre
     * alternative — cioe' l'avviso "gli abbiamo gia' scritto due volte"
     * non compare proprio quando serve.
     *
     * Percio' qui si risale alla TRATTATIVA intera e si tolgono le soglie.
     */
    protected function resolveTableRecord(?string $key): ?Model
    {
        if ($key === null) {
            return null;
        }

        return $this->trattative(conSoglie: false)
            ->whereRaw(self::TRATTATIVA.' = ('.self::trattativaDi().')', [$key])
            ->first();
    }

    /**
     * La chiave di trattativa di un singolo preventivo, come sottoselect.
     * Rifa' la stessa COALESCE di TRATTATIVA (offerta cancellata compresa):
     * se le due definizioni divergessero, il record non si troverebbe.
     */
    private static function trattativaDi(): string
    {
        return 'SELECT COALESCE(g3.id, q3.id) FROM quotes q3'
            .' LEFT JOIN quote_groups g3 ON g3.id = q3.quote_group_id AND g3.deleted_at IS NULL'
            .' WHERE q3.id = ?';
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(fn (): Builder => $this->trattative())
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
                    ->sortable(query: fn (Builder $query, string $direction): Builder => $query->orderByRaw("MAX(quotes.subtotal) {$direction}"))
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

                // La quarta colonna dice se tocca ancora a noi: una
                // trattativa ferma da 90 giorni a cui non abbiamo mai
                // chiesto niente e una a cui abbiamo gia' scritto due volte
                // sono due lavori diversi, e prima stavano identiche.
                //
                // E' VUOTA quando non abbiamo mai chiesto, che oggi e'
                // quasi ogni riga: una colonna piena di "mai" identici non
                // dice niente, la si smette di leggere, e il giorno in cui
                // una riga diversa compare non la si vede piu'. Il bianco
                // qui vuol dire "il primo sollecito e' ancora da mandare".
                Tables\Columns\TextColumn::make('solleciti')
                    ->label('Chiesto')
                    ->sortable(query: fn (Builder $query, string $direction): Builder => $query->orderByRaw(self::solleciti().' '.$direction))
                    ->badge()
                    ->placeholder('')
                    ->formatStateUsing(fn ($record): ?string => (int) $record->solleciti > 0 ? self::chiesto($record) : null)
                    ->color(fn ($record): string => (int) $record->solleciti >= QuoteContatto::SOLLECITI_MASSIMI ? 'gray' : 'info')
                    ->description(fn ($record) => (int) $record->solleciti > 0 ? self::quandoChiesto($record) : null),
            ])
            ->actions([
                Tables\Actions\ActionGroup::make([
                    // Il sollecito: la mail che chiede un si' o un no. Non
                    // riallega niente — vedi SollecitoPreventivoMail.
                    Tables\Actions\Action::make('sollecita')
                        ->label('Sollecita per mail')
                        ->icon('heroicon-o-envelope')
                        ->color('primary')
                        ->modalHeading(fn (mixed $record) => 'Sollecito · '.self::riferimento($record))
                        ->modalDescription(fn (mixed $record) => (int) $record->solleciti >= QuoteContatto::SOLLECITI_MASSIMI
                            ? 'Gli abbiamo già scritto '.self::chiesto($record).': di norma qui si smette.'
                            : 'Chiede un sì o un no. Non riallega il preventivo: il cliente lo riapre dal suo link.')
                        ->modalSubmitActionLabel('Manda il sollecito')
                        ->modalWidth('2xl')
                        ->form(Solleciti::formSchema())
                        // Nessun ->disabled() quando manca l'indirizzo:
                        // costava una risoluzione del documento PER RIGA
                        // (56 query su una pagina da 25) per togliere un
                        // pulsante che si puo' usare lo stesso — il campo
                        // e' obbligatorio, l'indirizzo si scrive a mano.
                        ->action(fn (mixed $record, array $data) => Solleciti::invia($record, $data)),

                    // La telefonata. Non manda niente: scrive che e'
                    // successa, perche' il silenzio riparte da li' e domani
                    // questa riga non deve ricomparire in cima.
                    Tables\Actions\Action::make('telefonata')
                        ->label('Ho telefonato')
                        ->icon('heroicon-o-phone')
                        ->color('gray')
                        ->modalHeading(fn (mixed $record) => 'Contatto · '.self::riferimento($record))
                        ->modalDescription('Da qui non parte nessuna mail: si segna solo che li abbiamo sentiti.')
                        ->modalSubmitActionLabel('Segna')
                        ->form([
                            Forms\Components\Radio::make('tipo')
                                ->label('Com\'è andata')
                                ->options($tipiContatto = collect(QuoteContatto::tipi())
                                    ->except(QuoteContatto::TIPO_SOLLECITO)
                                    ->all())
                                ->default(QuoteContatto::TIPO_TELEFONATA)
                                // Il Radio di Filament non aggiunge nessuna
                                // regola "in": senza questa, una richiesta
                                // Livewire confezionata a mano potrebbe
                                // scrivere tipo='sollecito' da qui e
                                // bruciare un sollecito senza mandare
                                // niente al cliente.
                                ->in(array_keys($tipiContatto))
                                ->required(),
                            Forms\Components\DateTimePicker::make('data')
                                ->label('Quando')
                                // Come sulle richieste informazioni: il
                                // campo nativo del browser scrive la data
                                // all'americana (11/27/2026, 04:21 PM) e
                                // in un CRM italiano si legge storta.
                                ->native(false)
                                ->displayFormat('d/m/Y H:i')
                                ->seconds(false)
                                ->default(now())
                                ->maxDate(now())
                                ->required(),
                            Forms\Components\Textarea::make('nota')
                                ->label('Cosa hanno detto')
                                ->placeholder('Stanno ancora valutando, richiamare a gennaio…')
                                ->rows(3),
                        ])
                        ->action(function (mixed $record, array $data): void {
                            Solleciti::registra(Solleciti::documento($record), $data);

                            Notification::make()
                                ->title('Contatto segnato')
                                ->body('Il conto dei giorni riparte da qui.')
                                ->success()
                                ->send();
                        }),

                    Tables\Actions\Action::make('apri')
                        ->label(fn (mixed $record) => $record->quote_group_id ? 'Vedi offerta' : 'Vedi preventivo')
                        ->icon('heroicon-m-arrow-top-right-on-square')
                        ->color('gray')
                        ->url(fn (mixed $record) => self::destinazione($record)),
                ]),
            ])
            // Il peso e' l'ordine di partenza, ma solo un default:
            // cliccando un'intestazione l'utente lo scavalca. Sta in
            // defaultSort e non nella query perche' un orderBy nella query
            // verrebbe prima e renderebbe la scelta dell'utente un
            // criterio secondario, cioe' inefficace.
            ->defaultSort(fn (Builder $query): Builder => $query->orderByRaw(self::ordinamentoPerPeso(), [now()->toDateString()]))
            ->recordUrl(fn ($record) => self::destinazione($record))
            ->emptyStateHeading('Nessuno da sollecitare')
            ->emptyStateDescription('Nessun preventivo aspetta una risposta da più di '.self::GIORNI_MINIMI.' giorni, e nessuno di quelli già sollecitati è ancora in attesa.')
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
        // e' ammesso. Anche qui l'offerta cancellata non conta, o le due
        // definizioni di trattativa — quella di fuori e quella di dentro —
        // non combacerebbero piu' e l'indirizzo tornerebbe vuoto.
        $stessaTrattativa = 'COALESCE(g2.id, q2.id) = '.self::TRATTATIVA;

        // Il sollecito non conta nemmeno qui: questa riga sta sotto
        // "inviato il <data del preventivo>", e mostrare l'indirizzo di
        // una mail diversa da quella sarebbe la stessa bugia che la
        // migrazione 2026_11_27_190000 e' andata a togliere.
        $nonSollecito = fn (string $alias) => " AND COALESCE({$alias}.tipo, 'preventivo') <> '".QuoteEmail::TIPO_SOLLECITO."'";

        $singolo = 'SELECT qe.recipient_email FROM quote_emails qe'
            .' JOIN quotes q2 ON q2.id = qe.quote_id'
            .' LEFT JOIN quote_groups g2 ON g2.id = q2.quote_group_id AND g2.deleted_at IS NULL'
            ." WHERE {$stessaTrattativa} AND qe.status = 'sent' AND qe.deleted_at IS NULL"
            .$nonSollecito('qe')
            .' ORDER BY qe.created_at DESC LIMIT 1';

        $gruppo = 'SELECT qge.recipient_email FROM quote_group_emails qge'
            .' WHERE qge.quote_group_id = quote_groups.id'
            ." AND qge.status = 'sent' AND qge.deleted_at IS NULL"
            .$nonSollecito('qge')
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

    /**
     * Chi compare nell'elenco.
     *
     * I sette giorni valgono per il PRIMO contatto: sotto quella soglia non
     * si telefona, perche' un preventivo di tre giorni non e' un silenzio,
     * e' un cliente che sta ancora leggendo.
     *
     * Ma una volta che il sollecito e' partito la trattativa deve RESTARE
     * a vedersi fino al tetto, anche il giorno stesso. Il 27/11/2026 Laura
     * ha sollecitato Hotel Someda e la riga e' sparita sotto i suoi occhi:
     * il sollecito fa ripartire il conto del silenzio (e deve farlo, o
     * domani quella trattativa tornerebbe in cima come se nessuno avesse
     * scritto), e la soglia se la portava via. "Qua devo vederli fino a 2
     * solleciti": chi e' stato sollecitato una volta e' lavoro ancora
     * aperto, non lavoro finito.
     *
     * L'ordine per peso li manda comunque in fondo da soli, perche' i
     * giorni di silenzio sono appena tornati a zero: restano visibili
     * senza rubare il posto a chi aspetta da mesi.
     */
    private static function daMostrare(): string
    {
        return '('.self::giorniDiSilenzioOltre().' OR '.self::solleciti().' > 0)';
    }
}
