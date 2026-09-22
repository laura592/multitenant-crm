<?php

namespace App\Support\Gestionale;

use App\Models\EurekaFattura;
use App\Models\ServiceReport;
use App\Models\Tenant;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Controllo del pagante dei rapportini nel gestionale (22/09/2026).
 *
 * Il pagante e' quello della scheda Eureka (PaganteEureka) e il CRM non lo
 * cambia mai da se'. Qui si segnala soltanto quando la FATTURA della scheda
 * e' intestata a un altro: e' la scheda che va corretta su Eureka. Le
 * segnalazioni si vedono in "Verifica sync gestionale"; dopo la correzione
 * su Eureka il CRM rilegge quelle schede e, se tornano, spariscono.
 *
 * Nessuna chiamata a Eureka per il controllo: usa le fatture gia' nel CRM.
 * Le rilegge (sola lettura) solo ricontrolla().
 */
class ControlloPaganteFattura
{
    /**
     * Ricalcola le segnalazioni di tutti i rapportini nel gestionale.
     *
     * @return int quante schede sono da correggere dopo il giro
     */
    public static function segnala(Tenant $tenant): int
    {
        $fatture = static::mappaFatture($tenant->id);
        $aperte = 0;

        static::rapportiniNelGestionale($tenant->id)
            ->whereNotNull('eureka_fatture')
            ->with(['customer.billingCustomer', 'billingCustomer', 'machineUnit.billingCustomer'])
            ->chunkById(500, function (Collection $rapportini) use ($fatture, &$aperte) {
                foreach ($rapportini as $r) {
                    $aperte += static::aggiorna($r, $fatture) ? 1 : 0;
                }
            });

        return $aperte;
    }

    /**
     * Rilegge da Eureka le schede segnalate (o quelle passate), copia il
     * pagante della scheda e rifa' il controllo. E' il "dopo la correzione su
     * Eureka il CRM si allinea".
     *
     * @param  Collection<int, ServiceReport>|null  $rapportini
     * @return array{letti: int, sistemati: int, ancora: int}
     */
    public static function ricontrolla(Tenant $tenant, ?Collection $rapportini = null): array
    {
        $rapportini ??= static::rapportiniNelGestionale($tenant->id)->whereNotNull('pagante_fattura_customer_id')->get();

        if ($rapportini->isEmpty()) {
            return ['letti' => 0, 'sistemati' => 0, 'ancora' => 0];
        }

        $esito = PaganteEureka::rileggiSchede($rapportini);
        $fatture = static::mappaFatture($tenant->id);
        $ancora = 0;

        foreach ($rapportini as $r) {
            $ancora += static::aggiorna($r->fresh(['customer.billingCustomer', 'billingCustomer', 'machineUnit.billingCustomer']), $fatture) ? 1 : 0;
        }

        return ['letti' => $esito['letti'], 'sistemati' => $rapportini->count() - $ancora, 'ancora' => $ancora];
    }

    /** "Va bene cosi'": questa differenza fra scheda e fattura non si segnala piu'. */
    public static function vaBene(ServiceReport $r): void
    {
        ServiceReport::withoutGlobalScopes()->whereKey($r->getKey())->toBase()->update([
            'pagante_fattura_ok' => static::chiave(rescue(fn () => $r->invoiceRecipient()->id, null, false), $r->pagante_fattura_customer_id),
            'pagante_fattura_customer_id' => null,
            'pagante_fattura_rilevato_il' => null,
        ]);
    }

