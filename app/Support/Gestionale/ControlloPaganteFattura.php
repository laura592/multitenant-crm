<?php

namespace App\Support\Gestionale;

use App\Models\Customer;
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
     * I tre problemi, ognuno con il suo foglio nell'Excel e le sue colonne:
     * le schede da correggere non vanno confuse con le decine di rapportini
     * senza fattura, che sono un'altra cosa.
     *
     * @var array<string, array{0: string, 1: array<int, string>}>
     */
    public const CATEGORIE = [
        'da_correggere' => ['Da correggere su Eureka', ['Rapportino', 'N. gestionale', 'Data', 'Cliente', 'Pagante nel CRM', 'Destinazione sulla scheda', 'Fattura', 'Fattura intestata a']],
        'destinazione' => ['Destinazione incoerente', ['Rapportino', 'N. gestionale', 'Data', 'Cliente', 'Pagante nel CRM', 'Destinazione sulla scheda']],
        'senza_fattura' => ['Senza fattura', ['Problema', 'Rapportino', 'N. gestionale', 'Data', 'Cliente', 'Pagante nel CRM', 'Fattura', 'Indizio']],
    ];

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
     * Solo i rapportini nel gestionale con una differenza o un errore, per il
     * controllo a mano in Excel (riquadro "Schede da correggere su Eureka").
     * I casi normali restano fuori: tutto a posto, e senza fattura perche'
     * recente (la fattura di fine mese deve ancora uscire) o senza importo.
     *
     * @return \Generator<int, array<string, string>>
     */
    public static function righeEsportazione(Tenant $tenant): \Generator
    {
        $fatture = static::mappaFatture($tenant->id);
        $nomi = Customer::withoutGlobalScopes()->where('tenant_id', $tenant->id)->pluck('company_name', 'id');

        // Niente orderBy qui: lazyById legge a blocchi per id, e un altro
        // ordinamento gli fa saltare righe. In Excel si ordina con un clic.
        $rapportini = static::rapportiniNelGestionale($tenant->id)
            ->with(['customer.billingCustomer', 'billingCustomer', 'machineUnit.billingCustomer'])
            ->lazyById(500);

        foreach ($rapportini as $r) {
            $numeri = collect($r->eureka_fatture ?? [])
                ->filter(fn ($f) => is_array($f) && ! empty($f['numero_fattura']) && ! empty($f['data_fattura']));
            $pagante = rescue(fn () => $r->invoiceRecipient()->company_name, null, false);
            $scritto = $r->eureka_destinazione_label;

            $daCorreggere = $r->pagante_fattura_customer_id !== null;
            $destinazione = $scritto && $pagante && ! static::simili($scritto, $pagante);
            $senzaFattura = $numeri->isEmpty() && ! in_array($r->eureka_fattura_motivo, [SenzaFatturaCollegata::RECENTE, SenzaFatturaCollegata::SENZA_IMPORTO], true);

            $problemi = array_filter([
                $daCorreggere ? 'da correggere su Eureka: fattura intestata a un altro' : null,
                $destinazione ? 'scheda: codice e nome della destinazione diversi' : null,
                $senzaFattura ? 'senza fattura: '.static::motivo($r->eureka_fattura_motivo) : null,
            ]);

            if ($problemi === []) {
                continue;
            }

            yield [
                // Un rapportino con piu' problemi finisce nel foglio del piu'
                // importante, ma la colonna Problema li elenca tutti.
                'Categoria' => match (true) {
                    $daCorreggere => 'da_correggere',
                    $destinazione => 'destinazione',
                    default => 'senza_fattura',
                },
                'Problema' => implode(' + ', $problemi),
                'Rapportino' => $r->number,
                'N. gestionale' => (string) $r->gestionale_number,
                'Data' => (string) $r->intervention_date?->format('d/m/Y'),
                'Cliente' => (string) $r->customer?->company_name,
                'Pagante nel CRM' => (string) $pagante,
                'Destinazione sulla scheda' => trim(($scritto ?? '').($r->eureka_destinazione_code ? ' (codice '.$r->eureka_destinazione_code.')' : '')),
                'Fattura' => (string) $r->etichettaFatturaEureka(),
                'Fattura intestata a' => $numeri
                    ->flatMap(fn (array $f) => $fatture[Carbon::parse($f['data_fattura'])->year.'|'.$f['numero_fattura']] ?? [])
                    ->unique()
                    ->map(fn ($id) => $nomi[$id] ?? '?')
                    ->implode(' / '),
                'Indizio' => (string) $r->eureka_fattura_indizio,
            ];
        }
    }

    private static function simili(string $a, string $b): bool
    {
        $a = PaganteEureka::normalizza($a);
        $b = PaganteEureka::normalizza($b);

        return str_contains($a, substr($b, 0, 8)) || str_contains($b, substr($a, 0, 8));
    }

    private static function motivo(?string $motivo): string
    {
        return match ($motivo) {
            SenzaFatturaCollegata::DOPPIONE => 'sembra il doppione di una scheda gia\' fatturata',
            SenzaFatturaCollegata::FATTURA_NON_COLLEGATA => 'probabilmente in una fattura fatta a mano',
            SenzaFatturaCollegata::NON_NELLA_FATTURA => 'lasciato fuori da una fattura fatta dalle schede',
            SenzaFatturaCollegata::DA_VERIFICARE => 'da verificare',
            default => 'non ancora classificato',
        };
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
