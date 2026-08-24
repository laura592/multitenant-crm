<?php

namespace App\Support;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Pool;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class EurekaClient
{
    public function __construct(
        private readonly string $baseUrl,
        private readonly string $username,
        private readonly string $password,
    ) {}

    /**
     * @param  array{id_codice_f15?: int, data_da?: string, data_a?: string, matricola?: string}  $filters
     * @return array<int, array<string, mixed>>
     */
    public function searchServiceReports(array $filters): array
    {
        $query = array_filter(
            $filters,
            fn (mixed $v) => $v !== null && $v !== '',
        );

        $payload = $this->get('/schedelavoro/', $query);

        // L'endpoint restituisce un array diretto o {items:[...]}
        $items = isset($payload['items']) ? $payload['items'] : $payload;

        return array_values(array_filter($items, fn (mixed $row) => is_array($row)));
    }

    /**
     * @return array<string, mixed>
     */
    public function getServiceReport(int $id): array
    {
        $payload = $this->get('/schedelavoro/'.rawurlencode((string) $id));

        // La risposta reale identifica il documento con "id_eureka", non
        // "id" (coerente con sl_articolo.id_eureka/sl_tariffa.id_eureka
        // nello stesso payload) — verificato dal vivo sull'API di
        // produzione, vedi GET /schedelavoro/17264.
        if (! isset($payload['id_eureka'])) {
            throw new RuntimeException("Risposta non valida da /schedelavoro/{$id}.");
        }

        return $payload;
    }

