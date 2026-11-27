<?php

namespace App\Support\Controlli;

use App\Filament\Resources\CustomerResource;
use App\Filament\Resources\MachineUnitResource;
use App\Filament\Resources\MaintenanceScheduleResource;
use App\Filament\Resources\MaterialResource;
use App\Filament\Resources\ServiceReportResource;
use App\Filament\Resources\VehicleResource;
use App\Models\Lavaggio;
use App\Models\Vehicle;
use Closure;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;

/**
 * I controlli sui dati, in un posto solo.
 *
 * Nascono da una settimana in cui ogni problema e' saltato fuori per caso:
 * il piano di Patatrac fermo dal 5 settembre scoperto guardando un'altra
 * cosa, la Casadio intestata a Isola Blu trovata mentre se ne sistemava
 * un'altra, i mezzi senza assicurazione visti solo perche' si stava
 * scrivendo il promemoria. Nessuno di questi era nascosto: erano tutti
 * visibili, ciascuno nella sua pagina, e nessuno li guardava insieme.
 *
 * Ogni controllo risponde a una domanda sola e porta dove si sistema. Lo
 * zero non e' "nessun risultato", e' "questa cosa e' a posto": per questo
 * i controlli a zero restano in pagina, in verde, invece di sparire come
 * fanno i blocchi della dashboard.
 *
 * DOVE PORTA IL BOTTONE (27/11/2026). Prima portava all'elenco nudo della
 * risorsa: "vedi tutti i clienti? come faccio a capire i possibili
 * problemi?" (Laura). Una scheda che annuncia un numero e poi scarica
 * addosso 3600 materiali o 2226 clienti fa fare il lavoro due volte, ed e'
 * il motivo per cui una pagina del genere si smette di usare. Adesso ogni
 * url accende in tabella un filtro dedicato (tableFilters nella query
 * string) e il filtro NON riscrive la condizione del conteggio: chiama la
 * stessa. Il numero sulla scheda e le righe che si aprono vengono per
 * costruzione dalla stessa fonte, quindi se un giorno la condizione cambia
 * cambiano insieme.
 */
class RegistroControlli
{
    /** @return Collection<int, Controllo> */
    public static function tutti(): Collection
    {
        return collect([
            self::anagraficheDoppie(),
            self::codiciFiscaliRipetuti(),
            self::clientiSenzaDatiFiscali(),
            self::pianiScaduti(),
            self::macchineSenzaPiano(),
            self::rapportiniMaiInviati(),
            self::mezziScoperti(),
            self::lavaggiSenzaVie(),
            self::macchineForseSpostate(),
            self::fusioniDaDecidere(),
            self::materialiSenzaPrezzo(),
            self::materialiDoppi(),
        ])->filter(fn (Controllo $c) => $c->visibile())->values();
    }

    private static function puo(string $permesso): bool
    {
        return Auth::user()?->can($permesso) ?? false;
    }

    /**
     * I due numeri di un controllo che raggruppa, con un calcolo solo.
     *
     * Chi raggruppa ha due numeri diversi: i casi da decidere (i gruppi) e
     * le righe che si aprono cliccando. Devono uscire dallo stesso giro,
     * altrimenti sono due query che un giorno raccontano due storie —
     * esattamente il difetto che questa pagina sta correggendo. Il memo non
     * e' un'ottimizzazione prematura: senza, raggruppare i 3600 materiali
     * si pagherebbe due volte per riempire una scheda sola.
     *
     * @param  Closure(): Collection<array-key, mixed>  $gruppi
     * @return Closure(): array{gruppi: int, righe: int}
     */
    private static function numeriDeiGruppi(Closure $gruppi): Closure
    {
        $memo = null;

        return function () use ($gruppi, &$memo): array {
            if ($memo === null) {
                $trovati = $gruppi();

                $memo = [
                    'gruppi' => $trovati->count(),
                    'righe' => $trovati->flatten(1)->count(),
                ];
            }

            return $memo;
        };
    }

