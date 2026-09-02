<?php

namespace App\Support\Gestionale;

use App\Models\Tenant;
use Illuminate\Http\Client\Pool;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Client per l'API REST del gestionale Eureka (ALEX srl), usata per inviare
 * i rapportini firmati come "scheda lavoro" (vedi ServiceReport::toGestionalePayload()).
 * Le credenziali sono globali da .env (config/services.php → eureka.*), non
 * per-tenant: questo CRM e' multi-tenant ma Eureka e' un'integrazione
 * specifica di ALEX (il tenant master), non di ogni partner — un partner non
 * deve mai poterle vedere o modificare dal pannello (vedi thread 2026-08-12).
 * Il parametro $tenant resta comunque per il gate (Tenant::hasGestionaleEurekaCredentials(),
 * vero solo per il master) e per la business logic a valle, non per le credenziali.
 */
class EurekaClient
{
    // Esito di OGNI chiamata fatta da questa istanza, raggruppato per
    // endpoint (vedi endpointKey()): per distinguere "Eureka irraggiungibile
    // per tutta la sync" da "nessun risultato trovato" — senza questo,
    // un'interruzione di Eureka durante `gestionale:sync` produce
    // silenziosamente lo stesso esito di una notte senza nulla da segnalare.
    //
    // Il conteggio e' per endpoint e non piu' globale (com'era con due soli
    // contatori pooled) perche' il caso peggiore visto dal vivo non e' Eureka
    // giu' del tutto, ma UN endpoint che smette di rispondere mentre gli
    // altri funzionano: il 2026-08-27 il fornitore ha introdotto i diritti
    // per modulo e /crm_api/m14/search ha iniziato a rispondere 403
    // (modulo `crm` non abilitato sulle nostre credenziali). Con i contatori
    // globali quel 403 non faceva scattare hadCompleteFailure() — le altre
    // chiamate andavano — e il sync riportava "0 proposte macchinari"
    // invece di "Eureka mi nega l'accesso". Vedi failedEndpoints().
    //
    // @var array<string, array{attempts: int, failures: int, statuses: array<string, int>}>
    private array $callStats = [];

    private readonly string $baseUrl;

    private readonly string $username;

    private readonly string $password;

    public function __construct(private readonly Tenant $tenant)
    {
        if (! $tenant->hasGestionaleEurekaCredentials()) {
            throw new GestionaleEurekaException("Il tenant \"{$tenant->name}\" non ha credenziali Eureka configurate.");
        }

        $this->baseUrl = config('services.eureka.base_url');
        $this->username = config('services.eureka.username');
        $this->password = config('services.eureka.password');
    }

    /**
     * True se questa istanza ha fatto almeno una chiamata pooled e TUTTE
     * sono fallite — segnale forte di un'interruzione lato Eureka (host giu',
     * credenziali rifiutate, rete) piuttosto che "nessun match trovato"
     * (che torna comunque un array vuoto per chiamata, ma successful()).
     */
    public function hadCompleteFailure(): bool
    {
        $attempts = array_sum(array_column($this->callStats, 'attempts'));
        $failures = array_sum(array_column($this->callStats, 'failures'));

        return $attempts > 0 && $failures === $attempts;
    }

    /**
     * Endpoint su cui c'e' un problema sistematico, da segnalare a voce alta
     * invece di lasciarli sparire dentro un risultato vuoto:
     *
     *   - tutte le chiamate a quell'endpoint sono fallite (endpoint morto,
     *     anche se il resto di Eureka risponde);
     *   - oppure e' comparso almeno un 401/403 (autenticazione o diritti di
     *     modulo): non e' un disturbo di passaggio come un 500 o un timeout,
     *     e ritentare non lo risolve — va vista da una persona.
     *
     * Un fallimento sporadico (qualche 500 su tante chiamate, vedi i 500 a
     * raffica del fornitore) resta invece silenzioso di proposito: le
     * chiamate qui sono best-effort e un buco ogni tanto e' tollerato.
     *
     * @return array<int, array{endpoint: string, attempts: int, failures: int, statuses: string}>
     */
    public function failedEndpoints(): array
    {
        $issues = [];

        foreach ($this->callStats as $endpoint => $stats) {
            $denied = isset($stats['statuses']['401']) || isset($stats['statuses']['403']);

            if (! $denied && $stats['failures'] < $stats['attempts']) {
                continue;
            }

            if ($stats['failures'] === 0) {
                continue;
            }

            $statuses = [];
            foreach ($stats['statuses'] as $status => $count) {
                $statuses[] = $status.' x'.$count;
            }

            $issues[] = [
                'endpoint' => $endpoint,
                'attempts' => $stats['attempts'],
                'failures' => $stats['failures'],
                'statuses' => implode(', ', $statuses),
            ];
        }

        usort($issues, fn (array $a, array $b) => $b['failures'] <=> $a['failures']);

        return $issues;
    }

