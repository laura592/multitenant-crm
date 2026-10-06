<?php

namespace App\Console\Commands;

use App\Models\MachineUnit;
use App\Models\ServiceReport;
use App\Models\Tenant;
use App\Support\DisplayName;
use App\Support\Gestionale\RientriMagazzino;
use App\Support\OutsideLivewireRender;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Il foglio dei ritiri da confermare, da stampare e girare con la penna.
 *
 * Nel CRM nessuna macchina risulta in magazzino: il flusso dei rientri non e'
 * mai stato usato e al 06/10/2026 c'erano quattro anni di ritiri in attesa.
 * Smaltirli richiede piu' di una passata, quindi la lista si rifa' con un
 * comando invece di essere un documento generato una volta sola.
 *
 * Da riga di comando le query vanno scritte per esteso: senza utente e senza
 * pannello lo scope del tenant non filtra niente (BelongsToTenant esce
 * subito) e withoutGlobalScopes() toglie anche il cestino, quindi tenant e
 * deleted_at si dichiarano a mano come fa gestionale:riepilogo.
 */
class MacchineDaVerificare extends Command
{
    protected $signature = 'macchine:da-verificare
        {--tenant= : Slug tenant (default: tenant master)}
        {--out= : dove scrivere il PDF}';

    protected $description = 'Il PDF delle macchine date per ritirate e ancora assegnate a un cliente';

    public function handle(): int
    {
        $tenant = $this->option('tenant')
            ? Tenant::where('slug', $this->option('tenant'))->firstOrFail()
            : Tenant::where('is_master', true)->firstOrFail();

        $macchine = MachineUnit::withoutGlobalScopes()
            ->whereNull('deleted_at')
            ->where('tenant_id', $tenant->id)
            // Senza cliente non c'e' niente da verificare: o e' gia' in
            // magazzino, o la proposta e' un residuo che il prossimo sync
            // ripulisce da solo.
            ->whereNotNull('current_customer_id')
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
            ->where('r.tenant_id', $tenant->id)
            ->whereIn('r.machine_unit_id', $macchine->pluck('id'))
            ->tap(fn ($q) => RientriMagazzino::filtroCodici($q))
            ->get(['r.machine_unit_id', 'r.customer_id', 'r.number', 'r.intervention_date'])
            ->groupBy('machine_unit_id');

        // Una query sola per tutti: una per macchina erano centotrenta giri
        // sul database per un foglio di otto pagine.
        $interventi = ServiceReport::withoutGlobalScopes()
            ->whereNull('deleted_at')
            ->where('tenant_id', $tenant->id)
            ->whereIn('machine_unit_id', $macchine->pluck('id'))
            ->whereNotNull('intervention_date')
            ->with('customer:id,company_name')
            ->get(['id', 'machine_unit_id', 'customer_id', 'intervention_date'])
            ->groupBy('machine_unit_id');

        $righe = $macchine->map(function (MachineUnit $m) use ($ritiri, $interventi) {
            $suoi = collect($ritiri[$m->id] ?? [])->sortByDesc('intervention_date');
            $ritiro = $suoi->first(fn ($r) => $r->customer_id === $m->current_customer_id) ?: $suoi->first();

            $ultimo = $interventi->get($m->id, collect())->sortByDesc('intervention_date')->first();

            return [
                // Senza data va in fondo, nell'arretrato, e la cella lo dice.
                'anno' => $m->spostamento_suggerito_il ? (int) $m->spostamento_suggerito_il->format('Y') : 0,
                'matricola' => $m->serial_number,
                'modello' => $m->model_name,
                'cliente' => DisplayName::titleCase($m->currentCustomer?->company_name),
                'citta' => $m->currentCustomer?->city,
                'ritirata' => $m->spostamento_suggerito_il?->format('d/m/Y') ?: '—',
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

        $pdf = OutsideLivewireRender::run(fn () => Pdf::loadView('pdf.macchine-da-verificare', [
            'gruppi' => $gruppi,
            'totale' => $righe->count(),
            'oggi' => Carbon::now(),
            'tenant' => $tenant,
        ])->setPaper('a4', 'landscape'));

        $out = $this->option('out') ?: storage_path('app/macchine-da-verificare.pdf');

        // Un percorso sbagliato non deve finire con "Scritto" e uscita zero:
        // chi lancia il comando cercherebbe un file che non c'e'.
        if (@file_put_contents($out, $pdf->output()) === false) {
            $this->error("Non sono riuscito a scrivere {$out}.");

            return self::FAILURE;
        }

        $this->info("Scritto {$out} — {$righe->count()} macchine.");
        foreach ($gruppi as $titolo => $elenco) {
            $this->line('  '.$titolo.': '.count($elenco));
        }

        return self::SUCCESS;
    }
}
