<?php

namespace App\Support\Gestionale;

use App\Models\Tenant;
use Illuminate\Http\Client\Pool;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

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
        try {
            $url = rtrim($this->baseUrl, '/').'/anagrafica/cerca?'.http_build_query([
                'nome' => $query,
                'like' => 'true',
            ]);

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
        try {
            $url = rtrim($this->baseUrl, '/').'/anagrafica/cerca?'.http_build_query([
                'piva' => $piva,
            ]);

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
        try {
            $url = rtrim($this->baseUrl, '/').'/show/q/art_installati?'.http_build_query([
                'q' => $customerCode,
            ]);

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
     * @param  array<int|string, string>  $urlsByKey  URL assoluta gia' completa per ciascuna chiamata
     * @return array<int|string, array>
     */
    private function pooledRequests(array $urlsByKey, int $concurrency): array
    {
        $results = [];

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
                    $results[$key] = [];
                }

                continue;
            }

            foreach ($chunk as $key => $url) {
                $response = $responses[$key] ?? null;
                $ok = $response instanceof Response && $response->successful();

                $this->recordCall($url, $response instanceof Response ? $response->status() : null);

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
        try {
            $url = rtrim($this->baseUrl, '/').'/articoli/lista/'.rawurlencode($query);

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
     * Ricerca matricole di un articolo (bene) gia' collegato a Eureka.
     * Risposta paginata (a differenza degli altri endpoint di ricerca):
     * `{"items": [...], "total": N}` — ritorniamo solo `items`.
     *
     * @return array<int, array{id: int, matricola: string, id_articolo_m10: int, note: ?string}>
     */
    public function cercaMatricole(int $idArticoloM10, string $query = ''): array
    {
        try {
            $url = rtrim($this->baseUrl, '/').'/crm_api/m14/search?'.http_build_query(array_filter([
                'id_articolo_m10' => $idArticoloM10,
                'q' => $query,
                'per_page' => 25,
            ]));

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
