<?php

namespace App\Support\Gestionale;

use App\Models\Customer;
use App\Models\ServiceReport;
use App\Support\EurekaClient;
use Illuminate\Support\Collection;

/**
 * Chi paga un rapportino gia' nel gestionale (22/09/2026, deciso con Laura):
 * lo dice la SCHEDA Eureka, e il CRM lo copia senza mai deciderlo da se'.
 *
 * - Pagante = la destinazione della scheda; se e' vuota, l'intestatario (il
 *   cliente del rapportino).
 * - La fattura serve solo da CONTROLLO: se e' intestata a un altro, e' la
 *   scheda che va corretta su Eureka (ControlloPaganteFattura). Il CRM poi
 *   la rilegge e si allinea da solo.
 *
 * Attenzione alla destinazione: a volte ha il nome ma non il codice
 * anagrafica (id_eureka 0), es. SL-346/2023 "MARTELLOZZO LORENZO & C. SAS".
 * Non e' vuota: si cerca il cliente per nome.
 */
class PaganteEureka
{
    /** @var array<string, array<string, array<int, string>>> tenant => nome normalizzato => id clienti */
    private static array $perNome = [];

    /**
     * Il pagante secondo la scheda (dettaglio GET /schedelavoro/{id}).
     *
     * @param  array<string, mixed>  $detail
     * @param  array<string, mixed>  $summary
     * @return array{customer_id: ?string, code: ?int, label: ?string, trovato: bool}
     *                                                                                trovato=false: la scheda indica un pagante che nel CRM non si ritrova
     *                                                                                (codice o nome sconosciuti, o nome che corrisponde a piu' clienti).
     */
    public static function daScheda(array $detail, array $summary, string $tenantId, ?string $clienteId): array
    {
        $destinazione = is_array($detail['destinazione'] ?? null) ? $detail['destinazione'] : [];
        $codice = (int) ($destinazione['id_eureka'] ?? 0);
        $nome = trim((string) ($destinazione['rag_sociale'] ?? ''));
        $intestatario = (int) ($detail['id_intestatario'] ?? $summary['id_codice_f15'] ?? 0);

        $intestatarioPaga = ['customer_id' => $clienteId, 'code' => null, 'label' => null, 'trovato' => $clienteId !== null];

        if ($codice > 0) {
            if ($codice === $intestatario) {
                return $intestatarioPaga;
            }

            $id = Customer::withoutGlobalScopes()->where('tenant_id', $tenantId)->where('gestionale_code', $codice)->value('id');

            // Nome e codice che dicono due aziende diverse: vale il NOME
            // (23/09/2026). Sulle schede corrette a mano Eureka si tiene il
            // codice di prima — SL-251/2024 e' intestata "DERSUT CAFFE' SPA"
            // col codice 782, che e' Goppion, e la fattura e' di Dersut. Il
            // nome e' quello che si legge sul documento: se corrisponde a un
            // cliente solo, e' quello che paga.
            if ($id !== null && $nome !== '' && ($daNome = static::unicoPerNome($tenantId, $nome)) && $daNome !== $id) {
                return ['customer_id' => $daNome, 'code' => $codice, 'label' => $nome, 'trovato' => true];
            }

            return ['customer_id' => $id, 'code' => $codice, 'label' => $nome ?: null, 'trovato' => $id !== null];
        }

        if ($nome === '') {
            return $intestatarioPaga;
        }

        // Destinazione scritta senza codice: si cerca per nome.
        $cliente = $clienteId ? Customer::withoutGlobalScopes()->whereKey($clienteId)->value('company_name') : null;
        if ($cliente !== null && static::normalizza($cliente) === static::normalizza($nome)) {
            return $intestatarioPaga;
        }

        $id = static::unicoPerNome($tenantId, $nome);

        return ['customer_id' => $id, 'code' => null, 'label' => $nome, 'trovato' => $id !== null];
    }

    /**
     * L'unico cliente che si chiama cosi', o null se non c'e' o se sono piu'
     * d'uno: su un nome ambiguo non si sceglie a caso.
     */
    private static function unicoPerNome(string $tenantId, string $nome): ?string
    {
        $trovati = static::clientiPerNome($tenantId)[static::normalizza($nome)] ?? [];

        return count($trovati) === 1 ? $trovati[0] : null;
    }

