<?php

namespace App\Console\Commands;

use App\Models\ServiceReport;
use App\Models\Tenant;
use App\Support\Gestionale\RegistroSync;
use App\Support\Gestionale\RiabbinaImportati;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * Sistema le schede importate da Eureka da una certa ora in poi: unisce ai
 * rapportini del CRM quelle che sono lo stesso intervento, lascia da decidere
 * quelle ambigue, tiene quelle nuove e ricompatta i numeri. Vedi
 * RiabbinaImportati.
 *
 * Nasce dai 58 rapportini recuperati il 21/09/2026 prima che l'import sapesse
 * abbinare: 35 erano doppioni di rapportini del tecnico. Da quel giorno
 * l'import lo fa da solo alla fine di ogni giro; questo serve per rimettere a
 * posto quello che e' entrato prima.
 */
class RiabbinaRapportiniImportati extends Command
{
    protected $signature = 'rapportini:riabbina-importati
                            {--dal= : le schede importate da quest\'ora in poi (es. "2026-09-21 12:00")}
                            {--tenant=alex : slug del tenant}
                            {--dry-run : mostra cosa farebbe, senza scrivere}';

    protected $description = 'Unisce ai rapportini del CRM le schede Eureka importate che sono lo stesso intervento, e ricompatta i numeri';

    public function handle(): int
    {
        if (blank($this->option('dal'))) {
            $this->error('Serve --dal: da che ora considerare le schede importate (es. --dal="2026-09-21 12:00").');

            return self::FAILURE;
        }

        $tenant = Tenant::where('slug', $this->option('tenant'))->firstOrFail();
        $dal = Carbon::parse($this->option('dal'));

        $schede = ServiceReport::withoutGlobalScopes()
            ->whereNull('deleted_at')
            ->where('tenant_id', $tenant->id)
            ->where('source', ServiceReport::SOURCE_EUREKA)
            ->where('created_at', '>=', $dal)
            ->with(['machineUnit', 'materialsUsed.material'])
            ->get();

        if ($schede->isEmpty()) {
            $this->info('Nessuna scheda importata da '.$dal->format('d/m/Y H:i').'.');

            return self::SUCCESS;
        }

        $piano = RiabbinaImportati::esegui($schede, prova: true);
        $this->mostra($schede->count(), $piano);

        if ($this->option('dry-run')) {
            $this->warn('--dry-run: niente e\' stato scritto.');

            return self::SUCCESS;
        }

        if (! $this->confirm('Procedo?', false)) {
            $this->line('Annullato: nessuna modifica.');

            return self::SUCCESS;
        }

        RegistroSync::avvio('riabbina-importati', ['tenant' => $tenant->slug, 'dal' => $dal->toDateTimeString(), 'schede' => $schede->count()]);
        $fatto = RiabbinaImportati::esegui($schede);
        RegistroSync::esito('riabbina-importati', [
            'uniti' => count($fatto['uniti']), 'ambigui' => count($fatto['ambigui']),
            'nuovi' => $fatto['nuovi'], 'rinumerati' => count($fatto['rinumerati']),
        ]);

        $this->info(sprintf('Fatto: %d uniti, %d da decidere, %d nuovi, %d rinumerati.',
            count($fatto['uniti']), count($fatto['ambigui']), $fatto['nuovi'], count($fatto['rinumerati'])));

        return self::SUCCESS;
    }

    private function mostra(int $totale, array $piano): void
    {
        $this->line("Schede importate: {$totale}");

        if ($piano['uniti'] !== []) {
            $this->newLine();
            $this->info('Stesso intervento di un rapportino del CRM: si uniscono');
            $this->table(['Scheda Eureka', 'Rapportino CRM', 'Perche\''], array_map(fn ($u) => [$u['scheda'], $u['rapportino'], $u['motivo']], $piano['uniti']));
        }

        if ($piano['ambigui'] !== []) {
            $this->newLine();
            $this->warn('Ambigui: piu\' candidati a pari merito, li decide una persona dal confronto');
            $this->table(['Scheda Eureka', 'Rapportini CRM candidati'], array_map(fn ($a) => [$a['scheda'], $a['candidati']], $piano['ambigui']));
        }

        $this->newLine();
        $this->line("Interventi che nel CRM non c'erano: {$piano['nuovi']} (restano)");
        $this->line('Numeri: '.$piano['rinumerazione']);

        if ($piano['rinumerati'] !== []) {
            $this->table(['Numero ora', 'Diventa'], collect($piano['rinumerati'])->map(fn ($n, $v) => [$v, $n])->values()->all());
        }
    }
}