    /**
     * Tutti i rapportini nel gestionale con pagante, scheda e fattura, per il
     * controllo a mano in Excel (riquadro "Schede da correggere su Eureka").
     *
     * @return \Generator<int, array<string, string>>
     */
    public static function righeEsportazione(Tenant $tenant): \Generator
    {
        $fatture = static::mappaFatture($tenant->id);
        $nomi = \App\Models\Customer::withoutGlobalScopes()->where('tenant_id', $tenant->id)->pluck('company_name', 'id');

        $rapportini = static::rapportiniNelGestionale($tenant->id)
            ->with(['customer.billingCustomer', 'billingCustomer', 'machineUnit.billingCustomer'])
            // Niente orderBy qui: lazyById legge a blocchi per id, e un altro
            // ordinamento gli fa saltare righe (1.578 su 3.772 in prova).
            // In Excel si ordina per data con un clic.
            ->lazyById(500);

        foreach ($rapportini as $r) {
            $numeri = collect($r->eureka_fatture ?? [])
                ->filter(fn ($f) => is_array($f) && ! empty($f['numero_fattura']) && ! empty($f['data_fattura']));
            $intestatari = $numeri
                ->flatMap(fn (array $f) => $fatture[Carbon::parse($f['data_fattura'])->year.'|'.$f['numero_fattura']] ?? [])
                ->unique()
                ->map(fn ($id) => $nomi[$id] ?? '?')
                ->implode(' / ');
            $pagante = rescue(fn () => $r->invoiceRecipient()->company_name, null, false);

            yield [
                'Rapportino' => $r->number,
                'N. gestionale' => (string) $r->gestionale_number,
                'Data' => (string) $r->intervention_date?->format('d/m/Y'),
                'Cliente' => (string) $r->customer?->company_name,
                'Pagante nel CRM' => (string) $pagante,
                'Destinazione sulla scheda' => trim(($r->eureka_destinazione_label ?? '').($r->eureka_destinazione_code ? ' (codice '.$r->eureka_destinazione_code.')' : '')),
                'Fattura' => (string) $r->etichettaFatturaEureka(),
                'Fattura intestata a' => $intestatari,
                'Esito' => match (true) {
                    $r->pagante_fattura_customer_id !== null => 'da correggere su Eureka',
                    $numeri->isEmpty() => 'senza fattura',
                    default => 'ok',
                },
            ];
        }
    }

    public static function rapportiniNelGestionale(string $tenantId)
    {
        return ServiceReport::withoutGlobalScopes()
            ->whereNull('deleted_at')
            ->where('tenant_id', $tenantId)
            ->where(fn ($q) => $q
                ->where('source', ServiceReport::SOURCE_EUREKA)
                ->orWhere('gestionale_sync_status', 'sent')
                ->orWhereNotNull('eureka_service_report_id'));
    }

    /**
     * @param  array<string, array<int, string>>  $fatture
     * @return bool se il rapportino resta da correggere su Eureka
     */
    private static function aggiorna(ServiceReport $r, array $fatture): bool
    {
        $intestatari = collect($r->eureka_fatture ?? [])
            ->filter(fn ($f) => is_array($f) && ! empty($f['numero_fattura']) && ! empty($f['data_fattura']))
            ->map(fn (array $f) => $fatture[Carbon::parse($f['data_fattura'])->year.'|'.$f['numero_fattura']] ?? []);

        // Numero ambiguo (clienti diversi) o fatture a clienti diversi: il
        // controllo non si fa, non si segnala niente di incerto.
        $unici = $intestatari->contains(fn (array $c) => count($c) > 1) ? collect() : $intestatari->flatten()->unique();
        $fattura = $unici->count() === 1 ? $unici->first() : null;
        $scheda = rescue(fn () => $r->invoiceRecipient()->id, null, false);

        $daCorreggere = $fattura && $scheda && $fattura !== $scheda && $r->pagante_fattura_ok !== static::chiave($scheda, $fattura);

        $valori = $daCorreggere
            ? ['pagante_fattura_customer_id' => $fattura, 'pagante_fattura_rilevato_il' => $r->pagante_fattura_customer_id === $fattura ? $r->pagante_fattura_rilevato_il : now()]
            : ['pagante_fattura_customer_id' => null, 'pagante_fattura_rilevato_il' => null];

        // Una lettura, non una modifica del rapportino: niente eventi.
        if ($r->pagante_fattura_customer_id !== $valori['pagante_fattura_customer_id']) {
            ServiceReport::withoutGlobalScopes()->whereKey($r->getKey())->toBase()->update($valori);
        }

        return $daCorreggere;
    }

    /**
     * Le fatture vere ai clienti in memoria, una volta sola: anno|numero =>
     * clienti distinti. Autofatture dei fornitori escluse.
     *
     * @return array<string, array<int, string>>
     */
    private static function mappaFatture(string $tenantId): array
    {
        return EurekaFattura::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->where('tipo', EurekaFattura::TIPO_CLIENTE)
            ->whereNotNull('customer_id')
            ->where(fn ($q) => $q->whereNull('causale')->orWhereNotIn('causale', EurekaFattura::CAUSALI_NON_CLIENTI))
            ->get(['numero_doc', 'data_doc', 'customer_id'])
            ->groupBy(fn (EurekaFattura $f) => $f->data_doc?->year.'|'.$f->numero_doc)
            ->map(fn ($g) => $g->pluck('customer_id')->unique()->values()->all())
            ->all();
    }

    private static function chiave(?string $scheda, ?string $fattura): string
    {
        return $scheda.'|'.$fattura;
    }
}