    /**
     * Rilegge da Eureka (sola lettura) le schede dei rapportini e copia nel CRM
     * pagante e destinazione. Tocca solo quelli, senza eventi.
     *
     * @param  Collection<int, ServiceReport>  $rapportini
     * @return array{letti: int, cambiati: array<int, array{0: ServiceReport, 1: ?string, 2: ?string}>, non_trovati: array<int, array{0: ServiceReport, 1: ?string}>, non_letti: int, da_scrivere: array<int, array{0: ServiceReport, 1: array<string, mixed>}>}
     *                                                                                                                                                                                                                                                           cambiati: [rapportino, pagante prima, pagante dopo]. Con $scrivi=false
     *                                                                                                                                                                                                                                                           non scrive niente: da_scrivere si passa poi a scrivi().
     */
    public static function rileggiSchede(Collection $rapportini, bool $scrivi = true): array
    {
        $esito = ['letti' => 0, 'cambiati' => [], 'non_trovati' => [], 'non_letti' => 0, 'da_scrivere' => []];

        $dettagli = app(EurekaClient::class)->pooledGetServiceReports(
            $rapportini->map(fn (ServiceReport $r) => $r->idSchedaEureka())->filter()->values()->all()
        );

        foreach ($rapportini as $r) {
            $detail = $dettagli[$r->idSchedaEureka()] ?? null;

            if (! $detail) {
                $esito['non_letti']++;

                continue;
            }

            $esito['letti']++;
            $scheda = static::daScheda($detail, [], $r->tenant_id, $r->customer_id);

            if (! $scheda['trovato']) {
                $esito['non_trovati'][] = [$r, $scheda['label']];
            }

            // Pagante che la scheda indica ma che nel CRM non si ritrova: non
            // si inventa, si lascia quello che c'e'.
            $pagante = $scheda['trovato'] ? $scheda['customer_id'] : $r->billing_customer_id;

            $valori = [
                'billing_customer_id' => $pagante,
                'eureka_destinazione_code' => $scheda['code'],
                'eureka_destinazione_label' => $scheda['label'],
            ];

            $prima = rescue(fn () => $r->invoiceRecipient()->id, null, false);

            if ($pagante !== null && $pagante !== $prima) {
                $esito['cambiati'][] = [$r, $prima, $pagante];
            }

            $diverso = collect($valori)->contains(fn ($v, $k) => (string) $r->{$k} !== (string) $v);

            if ($diverso) {
                $esito['da_scrivere'][] = [$r, $valori];
            }
        }

        if ($scrivi) {
            static::scrivi($esito['da_scrivere']);
        }

        return $esito;
    }

    /**
     * Copia nel CRM quello che rileggiSchede() ha letto. Solo pagante e
     * destinazione, senza eventi: una lettura dal gestionale, non una
     * modifica del rapportino.
     *
     * @param  array<int, array{0: ServiceReport, 1: array<string, mixed>}>  $daScrivere
     */
    public static function scrivi(array $daScrivere): void
    {
        foreach ($daScrivere as [$r, $valori]) {
            ServiceReport::withoutGlobalScopes()->whereKey($r->getKey())->toBase()->update($valori);
            $r->forceFill($valori)->syncOriginalAttributes(array_keys($valori));
            $r->unsetRelation('billingCustomer');
        }
    }

    public static function normalizza(string $nome): string
    {
        return preg_replace('/[^A-Z0-9]/', '', mb_strtoupper($nome));
    }

    /** @return array<string, array<int, string>> */
    private static function clientiPerNome(string $tenantId): array
    {
        return static::$perNome[$tenantId] ??= Customer::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->whereNull('deleted_at')
            ->whereNotNull('company_name')
            ->get(['id', 'company_name'])
            ->groupBy(fn (Customer $c) => static::normalizza($c->company_name))
            ->map(fn ($g) => $g->pluck('id')->all())
            ->all();
    }
}