    /**
     * Registra l'esito di una chiamata. $status null = la richiesta non e'
     * nemmeno arrivata a una risposta HTTP (connessione, DNS, timeout).
     */
    private function recordCall(string $url, ?int $status): void
    {
        $key = $this->endpointKey($url);

        $stats = $this->callStats[$key] ?? ['attempts' => 0, 'failures' => 0, 'statuses' => []];

        $stats['attempts']++;

        if ($status === null || $status < 200 || $status >= 300) {
            $stats['failures']++;
            $label = $status === null ? 'connessione' : (string) $status;
            $stats['statuses'][$label] = ($stats['statuses'][$label] ?? 0) + 1;
        }

        $this->callStats[$key] = $stats;
    }

    /**
     * Raggruppa le URL per endpoint tenendo i primi due segmenti di path e
     * riducendo il resto a `*`: /articoli/articolo/CHIORD e
     * /articoli/articolo/LAV2 sono lo stesso endpoint, e su un sync da
     * migliaia di chiamate contarle separatamente riempirebbe le statistiche
     * di una chiave per ricambio invece di dire "/articoli/articolo/* e'
     * giu'". Due segmenti bastano a distinguere tutti gli endpoint che
     * usiamo (/anagrafica/cerca, /show/q/*, /crm_api/m14/*, ...).
     */
    private function endpointKey(string $url): string
    {
        $path = trim((string) parse_url($url, PHP_URL_PATH), '/');

        if ($path === '') {
            return '/';
        }

        $segments = explode('/', $path);
        $kept = array_slice($segments, 0, 2);

        if (count($segments) > 2) {
            $kept[] = '*';
        }

        return '/'.implode('/', $kept);
    }

    /**
     * Crea o aggiorna (via objectId + checkObjectId) una scheda lavoro.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed> il documento riletto da Eureka
     */
    public function inviaSchedaLavoro(array $payload, string $objectId): array
    {
        $url = rtrim($this->baseUrl, '/').'/schedelavoro/?checkObjectId=true';

        $response = Http::withBasicAuth(
            $this->username,
            $this->password,
        )
            ->timeout(15)
            ->post($url, [...$payload, 'objectId' => $objectId]);

        if (! $response->successful()) {
            throw new GestionaleEurekaException(
                "Eureka ha risposto {$response->status()}: ".($response->json('message') ?? $response->body())
            );
        }

        return $response->json() ?? [];
    }

    /**
     * Ricerca anagrafiche cliente per ragione sociale (parziale, "contains").
     * Stesso principio di cercaArticoli(): best-effort, proposta di
     * candidati da confermare a vista, non un collegamento automatico.
     *
     * @return array<int, array{id: int, rag_sociale_1: ?string, partita_iva: ?string, citta: ?string}>
     */
    public function cercaClienti(string $query): array
    {
        $url = rtrim($this->baseUrl, '/').'/anagrafica/cerca?'.http_build_query([
            'nome' => $query,
            'like' => 'true',
        ]);

        try {
            $response = Http::withBasicAuth(
                $this->username,
                $this->password,
            )->timeout(10)->get($url);

            $this->recordCall($url, $response->status());

            if (! $response->successful()) {
                return [];
            }

            return $response->json() ?? [];
        } catch (\Throwable) {
            $this->recordCall($url, null);

            return [];
        }
    }

    /**
     * Ricerca anagrafica per partita IVA — match preciso (priorita' piu' alta
     * lato Eureka, vedi doc fornitore), usato dal sync automatico per
     * ritrovare/verificare un cliente gia' collegato o proporne uno nuovo
     * senza ambiguita' quando la P.IVA e' nota.
     *
     * @return array<int, array{id: int, rag_sociale_1: ?string, partita_iva: ?string, citta: ?string}>
     */
    public function cercaClientePerPiva(string $piva): array
    {
        $url = rtrim($this->baseUrl, '/').'/anagrafica/cerca?'.http_build_query([
            'piva' => $piva,
        ]);

        try {
            $response = Http::withBasicAuth(
                $this->username,
                $this->password,
            )->timeout(10)->get($url);

            $this->recordCall($url, $response->status());

            if (! $response->successful()) {
                return [];
            }

            return $response->json() ?? [];
        } catch (\Throwable) {
            $this->recordCall($url, null);

            return [];
        }
    }

