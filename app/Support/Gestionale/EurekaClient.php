<?php

namespace App\Support\Gestionale;

use App\Models\Tenant;
use Illuminate\Support\Facades\Http;

/**
 * Client per l'API REST del gestionale Eureka (ALEX srl), usata per inviare
 * i rapportini firmati come "scheda lavoro" (vedi ServiceReport::toGestionalePayload()).
 * Le credenziali sono per-tenant (Tenant::gestionale_eureka_*), non globali:
 * questo CRM e' multi-tenant e Eureka e' un'integrazione specifica di ALEX,
 * non di ogni partner.
 */
class EurekaClient
{
    public function __construct(private readonly Tenant $tenant)
    {
        if (! $tenant->hasGestionaleEurekaCredentials()) {
            throw new GestionaleEurekaException("Il tenant \"{$tenant->name}\" non ha credenziali Eureka configurate.");
        }
    }

    /**
     * Crea o aggiorna (via objectId + checkObjectId) una scheda lavoro.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed> il documento riletto da Eureka
     */
    public function inviaSchedaLavoro(array $payload, string $objectId): array
    {
        $url = rtrim($this->tenant->gestionale_eureka_base_url, '/').'/schedelavoro/?checkObjectId=true';

        $response = Http::withBasicAuth(
            $this->tenant->gestionale_eureka_username,
            $this->tenant->gestionale_eureka_password,
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
            $url = rtrim($this->tenant->gestionale_eureka_base_url, '/').'/anagrafica/cerca?'.http_build_query([
                'nome' => $query,
                'like' => 'true',
            ]);

            $response = Http::withBasicAuth(
                $this->tenant->gestionale_eureka_username,
                $this->tenant->gestionale_eureka_password,
            )->timeout(10)->get($url);

            if (! $response->successful()) {
                return [];
            }

            return $response->json() ?? [];
        } catch (\Throwable) {
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
            $url = rtrim($this->tenant->gestionale_eureka_base_url, '/').'/anagrafica/cerca?'.http_build_query([
                'piva' => $piva,
            ]);

            $response = Http::withBasicAuth(
                $this->tenant->gestionale_eureka_username,
                $this->tenant->gestionale_eureka_password,
            )->timeout(10)->get($url);

            if (! $response->successful()) {
                return [];
            }

            return $response->json() ?? [];
        } catch (\Throwable) {
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
            $url = rtrim($this->tenant->gestionale_eureka_base_url, '/').'/show/q/art_installati?'.http_build_query([
                'q' => $customerCode,
            ]);

            $response = Http::withBasicAuth(
                $this->tenant->gestionale_eureka_username,
                $this->tenant->gestionale_eureka_password,
            )->timeout(10)->get($url);

            if (! $response->successful()) {
                return [];
            }

            return $response->json() ?? [];
        } catch (\Throwable) {
            return [];
        }
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
            $url = rtrim($this->tenant->gestionale_eureka_base_url, '/').'/articoli/lista/'.rawurlencode($query);

            $response = Http::withBasicAuth(
                $this->tenant->gestionale_eureka_username,
                $this->tenant->gestionale_eureka_password,
            )->timeout(10)->get($url);

            if (! $response->successful()) {
                return [];
            }

            return $response->json() ?? [];
        } catch (\Throwable) {
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
            $url = rtrim($this->tenant->gestionale_eureka_base_url, '/').'/crm_api/m14/search?'.http_build_query(array_filter([
                'id_articolo_m10' => $idArticoloM10,
                'q' => $query,
                'per_page' => 25,
            ]));

            $response = Http::withBasicAuth(
                $this->tenant->gestionale_eureka_username,
                $this->tenant->gestionale_eureka_password,
            )->timeout(10)->get($url);

            if (! $response->successful()) {
                return [];
            }

            return $response->json('items') ?? [];
        } catch (\Throwable) {
            return [];
        }
    }
}