    /**
     * Come getServiceReport() ma per molti id in gruppi concorrenti invece
     * che uno alla volta (stesso principio di
     * Gestionale\EurekaClient::pooledGet) — era il vero collo di bottiglia
     * di `eureka:import-service-reports --with-detail`: ~2s/chiamata x
     * migliaia di rapportini in sequenza. Best-effort come le altre
     * chiamate pooled: un id fallito o con risposta non valida resta
     * semplicemente assente dal risultato invece di interrompere l'intero
     * import.
     *
     * Concorrenza volutamente bassa (5, non 15 come le altre chiamate
     * pooled) e pausa tra i gruppi: un giro su tutto lo storico (~3600
     * rapportini, 2026-08-07) ha fatto crashare Eureka due volte facendo
     * girare gruppi da 15 senza pause — il fornitore ha poi confermato che
     * era proprio il volume di chiamate a farla cadere. Ogni GET dettaglio
     * pesa di piu' lato loro delle altre chiamate pooled (ricerca semplice),
     * quindi qui serve piu' margine anche a fronte dei limiti alzati dal
     * fornitore lato loro.
     *
     * @param  array<int, int>  $ids
     * @return array<int, array<string, mixed>> id => dettaglio (assente se fallito)
     */
    public function pooledGetServiceReports(array $ids, int $concurrency = 5, int $pauseMicroseconds = 300_000): array
    {
        $results = [];
        $chunks = array_chunk(array_values(array_unique($ids)), $concurrency);

        foreach ($chunks as $index => $chunk) {
            if ($index > 0 && $pauseMicroseconds > 0) {
                usleep($pauseMicroseconds);
            }

            try {
                $responses = Http::pool(function (Pool $pool) use ($chunk) {
                    foreach ($chunk as $id) {
                        $pool->as((string) $id)
                            ->baseUrl(rtrim($this->baseUrl, '/'))
                            ->withBasicAuth($this->username, $this->password)
                            ->acceptJson()
                            ->timeout(10)
                            ->get('/schedelavoro/'.rawurlencode((string) $id));
                    }
                });
            } catch (\Throwable) {
                continue;
            }

            foreach ($chunk as $id) {
                $response = $responses[$id] ?? null;

                if (! $response instanceof Response || ! $response->successful()) {
                    continue;
                }

                $payload = $response->json();

                if (is_array($payload) && isset($payload['id_eureka'])) {
                    $results[$id] = $payload;
                }
            }
        }

        return $results;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function searchArticles(string $query): array
    {
        $payload = $this->get('/articoli/lista/'.rawurlencode($query));

        return array_values(array_filter($payload, fn (mixed $row) => is_array($row)));
    }

    /**
     * Come pooledGetServiceReports() ma per /articoli/lista/(query): usata
     * da eureka:sweep-materials-catalog per scansionare il catalogo con
     * tante ricerche parziali diverse (l'endpoint non offre un elenco
     * completo/paginato, solo ricerca per codice — vedi thread 2026-08-21).
     * Stessa concorrenza bassa (8) e pausa fra i gruppi di
     * pooledGetServiceReports(), per lo stesso motivo (vedi il suo
     * commento): confermato dal vivo senza errori su un giro completo
     * 00-99 (2026-08-21).
     *
     * @param  array<int, string>  $queries
     * @return array<string, array<int, array<string, mixed>>> query => righe trovate
     */
    public function pooledSearchArticles(array $queries, int $concurrency = 8, int $pauseMicroseconds = 400_000): array
    {
        $results = [];
        $chunks = array_chunk(array_values(array_unique($queries)), $concurrency);

        foreach ($chunks as $index => $chunk) {
            if ($index > 0 && $pauseMicroseconds > 0) {
                usleep($pauseMicroseconds);
            }

            try {
                $responses = Http::pool(function (Pool $pool) use ($chunk) {
                    foreach ($chunk as $query) {
                        $pool->as($query)
                            ->baseUrl(rtrim($this->baseUrl, '/'))
                            ->withBasicAuth($this->username, $this->password)
                            ->acceptJson()
                            ->timeout(20)
                            ->get('/articoli/lista/'.rawurlencode($query));
                    }
                });
            } catch (\Throwable) {
                continue;
            }

            foreach ($chunk as $query) {
                $response = $responses[$query] ?? null;

                if (! $response instanceof Response || ! $response->successful()) {
                    continue;
                }

                $rows = $response->json();

                if (is_array($rows)) {
                    $results[$query] = array_values(array_filter($rows, fn (mixed $row) => is_array($row)));
                }
            }
        }

        return $results;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findArticleByCode(string $code): ?array
    {
        $payload = $this->get('/articoli/articolo/'.rawurlencode($code));

        if (isset($payload['id_eureka'])) {
            return $payload;
        }

        if (isset($payload[0]) && is_array($payload[0])) {
            return $payload[0];
        }

        return null;
    }

    /**
     * @param  array{nome?: string, piva?: string, email?: string, like?: bool}  $filters
     * @return array<int, array<string, mixed>>
     */
    public function searchCustomers(array $filters): array
    {
        $query = [];
        foreach (['nome', 'piva', 'email'] as $field) {
            $value = trim((string) ($filters[$field] ?? ''));
            if ($value !== '') {
                $query[$field] = $value;
            }
        }
        if (($filters['like'] ?? false) === true) {
            $query['like'] = 'true';
        }

        $payload = $this->get('/anagrafica/cerca', $query);

        return array_values(array_filter($payload, fn (mixed $row) => is_array($row)));
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function installedArticlesForCustomer(int $customerCode): array
    {
        $payload = $this->get('/show/q/art_installati', ['q' => $customerCode]);

        return array_values(array_filter($payload, fn (mixed $row) => is_array($row)));
    }

    /**
     * @return array{items: array<int, array<string, mixed>>, total: int}
     */
    public function searchSerials(int $articleId, ?string $query = null, int $perPage = 25): array
    {
        $params = array_filter([
            'id_articolo_m10' => $articleId,
            'q' => $query,
            'per_page' => min(100, max(1, $perPage)),
        ], fn (mixed $v) => $v !== null && $v !== '');

        $payload = $this->get('/crm_api/m14/search', $params);

        $items = [];
        foreach (($payload['items'] ?? []) as $item) {
            if (is_array($item)) {
                $items[] = $item;
            }
        }

        return [
            'items' => $items,
            'total' => (int) ($payload['total'] ?? count($items)),
        ];
    }

    /**
     * @return array<mixed>
     */
    private function get(string $path, array $query = []): array
    {
        $response = $this->request()->get($path, $query);
        $response->throw();

        $payload = $response->json();

        if (! is_array($payload)) {
            throw new RuntimeException("Risposta JSON non valida da endpoint Eureka: {$path}");
        }

        return $payload;
    }

    private function request(): PendingRequest
    {
        if ($this->baseUrl === '' || $this->username === '' || $this->password === '') {
            throw new RuntimeException('Configurazione Eureka incompleta: imposta EUREKA_BASE_URL, EUREKA_USERNAME, EUREKA_PASSWORD nel .env.');
        }

        return Http::baseUrl(rtrim($this->baseUrl, '/'))
            ->withBasicAuth($this->username, $this->password)
            ->acceptJson()
            ->connectTimeout(5)
            ->timeout(30);
    }
}
