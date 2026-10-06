<?php

namespace App\Console\Commands;

use App\Models\MachineUnit;
use App\Models\ServiceReport;
use App\Models\Tenant;
use App\Support\DisplayName;
use App\Support\OutsideLivewireRender;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Il foglio dei ritiri da confermare, da stampare e girare con la penna.
 *
 * Nel CRM nessuna macchina risulta in magazzino: il flusso non e' mai stato
 * usato e al 06/10/2026 c'erano quattro anni di ritiri in attesa. Smaltirli
 * richiede piu' di una passata, quindi la lista si rifa' con un comando
 * invece di essere un documento generato una volta sola.
 */
class MacchineDaVerificare extends Command
{
    protected $signature = 'macchine:da-verificare {--out= : dove scrivere il PDF}';

    protected $description = 'Il PDF delle macchine date per ritirate e ancora assegnate a un cliente';

    public function handle(): int
    {
        $macchine = MachineUnit::withoutGlobalScopes()
            ->whereNotNull('spostamento_suggerito_motivo')
            ->whereNull('spostamento_suggerito_customer_id')
            ->with('currentCustomer')
            ->orderByDesc('spostamento_suggerito_il')
            ->get();

        if ($macchine->isEmpty()) {
            $this->info('Nessuna macchina da verificare.');

            return self::SUCCESS;
        }

        // Il rapportino che registra il ritiro: e' la prova da mostrare a chi
        // controlla, senza doverla ricercare a mano.
        $ritiri = DB::table('service_report_materials as l')
            ->join('service_reports as r', 'r.id', '=', 'l.service_report_id')
            ->join('materials as m', 'm.id', '=', 'l.material_id')
            ->whereNull('l.deleted_at')->whereNull('r.deleted_at')
            ->whereIn('r.machine_unit_id', $macchine->pluck('id'))
            ->where(fn ($q) => $q->where('m.code', 'like', 'DISIN%')->orWhere('m.code', 'like', '%RITIR%'))
            ->get(['r.machine_unit_id', 'r.customer_id', 'r.number', 'r.intervention_date'])
            ->groupBy('machine_unit_id');

        $righe = $macchine->map(function (MachineUnit $m) use ($ritiri) {
            $suoi = collect($ritiri[$m->id] ?? [])->sortByDesc('intervention_date');
            $ritiro = $suoi->first(fn ($r) => $r->customer_id === $m->current_customer_id) ?: $suoi->first();

            $ultimo = ServiceReport::withoutGlobalScopes()->with('customer')
                ->where('machine_unit_id', $m->id)->latest('intervention_date')->first();

            return [
                'anno' => (int) $m->spostamento_suggerito_il?->format('Y'),
                'matricola' => $m->serial_number,
                'modello' => $m->model_name,
                'cliente' => DisplayName::titleCase($m->currentCustomer?->company_name),
                'citta' => $m->currentCustomer?->city,
                'ritirata' => $m->spostamento_suggerito_il?->format('d/m/Y'),
                'rapportino' => $ritiro->number ?? null,
                'ultimo' => $ultimo?->intervention_date?->format('d/m/Y'),
                'ultimo_presso' => DisplayName::titleCase($ultimo?->customer?->company_name),
            ];
        });

        $anno = (int) now()->format('Y');

        $gruppi = [
            "Ritiri del {$anno} — da controllare" => $righe->where('anno', '>=', $anno)->values()->all(),
            'Arretrato degli anni precedenti' => $righe->where('anno', '<', $anno)->values()->all(),
        ];

        $tenant = Tenant::withoutGlobalScopes()->find($macchine->first()->tenant_id);

        $pdf = OutsideLivewireRender::run(fn () => Pdf::loadView('pdf.macchine-da-verificare', [
            'gruppi' => $gruppi,
            'totale' => $righe->count(),
            'oggi' => Carbon::now(),
            'tenant' => $tenant,
        ])->setPaper('a4', 'landscape'));

        $out = $this->option('out') ?: storage_path('app/macchine-da-verificare.pdf');
        file_put_contents($out, $pdf->output());

        $this->info("Scritto {$out} — {$righe->count()} macchine.");
        foreach ($gruppi as $titolo => $elenco) {
            $this->line('  '.$titolo.': '.count($elenco));
        }

        return self::SUCCESS;
    }
}
