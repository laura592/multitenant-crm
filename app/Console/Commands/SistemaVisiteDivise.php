<?php

namespace App\Console\Commands;

use App\Models\ServiceReport;
use App\Models\Tenant;
use App\Support\Gestionale\RiabbinaImportati;
use Illuminate\Console\Command;

/**
 * Una volta sola (21/09/2026): i rapportini delle schede Eureka che sono
 * la parte in piu' di una visita gia' registrata dal tecnico nascevano
 * vuoti e col tecnico predefinito. Da qui in avanti ci pensa l'import
 * (RiabbinaImportati::stessaVisita); questo sistema quelli gia' creati.
 *
 * La parte in piu' si riconosce cosi': scheda Eureka, stesso cliente e
 * stesso giorno di un rapportino del tecnico che e' gia' legato a
 * un'altra scheda. Se quel giorno i rapportini del tecnico sono due, non
 * si sa di chi e' la visita e non si tocca.
 */
class SistemaVisiteDivise extends Command
{
    protected $signature = 'rapportini:sistema-visite-divise
                            {--tenant=alex : slug del tenant}
                            {--dry-run : mostra cosa cambierebbe, senza scrivere}';

    protected $description = 'Da\' tecnico e note del tecnico ai rapportini delle schede Eureka che dividono una visita';

    public function handle(): int
    {
        $tenant = Tenant::query()->where('slug', $this->option('tenant'))->firstOrFail();

        $coppie = ServiceReport::withoutGlobalScopes()
            ->with('technician')
            ->where('tenant_id', $tenant->id)
            ->whereNull('deleted_at')
            ->where('source', ServiceReport::SOURCE_EUREKA)
            ->whereNotNull('eureka_service_report_id')
            ->orderBy('number')
            ->get()
            ->map(function (ServiceReport $parte) {
                $delTecnico = RiabbinaImportati::rapportinoDelTecnico($parte);

                return $delTecnico ? [$parte, $delTecnico->load('technician')] : null;
            })
            ->filter()
            ->values();

        $riferimento = fn (ServiceReport $t) => "Stessa visita del rapportino {$t->number}";
        $daFare = $coppie->reject(fn ($c) => $c[0]->technician_id === $c[1]->technician_id
            && str_contains((string) $c[0]->notes, $riferimento($c[1])));

        if ($daFare->isEmpty()) {
            $this->info('Niente da sistemare.');

            return self::SUCCESS;
        }

        $this->table(['Rapportino', 'Scheda', 'Data', 'Cliente', 'Tecnico ora', 'Tecnico giusto', 'Rapportino del tecnico'], $daFare->map(fn ($c) => [
            $c[0]->number,
            $c[0]->gestionale_number,
            $c[0]->intervention_date->format('d/m/Y'),
            $c[0]->customer()->withoutGlobalScopes()->value('company_name'),
            $c[0]->technician?->name,
            $c[1]->technician?->name,
            $c[1]->number,
        ])->all());

        if ($this->option('dry-run')) {
            $this->warn("--dry-run: {$daFare->count()} rapportini da sistemare, niente e' stato scritto.");

            return self::SUCCESS;
        }

        if (! $this->confirm("Sistemo {$daFare->count()} rapportini?", false)) {
            $this->line('Annullato: nessuna modifica.');

            return self::SUCCESS;
        }

        $fatti = $daFare->filter(fn ($c) => RiabbinaImportati::stessaVisita($c[1], $c[0]))->count();
        $this->info("Sistemati: {$fatti}.");

        return self::SUCCESS;
    }
}