    /**
     * Articoli/macchine risultati installati presso un cliente
     * (GET /show/q/art_installati?q=<id_codice_f15>). Copertura bassa sui
     * dati reali visti in esplorazione (vedi docs/gestionale-eureka/macchinari.md)
     * — molti clienti non hanno nulla qui anche se hanno macchine vere.
     *
     * @return array<int, array{id_codice_f15: int, id: int, matricola: string, articolo: string, desc_articolo_1: ?string, desc_articolo_2: ?string, desc_articolo_3: ?string}>
     */
    public function articoliInstallati(int $customerCode): array
    {
        $url = rtrim($this->baseUrl, '/').'/show/q/art_installati?'.http_build_query([
            'q' => $customerCode,
        ]);

        try {
            $response = Http::withBasicAuth(
                $this->username,
                $this->password,
            )->timeout(10)->get($url);

            $this->recordCall($url, $response->status());

            if (! $response->successful()) {
                return [];
            }

            return $response->json() ?? [];
        } catch (\Throwable) {
            $this->recordCall($url, null);

            return [];
        }
    }

    /**
     * Come le chiamate singole sopra, ma per il sync di massa (migliaia di
     * clienti): esegue tante GET verso lo stesso path in gruppi concorrenti
     * invece che una alla volta, per non aspettare altrettanti round-trip di
     * rete in sequenza (era il vero collo di bottiglia di `gestionale:sync`
     * su ~2000 clienti). Concorrenza volutamente contenuta (15 per gruppo di
     * default): Eureka e' un sistema vecchio (Firebird), meglio non
     * rischiare di sovraccaricarlo o farsi bloccare. Stesso principio
     * best-effort delle chiamate singole: una chiamata fallita nel gruppo
     * risulta vuota, non blocca le altre ne' lancia un'eccezione.
     *
     * @param  array<int|string, array<string, mixed>>  $paramsByKey  parametri di query per ciascuna chiamata, indicizzati da una chiave a scelta (es. id cliente)
     * @return array<int|string, array> stessa chiave => risposta decodificata (vuoto se fallita o non riuscita)
     */
    public function pooledGet(string $path, array $paramsByKey, int $concurrency = 15): array
    {
        $url = rtrim($this->baseUrl, '/').$path;

        // http_build_query() esplicito (non il secondo argomento di get()):
        // quest'ultimo codifica gli spazi come %20 invece di + come fanno le
        // chiamate singole sopra, cambiando silenziosamente il formato delle
        // richieste verso Eureka.
        $urlsByKey = [];
        foreach ($paramsByKey as $key => $params) {
            $urlsByKey[$key] = $url.'?'.http_build_query($params);
        }

        return $this->pooledRequests($urlsByKey, $concurrency);
    }

    /**
     * Come pooledGet(), ma per endpoint dove il parametro di ricerca fa
     * parte del path invece che della query string (es.
     * /articoli/lista/{query}, vedi cercaArticoli()) — ogni chiamata ha un
     * path gia' completo, non una base comune con parametri diversi.
     *
     * @param  array<int|string, string>  $pathsByKey  path relativo gia' completo (incluso il parametro embedded) per ciascuna chiamata
     * @return array<int|string, array> stessa chiave => risposta decodificata (vuoto se fallita o non riuscita)
     */
    public function pooledGetByPath(array $pathsByKey, int $concurrency = 15): array
    {
        $base = rtrim($this->baseUrl, '/');

        $urlsByKey = [];
        foreach ($pathsByKey as $key => $path) {
            $urlsByKey[$key] = $base.$path;
        }

        return $this->pooledRequests($urlsByKey, $concurrency);
    }

    /**
     * Chiavi che NON hanno ricevuto una risposta valida nell'ultima chiamata
     * pooled. Serve dove "risposta vuota" e "chiamata fallita" portano a
     * decisioni opposte: pooledRequests() restituisce [] in entrambi i casi,
     * e sulle partite aperte la differenza vale una fattura che sparisce
     * dall'elenco di chi va sollecitato. Non e' il caso di ogni chiamante,
     * quindi resta un dato a parte invece di cambiare il tracciato dei
     * risultati per tutti.
     *
     * @var array<int, int|string>
     */
    private array $chiaviPooledFallite = [];

