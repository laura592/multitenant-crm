<?php

namespace App\Console\Commands;

use App\Models\MachineUnit;
use App\Models\MaintenanceSchedule;
use App\Models\Material;
use App\Models\ServiceReport;
use App\Models\ServiceReportMaterial;
use App\Models\Tenant;
use App\Support\DisplayName;
use App\Support\Rapportini\LavaggioFields;
use App\Support\TariffeIntervento;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Mette un rapportino sull'impianto giusto con le sue vie, e rifa' le righe
 * del lavaggio (28/09/2026).
 *
 * Serve quando un lavaggio di due impianti e' finito tutto su un rapportino
 * solo. Le Soleil, 25/09: il tecnico aveva fatto due rapportini — RT-2026-0859
 * "Chiusura stagionale in chiosco" e RT-2026-0860 — ma le righe e le vie erano
 * tutte sul secondo, e il primo era vuoto e agganciato all'impianto acqua.
 *
 * Dal pannello si fa, ma sono sei campi da toccare in due schermate diverse e
 * con 23 macchine ancora da dividere il caso si ripresenta. Qui si scrive una
 * riga sola.
 *
 * Le righe si ricalcolano con la regola vera: un LAVAGGIO 2 VIE per ogni
 * impianto, le vie ulteriori solo oltre la seconda DELLO STESSO impianto. I
 * codici sono quelli del pagante (LAV2MART per chi fattura a Martellozzo).
 */