    /**
     * L'indirizzo di un elenco col filtro del controllo gia' acceso.
     *
     * La forma della query string e' quella che Filament 3 legge da se':
     * tableFilters[<nome>][isActive]. "isActive" non e' un nome scelto da
     * noi, e' il campo che Tables\Filters\Filter mette nel suo form (vedi
     * Filter::getFormField): cambiarlo vorrebbe dire un link che si apre
     * senza filtro, senza che nulla lo segnali.
     *
     * Da sapere: quando i filtri arrivano dall'indirizzo, Filament NON
     * applica i default degli altri filtri della tabella. Per i piani e'
     * una fortuna (il controllo non dipende da "nascondi chiusi"), per i
     * rapportini no: le tab per anno restano, e il link deve dire
     * activeTab.
     *
     * @param  array<string, string|int>  $altri
     */
    private static function conFiltro(string $url, string $filtro, array $altri = []): string
    {
        $separatore = str_contains($url, '?') ? '&' : '?';

        return $url.$separatore.http_build_query([
            'tableFilters' => [$filtro => ['isActive' => true]],
        ] + $altri);
    }

    private static function anagraficheDoppie(): Controllo
    {
        $numeri = self::numeriDeiGruppi(fn () => AnagraficheDoppie::gruppi());

        return new Controllo(
            titolo: 'Anagrafiche che sembrano doppie',
            spiegazione: 'Stessa P.IVA, stesso nome e stessa citta\'. I punti vendita della stessa societa\' (Airest, Gromart) non compaiono: hanno nomi diversi ed e\' giusto che siano anagrafiche separate.',
            icona: 'heroicon-o-document-duplicate',
            conta: fn () => $numeri()['gruppi'],
            url: fn () => self::conFiltro(CustomerResource::getUrl('index'), 'controllo_anagrafiche_doppie'),
            visibile: fn () => self::puo('view_any_customer'),
            quandoZero: 'Nessuna anagrafica sdoppiata.',
            azione: 'Apri i clienti',
            righe: fn () => $numeri()['righe'],
        );
    }

    private static function codiciFiscaliRipetuti(): Controllo
    {
        $numeri = self::numeriDeiGruppi(fn () => AnagraficheDoppie::codiciFiscaliRipetuti());

        return new Controllo(
            titolo: 'Codici fiscali su piu\' anagrafiche',
            spiegazione: 'Spesso e\' lo stesso titolare con due attivita\', ed e\' corretto. Ma e\' anche il modo in cui un codice finisce incollato sul cliente sbagliato.',
            icona: 'heroicon-o-identification',
            conta: fn () => $numeri()['gruppi'],
            url: fn () => self::conFiltro(CustomerResource::getUrl('index'), 'controllo_codici_fiscali_ripetuti'),
            visibile: fn () => self::puo('view_any_customer'),
            quandoZero: 'Nessun codice fiscale ripetuto.',
            azione: 'Apri i clienti',
            righe: fn () => $numeri()['righe'],
        );
    }

    private static function clientiSenzaDatiFiscali(): Controllo
    {
        return new Controllo(
            titolo: 'Clienti con lavoro aperto e senza dati fiscali',
            spiegazione: 'Hanno gia\' un preventivo o un rapportino ma non hanno ne\' P.IVA ne\' codice fiscale: non si possono mandare in Eureka, quindi non si puo\' fatturare quello che e\' gia\' stato fatto.',
            icona: 'heroicon-o-exclamation-circle',
            // La condizione (e il perche' di "solo chi ha un lavoro
            // aperto") sta in ClientiSenzaDatiFiscali, da dove la prende
            // anche il filtro della tabella clienti.
            conta: fn () => ClientiSenzaDatiFiscali::query()->count(),
            url: fn () => self::conFiltro(CustomerResource::getUrl('index'), 'controllo_senza_dati_fiscali'),
            visibile: fn () => self::puo('view_any_customer'),
            quandoZero: 'Tutti i clienti hanno almeno un dato fiscale.',
            azione: 'Apri i clienti',
        );
    }

    private static function pianiScaduti(): Controllo
    {
        return new Controllo(
            titolo: 'Piani scaduti e non in pausa',
            spiegazione: 'Lavaggi e manutenzioni la cui data e\' passata senza che arrivasse un rapportino. Chi ha chiuso per la stagione non c\'e\': quello si mette in pausa e smette di scadere.',
            icona: 'heroicon-o-clock',
            conta: fn () => PianiScaduti::query()->count(),
            url: fn () => self::conFiltro(MaintenanceScheduleResource::getUrl('index'), 'controllo_piani_scaduti'),
            visibile: fn () => self::puo('view_any_maintenance::schedule'),
            quandoZero: 'Nessun piano indietro.',
            azione: 'Apri i piani',
        );
    }

