<?php

namespace App\Console\Commands;

use App\Models\Customer;
use Illuminate\Console\Command;

/**
 * Arricchisce telefoni e PEC dei clienti gia' importati dal gestionale,
 * incrociando il "COD" di un export anagrafico (es. Anagrafico-8058.pdf,
 * colonne COD/RAGIONE SOCIALE/INDIRIZZO/LOCALITA'/TELEFONO/PEC) con
 * customers.gestionale_code. Solo aggiunte: non sovrascrive mai un telefono
 * o una PEC gia' presenti, quindi e' sicuro da rilanciare piu' volte.
 *
 * Il file JSON di input e' un array di {cod, phones: string[], emails: string[]}
 * (vedi script di estrazione usato per generarlo dal PDF).
 */
class EnrichCustomerContactsFromGestionale extends Command
{
    protected $signature = 'customers:enrich-contacts-from-gestionale {path} {--dry-run}';

    protected $description = 'Arricchisce telefoni e PEC dei clienti incrociando il codice gestionale con un export anagrafico esterno';

    public function handle(): int
    {
        $path = $this->argument('path');

        if (! is_file($path)) {
            $this->error("File non trovato: {$path}");

            return self::FAILURE;
        }

        $rows = json_decode(file_get_contents($path), true);
        $dryRun = (bool) $this->option('dry-run');

        $phonesAdded = 0;
        $pecSet = 0;
        $notFound = 0;
        $touchedCustomers = 0;

        foreach ($rows as $row) {
            $customer = Customer::where('gestionale_code', (string) $row['cod'])->first();

            if (! $customer) {
                $notFound++;

                continue;
            }

            $dirty = false;

            foreach ($row['phones'] ?? [] as $phone) {
                $before = count($customer->phones);
                $customer->phones = [...$customer->phones, $phone];

                if (count($customer->phones) > $before) {
                    $phonesAdded++;
                    $dirty = true;
                }
            }

            foreach ($row['emails'] ?? [] as $pec) {
                if (blank($customer->pec)) {
                    $customer->pec = $pec;
                    $pecSet++;
                    $dirty = true;
                }
            }

            if ($dirty) {
                $touchedCustomers++;

                if (! $dryRun) {
                    $customer->save();
                }
            }
        }

        $this->info(($dryRun ? '[DRY RUN] ' : '').'Clienti aggiornati: '.$touchedCustomers);
        $this->info('Telefoni aggiunti: '.$phonesAdded);
        $this->info('PEC impostate: '.$pecSet);
        $this->info('Codici gestionale non trovati nel CRM: '.$notFound);

        return self::SUCCESS;
    }
}
