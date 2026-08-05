<?php

namespace App\Console\Commands;

use App\Models\Customer;
use App\Models\MachineUnit;
use App\Models\Tenant;
use App\Support\Gestionale\EurekaClient;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * Le macchine create da GestionaleSyncRunner::importInstalledMachines() prima
 * di questo comando avevano il posizionamento aperto datato al momento
 * dell'import (oggi), non alla vera data di installazione (data_documento su
 * Eureka) — perche' quella data non veniva sempre risolta correttamente a
 * monte. Questo comando ricontatta art_installati per i clienti gia'
 * collegati e corregge placed_at (ed eventualmente la nota, se vuota) sul
 * posizionamento aperto delle macchine source=eureka, senza toccare altro.
 */
class BackfillEurekaInstallDates extends Command
{
    protected $signature = 'gestionale:backfill-install-dates
        {--dry-run : Mostra cosa verrebbe corretto senza salvare}
        {--tenant= : Limita il backfill a uno specifico slug tenant}';

    protected $description = 'Corregge la data di installazione delle macchine Eureka gia importate, recuperandola di nuovo da art_installati';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');

        $tenants = Tenant::query()->where('is_active', true)->get()
            ->filter->hasGestionaleEurekaCredentials();

        if ($tenantSlug = $this->option('tenant')) {
            $tenants = $tenants->where('slug', $tenantSlug);
        }

        $updated = 0;
        $unchanged = 0;
        $notFoundOnEureka = 0;

        foreach ($tenants as $tenant) {
            $client = new EurekaClient($tenant);

            $machineUnits = MachineUnit::query()
                ->where('tenant_id', $tenant->id)
                ->where('source', MachineUnit::SOURCE_EUREKA)
                ->whereNotNull('serial_number')
                ->with(['placements' => fn ($q) => $q->whereNull('removed_at')])
                ->get();

            if ($machineUnits->isEmpty()) {
                continue;
            }

            $customers = Customer::query()
                ->where('tenant_id', $tenant->id)
                ->whereNotNull('gestionale_code')
                ->get();

            $installedByCustomer = $client->pooledGet(
                '/show/q/art_installati',
                $customers->mapWithKeys(fn (Customer $customer) => [$customer->id => ['q' => (int) $customer->gestionale_code]])->all(),
            );

            // Mappa matricola (minuscolo) => riga Eureka, indipendentemente dal
            // cliente: qui serve solo la data/nota, non il collegamento cliente.
            $rowsBySerial = [];
            foreach ($installedByCustomer as $rows) {
                foreach ($rows as $row) {
                    $serial = mb_strtolower(trim((string) ($row['matricola'] ?? '')));

                    if ($serial !== '') {
                        $rowsBySerial[$serial] = $row;
                    }
                }
            }

            foreach ($machineUnits as $machineUnit) {
                $row = $rowsBySerial[mb_strtolower(trim($machineUnit->serial_number))] ?? null;
                $placement = $machineUnit->placements->first();

                if (! $row || ! $placement) {
                    $notFoundOnEureka++;

                    continue;
                }

                $installedAt = $this->parseEurekaDate($row['data_documento'] ?? null);
                $documentNumber = (int) ($row['numero_doc_t23'] ?? 0);

                $newNotes = filled($placement->notes) ? $placement->notes : collect([
                    'Importata da Eureka',
                    filled($row['articolo'] ?? null) ? "articolo {$row['articolo']}" : null,
                    $documentNumber > 0 ? "bolla n. {$documentNumber}" : null,
                ])->filter()->implode(', ');

                $dateChanged = $installedAt && ! $installedAt->isSameDay($placement->placed_at);
                $notesChanged = $newNotes !== $placement->notes;

                if (! $dateChanged && ! $notesChanged) {
                    $unchanged++;

                    continue;
                }

                $this->line(sprintf(
                    '  %s matricola %s: %s -> %s%s',
                    $dryRun ? 'DA CORREGGERE' : 'CORRETTA',
                    $machineUnit->serial_number,
                    $placement->placed_at?->toDateString(),
                    $installedAt?->toDateString() ?? $placement->placed_at?->toDateString(),
                    $notesChanged ? ' (nota aggiornata)' : '',
                ));

                if (! $dryRun) {
                    $placement->update([
                        'placed_at' => $installedAt ?? $placement->placed_at,
                        'notes' => $newNotes,
                    ]);
                }

                $updated++;
            }
        }

        $this->newLine();
        $this->info(($dryRun ? '[dry-run] ' : '')."Posizionamenti corretti: {$updated}");
        $this->line("Gia' corretti: {$unchanged}");
        $this->line("Non trovati su Eureka (matricola non presente in art_installati): {$notFoundOnEureka}");

        return self::SUCCESS;
    }

    private function parseEurekaDate(mixed $value): ?Carbon
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        try {
            return Carbon::parse($value);
        } catch (\Throwable) {
            return null;
        }
    }
}