    private static function macchineSenzaPiano(): Controllo
    {
        return new Controllo(
            titolo: 'Macchine che si manutengono senza un piano',
            spiegazione: 'Ci si va sopra regolarmente — ci sono rapportini di manutenzione o sanificazione — ma nessun piano le tiene: se saltano un giro non se ne accorge nessuno. E\' il caso dell\'impianto acqua di Patatrac, lavorato il 9 ottobre e il 24 novembre senza che nulla lo registrasse.',
            icona: 'heroicon-o-wrench-screwdriver',
            // La condizione (e il perche' delle due sottoquery) sta in
            // MacchineSenzaPiano, da dove la prende anche il filtro della
            // tabella macchine.
            conta: fn () => MacchineSenzaPiano::query()->count(),
            url: fn () => self::conFiltro(MachineUnitResource::getUrl('index'), 'controllo_senza_piano'),
            visibile: fn () => self::puo('view_any_machine::unit'),
            quandoZero: 'Ogni macchina installata ha il suo piano.',
            azione: 'Apri le macchine',
        );
    }

    private static function rapportiniMaiInviati(): Controllo
    {
        return new Controllo(
            titolo: 'Rapportini chiusi e mai inviati',
            spiegazione: 'Il lavoro e\' fatto e il rapportino e\' chiuso, ma in Eureka non e\' mai arrivato: finche\' non ci arriva, non si fattura.',
            icona: 'heroicon-o-arrow-up-tray',
            conta: fn () => RapportiniMaiInviati::query()->count(),
            // activeTab=tutti non e' un vezzo: l'elenco dei rapportini si
            // apre sull'anno in corso, e un rapportino del 2025 mai
            // inviato sarebbe dentro al conteggio e fuori dalle righe.
            url: fn () => self::conFiltro(
                ServiceReportResource::getUrl('index'),
                'controllo_mai_inviati',
                ['activeTab' => 'tutti'],
            ),
            visibile: fn () => self::puo('view_any_service::report'),
            quandoZero: 'Tutti i rapportini chiusi sono passati in Eureka.',
            azione: 'Apri i rapportini',
        );
    }

    private static function mezziScoperti(): Controllo
    {
        return new Controllo(
            titolo: 'Mezzi senza documenti obbligatori',
            spiegazione: 'Manca assicurazione, bollo o revisione in corso. Non e\' una scadenza vicina: e\' una scadenza che non c\'e\', e quindi nessun promemoria potra\' mai avvisare.',
            icona: 'heroicon-o-truck',
            // Qui la condizione era gia' una scope del modello, e la usa
            // anche il filtro della tabella mezzi: non serve una classe
            // in piu' per tenerla.
            conta: fn () => Vehicle::query()->conScadenzeMancanti()->count(),
            url: fn () => self::conFiltro(VehicleResource::getUrl('index'), 'controllo_documenti_mancanti'),
            visibile: fn () => self::puo('view_any_vehicle'),
            quandoZero: 'Ogni mezzo in flotta e\' coperto.',
            azione: 'Apri i mezzi',
        );
    }

    private static function lavaggiSenzaVie(): Controllo
    {
        return new Controllo(
            titolo: 'Lavaggi senza numero di vie',
            spiegazione: 'Senza le vie il lavaggio non si puo\' valorizzare. Quelli ricavabili dalla descrizione, dal piano o dallo storico dello stesso impianto li sistema «php artisan lavaggi:vie-mancanti» (con --dry-run dice caso per caso da dove prende il numero); questi sono quelli rimasti, dove nemmeno lo storico e\' d\'accordo con se stesso: si leggono sul rapportino.',
            icona: 'heroicon-o-beaker',
            conta: fn () => Lavaggio::query()->whereNull('lines_washed')->count(),
            // L'unico controllo senza bottone, e resta cosi': i lavaggi non
            // hanno un elenco proprio (si vedono dentro al piano e dentro
            // al cliente, come relation manager), quindi non c'e' una
            // pagina su cui atterrare. Inventarne una per 92 righe da
            // leggere sul rapportino sarebbe una pagina in piu' da
            // mantenere: chi le sistema parte dal comando.
            url: fn () => null,
            visibile: fn () => self::puo('view_any_maintenance::schedule'),
            quandoZero: 'Tutti i lavaggi hanno le vie.',
        );
    }

