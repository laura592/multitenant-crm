<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Dataset comune-CAP-provincia italiano (fonte: ISTAT via matteocontrini/comuni-json),
 * appiattito una riga per coppia comune-CAP. Usato per l'autocomplete indirizzo
 * in CustomerResource (vedi database/data/comuni-cap.json). Idempotente:
 * svuota e ricarica ad ogni run, e' un dataset statico senza riferimenti FK.
 */
class MunicipalityPostalCodeSeeder extends Seeder
{
    public function run(): void
    {
        $path = database_path('data/comuni-cap.json');
        $rows = json_decode(file_get_contents($path), true);

        DB::table('municipality_postal_codes')->truncate();

        foreach (array_chunk($rows, 500) as $chunk) {
            DB::table('municipality_postal_codes')->insert(array_map(fn ($row) => [
                'municipality_name' => $row['n'],
                'province_name' => $row['p'],
                'province_code' => $row['s'],
                'postal_code' => $row['c'],
            ], $chunk));
        }

        $this->command->info(count($rows).' comuni/CAP caricati.');
    }
}
