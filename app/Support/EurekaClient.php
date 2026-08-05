<?php

namespace App\Support;

use Illuminate\Http\Client\PendingRequest;
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
     * @return array<int, array<string, mixed>>
     */
    public function searchArticles(string $query): array
    {
        $payload = $this->get('/articoli/lista/'.rawurlencode($query));

        return array_values(array_filter($payload, fn (mixed $row) => is_array($row)));
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