    /**
     * @return array<int, int|string>
     */
    public function chiaviPooledFallite(): array
    {
        return $this->chiaviPooledFallite;
    }

    /**
     * @param  array<int|string, string>  $urlsByKey  URL assoluta gia' completa per ciascuna chiamata
     * @return array<int|string, array>
     */
    private function pooledRequests(array $urlsByKey, int $concurrency): array
    {
        $results = [];
        $this->chiaviPooledFallite = [];

        foreach (array_chunk($urlsByKey, $concurrency, true) as $chunk) {
            try {
                $responses = Http::pool(function (Pool $pool) use ($chunk) {
                    foreach ($chunk as $key => $url) {
                        $pool->as($key)
                            ->withBasicAuth(
                                $this->username,
                                $this->password,
                            )
                            ->timeout(10)
                            ->get($url);
                    }
                });
            } catch (\Throwable) {
                foreach ($chunk as $key => $url) {
                    $this->recordCall($url, null);
                    $this->chiaviPooledFallite[] = $key;
                    $results[$key] = [];
                }

                continue;
            }

            foreach ($chunk as $key => $url) {
                $response = $responses[$key] ?? null;
                $ok = $response instanceof Response && $response->successful();

                $this->recordCall($url, $response instanceof Response ? $response->status() : null);

                if (! $ok) {
                    $this->chiaviPooledFallite[] = $key;
                }

                $results[$key] = $ok ? ($response->json() ?? []) : [];
            }
        }

        return $results;
    }

    /**
     * Ricerca articoli per codice (anche parziale). Il match non e' un
     * semplice "contains" sul codice — Eureka a volte ritorna anche articoli
     * non ovviamente collegati al termine cercato — quindi va sempre l'occhio
     * umano a scegliere il risultato giusto (vedi azione "Cerca su Eureka" in
     * ProductResource), non un collegamento automatico.
     *
     * Best-effort: una ricerca fallita ritorna un elenco vuoto invece di
     * lanciare un'eccezione, per non rompere la UI di ricerca-mentre-scrivi.
     *
     * @return array<int, array{id_eureka: int, codice: string, descr1: string}>
     */
    public function cercaArticoli(string $query): array
    {
        $url = rtrim($this->baseUrl, '/').'/articoli/lista/'.rawurlencode($query);

        try {
            $response = Http::withBasicAuth(
                $this->username,
                $this->password,
            )->timeout(10)->get($url);

            $this->recordCall($url, $response->status());

            if (! $response->successful()) {
                return [];
            }

            return $response->json() ?? [];
        } catch (\Throwable) {
            $this->recordCall($url, null);

            return [];
        }
    }

    /**
     * Fatture registrate in contabilita' (clienti o fornitori), in una
     * chiamata: ~2000 documenti dal 2023 a oggi.
     *
     * A differenza delle partite aperte questi dati sono verificati contro i
     * PDF reali delle fatture e coincidono, quindi sono la base preferibile
     * per le analisi.
     *
     * Restituisce NULL se la chiamata e' fallita e un array (anche vuoto) se
     * Eureka ha risposto: chi importa sostituisce una fotografia intera e
     * deve poter distinguere "nessun documento" da "non ho potuto chiedere",
     * altrimenti un 500 di passaggio cancella l'archivio.
     *
     * @return array<int, array<string, mixed>>|null
     */
    public function fattureContabili(bool $fornitori = false, string $dal = '2023-01-01'): ?array
    {
        $path = ($fornitori ? '/contabilita/fatture/fornitori' : '/contabilita/fatture/clienti')
            .'?'.http_build_query(['data_da' => $dal]);

        try {
            $url = rtrim($this->baseUrl, '/').$path;

            $response = Http::withBasicAuth(
                $this->username,
                $this->password,
            )->timeout(120)->get($url);

            $this->recordCall($url, $response->status());

            if (! $response->successful()) {
                return null;
            }

            return $response->json() ?? [];
        } catch (\Throwable) {
            $this->recordCall(rtrim($this->baseUrl, '/').$path, null);

            return null;
        }
    }

