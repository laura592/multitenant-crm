<?php

namespace Database\Seeders;

use App\Models\PriceList;
use App\Models\Tenant;
use App\Support\Assistenza\ContrattoAssistenza;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Storage;

/**
 * Carica in Documenti i due modelli dei contratti di assistenza
 * (Full-Service ed Easy-Service, con il Foro di Venezia) come se li avesse
 * caricati l'ufficio dal pannello. Serve una volta, al passaggio del
 * 21/09/2026 dai PDF nel codice a Documenti:
 *
 *   php artisan db:seed --class=ContrattiAssistenzaSeeder --force
 *
 * Non sovrascrive niente: un contratto gia' presente in Documenti resta
 * com'e' e quello del seeder non viene caricato. Le revisioni successive
 * le carica l'ufficio da Magazzino -> Documenti.
 */
class ContrattiAssistenzaSeeder extends Seeder
{
    public function run(): void
    {
        $tenantId = Tenant::query()->where('slug', 'alex')->value('id');

        foreach (PriceList::CONTRATTI as $tipo => $categoria) {
            $nome = 'Contratto '.ContrattoAssistenza::nome($tipo);

            if (PriceList::query()->withoutGlobalScopes()->where('category', $categoria)->exists()) {
                $this->command?->line("{$nome}: gia' in Documenti, lasciato com'e'.");

                continue;
            }

            $sorgente = __DIR__."/contratti/{$tipo}-service.pdf";
            $cartella = PriceList::cartella($categoria);
            $percorso = $cartella.'/'.PriceList::nomeFileLibero($nome, $cartella);

            Storage::disk('public')->put($percorso, file_get_contents($sorgente));

            PriceList::create([
                'tenant_id' => $tenantId,
                'category' => $categoria,
                'name' => $nome,
                'file_path' => $percorso,
            ]);

            $this->command?->info("{$nome}: caricato in Documenti ({$percorso}).");
        }
    }
}