    private static function macchineForseSpostate(): Controllo
    {
        return new Controllo(
            titolo: 'Macchine che sembrano spostate',
            spiegazione: 'Eureka le vede lavorare da un cliente diverso da quello a cui sono intestate. Finche\' non si decide, i rapportini finiscono sull\'anagrafica sbagliata — e\' il caso della Casadio ancora intestata a Isola Blu.',
            icona: 'heroicon-o-arrows-right-left',
            conta: fn () => MacchineForseSpostate::query()->count(),
            url: fn () => self::conFiltro(MachineUnitResource::getUrl('index'), 'controllo_forse_spostate'),
            visibile: fn () => self::puo('view_any_machine::unit'),
            quandoZero: 'Nessuno spostamento in sospeso.',
            azione: 'Apri le macchine',
        );
    }

    private static function fusioniDaDecidere(): Controllo
    {
        return new Controllo(
            titolo: 'Macchine forse doppie, da decidere',
            spiegazione: 'Due matricole che potrebbero essere la stessa macchina. Dove Eureka elenca entrambe puo\' trattarsi davvero di due apparecchi: vanno guardate una per una.',
            icona: 'heroicon-o-square-2-stack',
            conta: fn () => FusioniDaDecidere::query()->count(),
            url: fn () => self::conFiltro(MachineUnitResource::getUrl('index'), 'controllo_fusioni_da_decidere'),
            visibile: fn () => self::puo('view_any_machine::unit'),
            quandoZero: 'Nessuna fusione in sospeso.',
            azione: 'Apri le macchine',
        );
    }

    private static function materialiSenzaPrezzo(): Controllo
    {
        return new Controllo(
            titolo: 'Materiali senza prezzo, ma usati dove si fattura',
            spiegazione: 'Il listino del materiale e\' il prezzo che la riga si porta in fattura: a zero, la riga del rapportino esce senza importo e il canone di una fornitura vale niente. Qui ci sono solo quelli gia\' finiti su un rapportino o su un noleggio — i materiali Eureka a zero che nessuno usa non compaiono.',
            icona: 'heroicon-o-currency-euro',
            conta: fn () => MaterialiSenzaPrezzo::query()->count(),
            // Prima si ordinava per prezzo crescente e si sperava: i 124 da
            // sistemare restavano da riconoscere in mezzo agli altri 2300
            // materiali a zero che nessuno usa. Adesso sono esattamente i
            // 124, perche' il filtro della tabella chiama la stessa
            // MaterialiSenzaPrezzo che li conta qui sopra.
            url: fn () => self::conFiltro(MaterialResource::getUrl('index'), 'controllo_senza_prezzo'),
            visibile: fn () => self::puo('view_any_material'),
            quandoZero: 'Ogni materiale che fattura ha il suo prezzo.',
            azione: 'Apri i materiali',
        );
    }

    private static function materialiDoppi(): Controllo
    {
        $numeri = self::numeriDeiGruppi(fn () => MaterialiDoppi::gruppi());

        return new Controllo(
            titolo: 'Materiali che sembrano doppi, con prezzi diversi',
            spiegazione: 'Lo stesso codice a catalogo due volte (HLU-30 e HLU30, 05000038 e 5000038) con due prezzi: dalla tendina del rapportino si leggono identici, e quello che si fattura dipende da quale si e\' preso. Le varianti dei paganti (F2HTS, OREGOPPION) non compaiono: li\' il prezzo diverso e\' voluto.',
            // Non square-2-stack: e' gia' l'icona delle macchine forse
            // doppie, e due schede uguali in pagina si confondono.
            icona: 'heroicon-o-rectangle-stack',
            conta: fn () => $numeri()['gruppi'],
            url: fn () => self::conFiltro(MaterialResource::getUrl('index'), 'controllo_doppi'),
            visibile: fn () => self::puo('view_any_material'),
            quandoZero: 'Nessuna voce di catalogo sdoppiata.',
            azione: 'Apri i materiali',
            righe: fn () => $numeri()['righe'],
        );
    }
}
