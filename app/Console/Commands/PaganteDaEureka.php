<?php

namespace App\Console\Commands;

use App\Models\Customer;
use App\Models\ServiceReport;
use App\Models\Tenant;
use App\Support\EurekaClient;
use App\Support\Gestionale\PaganteEureka;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Riallinea il pagante dei rapportini importati al pagante della scheda su
 * Eureka (22/09/2026): se il documento e' nel gestionale, chi paga e' quello
 * e non cambia piu'.
 *
 * Serve per lo storico importato senza dettaglio (696 schede del 12/08/2026,
 * senza "destinazione") e per i pochi con un pagante congelato diverso da
 * Eureka. Legge soltanto da Eureka: li' non si scrive niente. Nel CRM tocca
 * solo pagante e destinazione, non descrizioni ne' righe.
 *
 * Di default mostra soltanto; scrive con --esegui, dopo conferma.
 */
class PaganteDaEureka extends Command
{
    protected $signature = 'rapportini:pagante-da-eureka
        {--tenant=  : Slug tenant (default: tenant master)}
        {--tutti    : Tutti i rapportini importati, non solo quelli senza pagante}
        {--esegui   : Scrive le correzioni (senza, mostra soltanto)}';

    protected $description = 'Mostra (e con --esegui scrive) il pagante dei rapportini importati come risulta su Eureka';

    public function __construct(private readonly EurekaClient $eureka)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $tenant = $this->option('tenant')
            ? Tenant::where('slug', $this->option('tenant'))->firstOrFail()
            : Tenant::where('is_master', true)->firstOrFail();

        $rapportini = ServiceReport::withoutGlobalScopes()
            ->whereNull('deleted_at')
            ->where('tenant_id', $tenant->id)
            ->where('source', ServiceReport::SOURCE_EUREKA)
            ->whereNotNull('eureka_service_report_id')
            ->when(! $this->option('tutti'), fn ($q) => $q->whereNull('billing_customer_id'))
            ->with(['customer', 'billingCustomer'])
            ->get();

        $this->info("Rapportini da controllare su Eureka: {$rapportini->count()}");

        if ($rapportini->isEmpty()) {
            return self::SUCCESS;
        }

        $dettagli = $this->eureka->pooledGetServiceReports($rapportini->pluck('eureka_service_report_id')->map(fn ($id) => (int) $id)->all());

        $correzioni = collect();
        $nonLetti = 0;
        $senzaCliente = collect();

        foreach ($rapportini as $r) {
            $detail = $dettagli[(int) $r->eureka_service_report_id] ?? null;

            if (! $detail) {
                $nonLetti++;

                continue;
            }

            $pagante = PaganteEureka::daDettaglio($detail, [], $tenant->id, $r->customer_id);

            if (! $pagante['trovato']) {
                $senzaCliente->push([$r->number, $r->intervention_date?->format('d/m/Y'), $pagante['label'] ?? '—', $pagante['code']]);
            }

            $nuovo = [
                'billing_customer_id' => $pagante['customer_id'],
                'eureka_destinazione_code' => $pagante['code'],
                'eureka_destinazione_label' => $pagante['label'],
            ];

            $cambia = collect($nuovo)->contains(fn ($valore, $campo) => (string) $r->{$campo} !== (string) $valore);

            if ($cambia) {
                $correzioni->push([$r, $nuovo]);
            }
        }

        if ($nonLetti > 0) {
            $this->warn("Scheda non letta da Eureka per {$nonLetti} rapportini (errore o risposta vuota): restano come sono, rilancia piu' tardi.");
        }

        if ($senzaCliente->isNotEmpty()) {
            $this->warn("Pagante indicato da Eureka ma non presente come cliente nel CRM ({$senzaCliente->count()}): il pagante resta vuoto finche' non si crea il cliente.");
            $this->table(['Rapportino', 'Data', 'Pagante su Eureka', 'Codice Eureka'], $senzaCliente->all());
        }

        if ($correzioni->isEmpty()) {
            $this->info('Tutti i paganti sono gia\' allineati a Eureka.');

            return self::SUCCESS;
        }

        $nomi = Customer::withoutGlobalScopes()
            ->whereIn('id', $correzioni->pluck('1.billing_customer_id')->filter()->unique())
            ->pluck('company_name', 'id');

        $this->table(
            ['Rapportino', 'Data', 'Cliente', 'Pagante oggi nel CRM', 'Pagante su Eureka'],
            $correzioni->map(fn ($c) => [
                $c[0]->number,
                $c[0]->intervention_date?->format('d/m/Y'),
                $c[0]->customer?->company_name ?? '—',
                $c[0]->billingCustomer?->company_name ?? '(calcolato dalla macchina/cliente)',
                $nomi[$c[1]['billing_customer_id']] ?? ($c[1]['eureka_destinazione_label'] ? $c[1]['eureka_destinazione_label'].' (non nel CRM)' : '—'),
            ])->all(),
        );

        if (! $this->option('esegui')) {
            $this->warn('Solo anteprima: niente e\' stato scritto. Rilancia con --esegui per applicare.');

            return self::SUCCESS;
        }

        if (! $this->confirm("Scrivere il pagante di Eureka su {$correzioni->count()} rapportini?")) {
            return self::SUCCESS;
        }

        DB::transaction(function () use ($correzioni) {
            foreach ($correzioni as [$rapportino, $nuovo]) {
                // Senza eventi: solo il pagante, nessun ricalcolo di piani,
                // lavaggi o altro legato al salvataggio del rapportino.
                $rapportino->forceFill($nuovo)->saveQuietly();
            }
        });

        $this->info("Fatto: {$correzioni->count()} rapportini allineati al pagante di Eureka.");

        return self::SUCCESS;
    }
}