    /**
     * Tetto di righe per chiamata imposto dal fornitore su
     * /articoli/cerca-in-righe. Chiedere di piu' non alza il tetto: il
     * servizio scarta il valore fuori intervallo e torna al DEFAULT di 50,
     * cioe' chiedendo 2000 se ne ricevono 50 senza nessun errore. Verificato
     * dal vivo il 2026-09-02.
     */
    private const RIGHE_PER_CHIAMATA = 500;

    /**
     * Righe documento che contengono un testo (ricerca full-text su
     * /articoli/cerca-in-righe). Usata per ritrovare gli acconti: le
     * fatture di acconto e le righe "A DETRARRE FATTURA DI ACCONTO NR X"
     * si riconoscono solo dal testo della riga, non da un campo strutturato.
     *
     * Interrogata UN ANNO ALLA VOLTA e non in un colpo solo. L'endpoint non
     * ha paginazione: superato il tetto di 500 righe taglia il resto senza
     * dirlo, e una riga persa qui non e' un buco visibile — e' un acconto
     * che risulta mai saldato, cioe' un falso allarme in "Analisi
     * contabili". Oggi le righe sono ~185 su tre anni e mezzo, quindi la
     * finestra annuale tiene il margine largo mentre l'archivio cresce.
     *
     * @return array<int, array<string, mixed>>
     */
    public function righeDocumentoContenenti(string $testo, string $dal = '2023-01-01'): array
    {
        $primoAnno = (int) substr($dal, 0, 4);
        $ultimoAnno = (int) now()->format('Y');

        $righe = [];

        for ($anno = $primoAnno; $anno <= $ultimoAnno; $anno++) {
            $righe = [
                ...$righe,
                ...$this->righeDocumentoNellAnno($testo, max($dal, "{$anno}-01-01"), "{$anno}-12-31"),
            ];
        }

        return $righe;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function righeDocumentoNellAnno(string $testo, string $da, string $a): array
    {
        $path = '/articoli/cerca-in-righe?'.http_build_query([
            'testo' => $testo,
            'data_da' => $da,
            'data_a' => $a,
            'limit' => self::RIGHE_PER_CHIAMATA,
        ]);

        try {
            $url = rtrim($this->baseUrl, '/').$path;

            $response = Http::withBasicAuth(
                $this->username,
                $this->password,
            )->timeout(90)->get($url);

            $this->recordCall($url, $response->status());

            $righe = $response->successful() ? ($response->json() ?? []) : [];

            // Una finestra piena e' quasi certamente una finestra tagliata:
            // non c'e' modo di chiedere le successive, quindi almeno lo si
            // scrive nel log invece di far sparire righe in silenzio.
            if (count($righe) >= self::RIGHE_PER_CHIAMATA) {
                Log::warning("Eureka cerca-in-righe: \"{$testo}\" dal {$da} al {$a} ha riempito il tetto di ".self::RIGHE_PER_CHIAMATA.' righe, le successive sono perse.');
            }

            return $righe;
        } catch (\Throwable) {
            $this->recordCall(rtrim($this->baseUrl, '/').$path, null);

            return [];
        }
    }

    /**
     * KPI di fatturato (/contabilita/fatturato), clienti o fornitori, con
     * totali di periodo e ripartizione mensile.
     *
     * Si prende il NETTO calcolato da Eureka invece di sommare gli
     * imponibili della nostra copia: i due numeri non coincidono (424.548,69
     * contro 438.484,17 sul 2026, misurati il 2026-09-02) perche' Eureka
     * pesa le causali con T07CAUSALI_CG e filtra sulla data di
     * registrazione, non su quella del documento. Ricostruirlo a mano
     * significherebbe reimplementare il piano dei conti del gestionale e
     * sbagliarlo: meglio riportare il suo numero e dire da dove viene.
     *
     * NULL se la chiamata e' fallita.
     *
     * @return array<string, mixed>|null
     */
    public function fatturato(bool $fornitori, string $dal, string $al): ?array
    {
        return $this->getJson('/contabilita/fatturato?'.http_build_query([
            'tipo' => $fornitori ? 'F' : 'C',
            'data_da' => $dal,
            'data_a' => $al,
        ]), timeout: 60);
    }

    /**
     * Cash flow prospettico mensile (/contabilita/cashflow): scadenze
     * clienti e fornitori piu' ordini e bolle ancora aperti.
     *
     * E' l'unica cosa in tutto il modulo che guarda AVANTI — le partite
     * aperte dicono cosa e' gia' andato storto, questo dice quando i soldi
     * arrivano e quando escono.
     *
     * @return array<string, mixed>|null
     */
    public function cashflow(string $dal, string $al): ?array
    {
        return $this->getJson('/contabilita/cashflow?'.http_build_query([
            'data_da' => $dal,
            'data_a' => $al,
        ]), timeout: 90);
    }

    /**
     * Le singole voci che compongono il cash flow di un mese
     * (/contabilita/cashflow/dettaglio): serve a rispondere a "questi 59.000
     * di uscite a gennaio, da cosa vengono?".
     *
     * In importo il segno indica il verso: positivo entrata, negativo
     * uscita. L'anagrafica compare solo come testo libero (descrizione),
     * quindi le righe non sono collegabili ai clienti del CRM — per quello
     * ci sono le partite aperte.
     *
     * @return array<int, array<string, mixed>>|null
     */
    public function cashflowDettaglio(int $anno, int $mese): ?array
    {
        return $this->getJson('/contabilita/cashflow/dettaglio?'.http_build_query([
            'anno' => $anno,
            'mese' => $mese,
        ]), timeout: 60);
    }

    /**
     * GET che restituisce JSON, con la sola distinzione che conta per gli
     * import: NULL se Eureka non ha risposto, il corpo decodificato se ha
     * risposto. Le chiamate qui sopra erano cinque copie della stessa
     * try/catch.
     *
     * @return array<mixed>|null
     */
    private function getJson(string $path, int $timeout): ?array
    {
        $url = rtrim($this->baseUrl, '/').$path;

        try {
            $response = Http::withBasicAuth($this->username, $this->password)
                ->timeout($timeout)
                ->get($url);

            $this->recordCall($url, $response->status());

            return $response->successful() ? ($response->json() ?? []) : null;
        } catch (\Throwable) {
            $this->recordCall($url, null);

            return null;
        }
    }

    /**
     * Anagrafiche con almeno una partita aperta, in una chiamata
     * (/contabilita/saldi, o la variante fornitori). Sono ~87 clienti e ~56
     * fornitori: l'elenco serve a sapere PER CHI vale la pena chiedere il
     * dettaglio, invece di interrogare tutte le 2000 anagrafiche.
     *
     * NULL se la chiamata e' fallita, array (anche vuoto) se Eureka ha
     * risposto: come fattureContabili(), l'import sostituisce una fotografia
     * intera e non deve confondere "nessuna partita aperta" con "non ho
     * potuto chiedere".
     *
     * @return array<int, array{id_nominativo: int, codice: string, nominativo: string, saldo: float}>|null
     */
    public function saldiPartiteAperte(bool $fornitori = false): ?array
    {
        $path = $fornitori ? '/contabilita/saldi/fornitori' : '/contabilita/saldi';

        try {
            $url = rtrim($this->baseUrl, '/').$path;

            $response = Http::withBasicAuth(
                $this->username,
                $this->password,
            )->timeout(30)->get($url);

            $this->recordCall($url, $response->status());

            if (! $response->successful()) {
                return null;
            }

            return $response->json() ?? [];
        } catch (\Throwable) {
            $this->recordCall(rtrim($this->baseUrl, '/').$path, null);

            return null;
        }
    }

    /**
     * Dettaglio delle partite aperte di ciascuna anagrafica indicata, in
     * gruppi concorrenti: una chiamata per anagrafica
     * (/contabilita/partitaaperta/{id}, o la variante fornitore).
     *
     * Non si usa /contabilita/cashflow/dettaglio, che elencherebbe le
     * scadenze di un mese intero in una sola chiamata: non espone l'id
     * dell'anagrafica ma solo la ragione sociale come testo libero, quindi le
     * righe non sarebbero collegabili ai clienti del CRM.
     *
     * @param  array<int, int>  $codici  id_nominativo Eureka
     * @return array<int, array> id_nominativo => elenco partite
     */
    public function partiteAperte(array $codici, bool $fornitori = false): array
    {
        $base = $fornitori ? '/contabilita/partitaaperta/fornitore/' : '/contabilita/partitaaperta/';

        $paths = [];
        foreach ($codici as $codice) {
            $paths[$codice] = $base.((int) $codice);
        }

        return $this->pooledGetByPath($paths);
    }

    /**
     * Note dell'anagrafica Eureka per tutti i clienti, in UNA chiamata
     * (GET /anagrafica/f15?note=1) invece di una per cliente: la risposta
     * pesa ~1,5 MB per ~2000 anagrafiche e impiega circa 80 secondi
     * (misurato 2026-09-01), contro i ~2000 round-trip che costerebbe
     * leggerle da /anagrafica/f15/{id}. Da qui il timeout molto piu' alto
     * delle altre chiamate: e' una lettura di massa, gira nel sync notturno.
     *
     * Senza il parametro note=1 il campo e' presente ma sempre vuoto: le
     * note sono testo libero senza limiti di lunghezza e il fornitore le
     * omette di default dalla lista completa.
     *
     * @return array<int, string> id_eureka => nota ripulita (solo le non vuote)
     */
    public function noteAnagrafiche(): array
    {
        try {
            $url = rtrim($this->baseUrl, '/').'/anagrafica/f15?note=1';

            $response = Http::withBasicAuth(
                $this->username,
                $this->password,
            )->timeout(180)->get($url);

            $this->recordCall($url, $response->status());

            if (! $response->successful()) {
                return [];
            }

            $note = [];

            foreach ($response->json() ?? [] as $anagrafica) {
                $id = (int) ($anagrafica['id_eureka'] ?? 0);
                $testo = self::ripulisciNota($anagrafica['note'] ?? null);

                if ($id > 0 && $testo !== '') {
                    $note[$id] = $testo;
                }
            }

            return $note;
        } catch (\Throwable) {
            $this->recordCall(rtrim($this->baseUrl, '/').'/anagrafica/f15', null);

            return [];
        }
    }

    /**
     * Le note arrivano dal database Eureka in RTF e l'API le converte in
     * testo semplice, ma la conversione lascia in coda un ritorno a capo e
     * un carattere NUL: `"note":"PAGA RIVER CAFFE' TREVISO\r\u0000"`
     * (verificato 2026-09-01 su tutte le 101 note valorizzate). Il JSON e'
     * valido — il NUL e' escapato — ma un NUL dentro una stringa fa
     * inciampare parecchi consumatori a valle, e va tolto prima di salvarlo.
     *
     * Un caso mostra perche' non basta un trim degli spazi: l'anagrafica
     * 3045 ha una nota composta SOLO da "\r\u0000", quindi senza questa
     * pulizia risulterebbe valorizzata pur essendo vuota.
     */
    private static function ripulisciNota(?string $nota): string
    {
        if ($nota === null) {
            return '';
        }

        // I ritorni a capo interni si tengono (le note multi-riga sono
        // legittime): si normalizzano soltanto, per non salvare CRLF misti.
        $nota = str_replace(["\x00", "\r\n", "\r"], ['', "\n", "\n"], $nota);

        return trim($nota);
    }

    /**
     * NON UTILIZZATA dal 2026-08-31: /crm_api/* risponde 403 da quando il
     * fornitore ha introdotto i diritti per modulo (il modulo `crm` non e'
     * abilitato sulle nostre credenziali, vedi GET /utente/permessi), e la
     * rotta non compare nemmeno nel manuale rev. 1.2. Le proposte di
     * collegamento macchinari passano ora da /show/q/art_installati (vedi
     * GestionaleSyncRunner::proposeMachineUnitLinks()). Tenuta qui perche'
     * torna utile identica il giorno in cui il modulo venisse abilitato: e'
     * l'unico modo di leggere l'id matricola M14.
     *
     * Ricerca matricole di un articolo (bene) gia' collegato a Eureka.
     * Risposta paginata (a differenza degli altri endpoint di ricerca):
     * `{"items": [...], "total": N}` — ritorniamo solo `items`.
     *
     * @return array<int, array{id: int, matricola: string, id_articolo_m10: int, note: ?string}>
     */
    public function cercaMatricole(int $idArticoloM10, string $query = ''): array
    {
        $url = rtrim($this->baseUrl, '/').'/crm_api/m14/search?'.http_build_query(array_filter([
            'id_articolo_m10' => $idArticoloM10,
            'q' => $query,
            'per_page' => 25,
        ]));

        try {
            $response = Http::withBasicAuth(
                $this->username,
                $this->password,
            )->timeout(10)->get($url);

            $this->recordCall($url, $response->status());

            if (! $response->successful()) {
                return [];
            }

            return $response->json('items') ?? [];
        } catch (\Throwable) {
            $this->recordCall($url, null);

            return [];
        }
    }
}