class AssegnaImpiantiRapportino extends Command
{
    protected $signature = 'rapportini:assegna-impianti
                            {rapportino : numero (RT-2026-0859) o id}
                            {--impianto= : matricola dell\'impianto su cui sta il rapportino}
                            {--vie= : vie per bevanda, es. birra:1,vino:1}
                            {--tipo= : tipo intervento (es. sanificazione), se va cambiato}
                            {--testo= : nuovo "lavoro svolto", se va riscritto}
                            {--tenant=alex : slug del tenant}
                            {--dry-run : mostra cosa cambierebbe senza scrivere}';

    protected $description = 'Assegna un rapportino a un impianto con le sue vie e rifa\' le righe del lavaggio';

    public function handle(): int
    {
        $tenant = Tenant::query()->where('slug', $this->option('tenant'))->firstOrFail();
        $chiave = (string) $this->argument('rapportino');

        $rapportino = ServiceReport::query()
            ->withoutGlobalScopes()
            ->where('tenant_id', $tenant->id)
            ->where(fn ($q) => $q->where('number', $chiave)->orWhere('id', $chiave))
            ->first();

        if (! $rapportino) {
            $this->error("Rapportino {$chiave} non trovato.");

            return self::FAILURE;
        }

        if ($rapportino->isLocked()) {
            $this->error($rapportino->number.' e\' gia\' su Eureka: le righe non si toccano.');

            return self::FAILURE;
        }

        $impianto = MachineUnit::query()
            ->withoutGlobalScopes()
            ->where('tenant_id', $tenant->id)
            ->where('serial_number', (string) $this->option('impianto'))
            ->first();

        if (! $impianto) {
            $this->error('Impianto '.$this->option('impianto').' non trovato.');

            return self::FAILURE;
        }

        if ($impianto->current_customer_id !== $rapportino->customer_id) {
            $this->error('L\'impianto non e\' di '.DisplayName::titleCase($rapportino->customer?->company_name).'.');

            return self::FAILURE;
        }

        $vie = self::vie((string) $this->option('vie'));

        if ($vie === []) {
            $this->error('Serve --vie, es. --vie=birra:1,vino:1');

            return self::FAILURE;
        }

        $piani = MaintenanceSchedule::query()
            ->withoutGlobalScopes()
            ->where('machine_unit_id', $impianto->id)
            ->where('type', MaintenanceSchedule::TYPE_LAVAGGIO)
            ->get()
            ->keyBy('beverage_type');

        $mancanti = array_diff(array_keys($vie), $piani->keys()->all());

        if ($mancanti !== []) {
            $this->error('Su '.$impianto->serial_number.' non c\'e\' nessun piano per: '.implode(', ', $mancanti));

            return self::FAILURE;
        }

        // La regola: un lavaggio base per impianto, le vie ulteriori solo
        // oltre la seconda dello stesso impianto. Qui l'impianto e' uno.
        $totale = array_sum($vie);
        $ulteriori = max(0, $totale - 2);

        // per() vuole il CLIENTE e da li' risale al pagante. Il pagante vero
        // di un rapportino pero' puo' venire dalla macchina o essere stato
        // congelato alla chiusura, quindi glielo si mette in mano gia'
        // risolto: setRelation non scrive niente, cambia solo cosa legge.
        $cliente = $rapportino->customer;

        if ($cliente && ($pagante = $rapportino->invoiceRecipient())) {
            $cliente->setRelation('billingCustomer', $pagante);
        }

        $tariffe = TariffeIntervento::per($cliente);
        $codBase = $tariffe['lavaggio'] ?? 'LAV2';
        $codUlt = $tariffe['lavaggio_ulteriore_via'] ?? 'ULTVIA';

        $base = Material::query()->withoutGlobalScopes()->where('code', $codBase)->first();
        $ult = Material::query()->withoutGlobalScopes()->where('code', $codUlt)->first();

        if (! $base) {
            $this->error("Il codice {$codBase} non e' a catalogo.");

            return self::FAILURE;
        }

        $this->line('  Rapportino: '.$rapportino->number.' del '.$rapportino->intervention_date?->format('d/m/Y'));
        $this->line('  Cliente:    '.DisplayName::titleCase($rapportino->customer?->company_name));
        $this->line('  Pagante:    '.(DisplayName::titleCase($rapportino->invoiceRecipient()?->company_name) ?? '—'));
        $this->newLine();

        $righeAttuali = $rapportino->materialsUsed
            ->map(fn (ServiceReportMaterial $m) => $m->material?->code.' ×'.rtrim(rtrim((string) $m->quantity, '0'), '.'))
            ->implode('  ');

        $nuove = collect([$codBase.' ×1'])
            ->when($ulteriori > 0, fn ($c) => $c->push($codUlt.' ×'.$ulteriori))
            ->implode('  ');

        $this->table(['', 'Adesso', 'Dopo'], [
            ['Impianto', $rapportino->machineUnit?->serial_number ?? '—', $impianto->serial_number],
            ['Tipo', $rapportino->intervention_type, (string) ($this->option('tipo') ?: $rapportino->intervention_type)],
            ['Vie', $rapportino->lavaggi()->get()->map(fn ($l) => ($l->maintenanceSchedule?->beverage_type ?? '?').' '.($l->lines_washed ?? '—'))->implode(', ') ?: '—',
                collect($vie)->map(fn ($v, $b) => "$b $v")->implode(', ')],
            ['Righe lavaggio', $righeAttuali ?: 'nessuna', $nuove],
        ]);

        if ($ulteriori === 0 && $totale > 2) {
            $this->warn('  Attenzione: '.$totale.' vie su un impianto solo dovrebbero produrre delle vie ulteriori.');
        }

        if ($this->option('dry-run')) {
            $this->warn('--dry-run: niente e\' stato scritto.');

            return self::SUCCESS;
        }

        if (! $this->confirm('Applico a '.$rapportino->number.'?', false)) {
            $this->line('Annullato: nessuna modifica.');

            return self::SUCCESS;
        }

        DB::transaction(function () use ($rapportino, $impianto, $vie, $piani, $base, $ult, $ulteriori) {
            $campi = [
                'machine_unit_id' => $impianto->id,
                'machine_product_id' => $impianto->product_id,
                'machine_material_id' => $impianto->material_id,
                'machine_serial_number' => $impianto->serial_number,
            ];

            if ($tipo = $this->option('tipo')) {
                $campi['intervention_type'] = $tipo;
            }

            if ($testo = $this->option('testo')) {
                $campi['work_performed'] = $testo;
            }

            $rapportino->forceFill($campi)->saveQuietly();

            // Via solo le righe del lavaggio: ricambi, chiamata e manodopera
            // restano, non e' questo comando a doverli decidere.
            foreach ($rapportino->materialsUsed as $riga) {
                $codice = (string) $riga->material?->code;

                if (str_starts_with($codice, 'LAV') || str_starts_with($codice, 'ULTVIA')) {
                    $riga->forceDelete();
                }
            }

            ServiceReportMaterial::create([
                'service_report_id' => $rapportino->id,
                'material_id' => $base->id,
                'quantity' => 1,
                'unit_cost_snapshot' => $base->list_price,
                'line_total_snapshot' => $base->list_price,
            ]);

            if ($ult && $ulteriori > 0) {
                ServiceReportMaterial::create([
                    'service_report_id' => $rapportino->id,
                    'material_id' => $ult->id,
                    'quantity' => $ulteriori,
                    'unit_cost_snapshot' => $ult->list_price,
                    'line_total_snapshot' => $ult->list_price * $ulteriori,
                ]);
            }

            // Gli impianti e le vie: da qui nascono le righe Lavaggio, e con
            // loro le scadenze dei piani e la pausa stagionale.
            LavaggioFields::syncLavaggioImpianti(
                $rapportino->refresh(),
                collect($vie)->map(fn (int $v, string $bevanda) => [
                    'maintenance_schedule_id' => $piani[$bevanda]->id,
                    'lines_washed' => $v,
                ])->values()->all(),
            );
        });

        $rapportino->refresh();

        $this->info('Fatto. '.$rapportino->number.' e\' su '.$impianto->serial_number.'.');
        $this->line('  righe:   '.$rapportino->materialsUsed->map(fn ($m) => $m->material?->code.' ×'.rtrim(rtrim((string) $m->quantity, '0'), '.'))->implode('  '));
        $this->line('  lavaggi: '.$rapportino->lavaggi()->get()->map(fn ($l) => ($l->maintenanceSchedule?->beverage_type ?? '?').' '.($l->lines_washed ?? '—').'v')->implode(', '));

        return self::SUCCESS;
    }

    /** @return array<string, int> */
    private static function vie(string $spec): array
    {
        $out = [];

        foreach (array_filter(explode(',', $spec)) as $pezzo) {
            [$bevanda, $n] = array_pad(explode(':', trim($pezzo), 2), 2, null);

            if ($bevanda && is_numeric($n)) {
                $out[mb_strtolower(trim($bevanda))] = (int) $n;
            }
        }

        return $out;
    }
}
