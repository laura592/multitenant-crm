<?php

namespace App\Console\Commands;

use App\Models\Lavaggio;
use Illuminate\Console\Command;

/**
 * Importa i lavaggi da LAVAGGI.ods (foglio di lavoro tenuto a mano dal
 * tecnico) per i soli clienti gia' riconosciuti con certezza nel CRM.
 *
 * Il file JSON di input e' un array di {customer_id, data (Y-m-d),
 * descrizione, note}, prodotto da uno script di estrazione + matching
 * eseguito a parte (non versionato, dati di origine esterni al repo).
 * I casi ambigui o senza corrispondenza restano fuori: vanno risolti a
 * mano e importati separatamente.
 */
class ImportLavaggiFromSpreadsheet extends Command
{
    protected $signature = 'lavaggi:import-from-spreadsheet {path} {--dry-run}';

    protected $description = 'Importa i lavaggi (data + descrizione) per i clienti gia\' riconosciuti con certezza da un export JSON';

    public function handle(): int
    {
        $path = $this->argument('path');

        if (! is_file($path)) {
            $this->error("File non trovato: {$path}");

            return self::FAILURE;
        }

        $rows = json_decode(file_get_contents($path), true);
        $dryRun = (bool) $this->option('dry-run');

        $created = 0;

        foreach ($rows as $row) {
            $customer = \App\Models\Customer::find($row['customer_id']);

            if (! $customer) {
                $this->warn("Cliente non trovato: {$row['customer_id']}");

                continue;
            }

            if (! $dryRun) {
                Lavaggio::create([
                    'tenant_id' => $customer->tenant_id,
                    'customer_id' => $customer->id,
                    'data' => $row['data'],
                    'descrizione' => $row['descrizione'],
                    'note' => $row['note'] ?? null,
                ]);
            }

            $created++;
        }

        $this->info(($dryRun ? '[DRY RUN] ' : '').'Lavaggi creati: '.$created);

        return self::SUCCESS;
    }
}
