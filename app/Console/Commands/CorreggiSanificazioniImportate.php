<?php

namespace App\Console\Commands;

use App\Models\ServiceReport;
use App\Models\Tenant;
use App\Support\Gestionale\RegistroSync;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Riporta a "sanificazione" i rapportini importati da Eureka che lo erano ma
 * sono finiti "riparazione".
 *
 * L'import riconosceva installazione, garanzia e manutenzione dal testo e
 * ripiegava su "riparazione" per tutto il resto: le sanificazioni impianto
 * acqua (riga SANIFICAZIONE, di solito con cartucce e filtri) ci finivano
 * tutte — 61 su 61 in produzione al 21/09/2026. Da ora l'import le riconosce
 * dall'articolo (ImportEurekaServiceReports::haArticoloSanificazione); questo
 * sistema quelle gia' entrate.
 *
 * Tocca SOLO i "riparazione": e' il ripiego di quando la regola non sapeva
 * cosa mettere. Un tipo scelto da qualcuno (installazione, garanzia,
 * manutenzione) non si cambia.
 *
 * Il salvataggio salta gli automatismi del modello — su un rapportino chiuso
 * fisserebbe il pagante, che qui non c'entra — ma il piano di manutenzione
 * si riallinea (syncMaintenanceSchedule): una sanificazione conta come
 * lavaggio (ServiceReport::countsAsLavaggio), e lo storico del cliente deve
 * vederla.
 */
class CorreggiSanificazioniImportate extends Command
{
    private const OPERAZIONE = 'correggi-sanificazioni';

    protected $signature = 'rapportini:correggi-sanificazioni
                            {--tenant=alex : slug del tenant}
                            {--dry-run : mostra cosa cambierebbe, senza scrivere}';

    protected $description = 'Riporta a "sanificazione" i rapportini con la riga SANIFICAZIONE finiti "riparazione"';

    public function handle(): int
    {
        $tenant = Tenant::where('slug', $this->option('tenant'))->firstOrFail();

        $rapportini = $this->daCorreggere($tenant)->with(['customer' => fn ($q) => $q->withoutGlobalScopes()])->get();

        if ($rapportini->isEmpty()) {
            $this->info('Nessun rapportino da correggere: le sanificazioni sono gia\' a posto.');

            return self::SUCCESS;
        }

        $this->table(['Rapportino', 'Scheda Eureka', 'Data', 'Cliente'], $rapportini->map(fn (ServiceReport $r) => [
            $r->number,
            $r->gestionale_number ?? '—',
            $r->intervention_date?->format('d/m/Y'),
            $r->customer?->company_name,
        ])->all());

        $this->line("{$rapportini->count()} rapportini da \"riparazione\" a \"sanificazione\".");

        if ($this->option('dry-run')) {
            $this->warn('--dry-run: niente e\' stato scritto.');

            return self::SUCCESS;
        }

        if (! $this->confirm("Correggo {$rapportini->count()} rapportini?", false)) {
            $this->line('Annullato: nessuna modifica.');

            return self::SUCCESS;
        }

        RegistroSync::avvio(self::OPERAZIONE, ['tenant' => $tenant->slug, 'rapportini' => $rapportini->count()]);

        foreach ($rapportini as $rapportino) {
            $rapportino->intervention_type = ServiceReport::TYPE_SANIFICAZIONE;
            $rapportino->saveQuietly();
            $rapportino->syncMaintenanceSchedule();

            RegistroSync::movimento(self::OPERAZIONE, 'sanificazione', [
                'rapportino' => $rapportino->number,
                'scheda' => $rapportino->gestionale_number,
            ]);
        }

        RegistroSync::esito(self::OPERAZIONE, ['corretti' => $rapportini->count()]);
        $this->info("Corretti: {$rapportini->count()}.");

        return self::SUCCESS;
    }

    private function daCorreggere(Tenant $tenant): Builder
    {
        return ServiceReport::query()
            ->withoutGlobalScopes()
            ->whereNull('deleted_at')
            ->where('tenant_id', $tenant->id)
            ->where('intervention_type', ServiceReport::TYPE_RIPARAZIONE)
            ->whereExists(fn ($q) => $q->select(DB::raw(1))
                ->from('service_report_materials')
                ->join('materials', 'materials.id', '=', 'service_report_materials.material_id')
                ->whereColumn('service_report_materials.service_report_id', 'service_reports.id')
                ->whereNull('service_report_materials.deleted_at')
                ->where('materials.code', ImportEurekaServiceReports::ARTICOLO_SANIFICAZIONE))
            ->orderBy('intervention_date');
    }
}
