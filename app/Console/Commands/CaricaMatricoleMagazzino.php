<?php

namespace App\Console\Commands;

use App\Models\MachineUnit;
use App\Models\Product;
use App\Models\Tenant;
use Illuminate\Console\Command;

/**
 * Carica in magazzino le matricole lette dalle etichette di un arrivo.
 *
 * Nasce da un caso concreto (04/09/2026): un bancale di macchine e accessori
 * fotografati uno per uno, da registrare prima che vadano dai clienti. Fino a
 * qui le matricole nascevano solo installate (dall'import Eureka o dal
 * rapportino), e non c'era modo di dire "questa ce l'abbiamo in casa".
 *
 * Il CSV e' la fonte, non le foto: le matricole lette da un'etichetta vanno
 * ricontrollate a mano prima di entrare, e un file si corregge in un foglio
 * di calcolo. Colonne: matricola, modello, product_sku, note. product_sku
 * puo' restare vuoto — il modello a catalogo si collega dopo dal pannello,
 * il pezzo fisico esiste lo stesso.
 *
 * Idempotente: una matricola gia' presente viene saltata, non aggiornata.
 * Cosi' rilanciarlo dopo aver corretto due righe del CSV non tocca quelle
 * gia' entrate.
 */
class CaricaMatricoleMagazzino extends Command
{
    protected $signature = 'macchinari:carica-magazzino
                            {file : percorso del CSV (matricola, modello, product_sku, note)}
                            {--tenant=alex : slug del tenant}
                            {--dry : mostra cosa farebbe senza scrivere}';

    protected $description = 'Carica in magazzino le matricole di un arrivo, da CSV';

    public function handle(): int
    {
        $file = $this->argument('file');

        if (! is_readable($file)) {
            $this->error("File non leggibile: {$file}");

            return self::FAILURE;
        }

        $tenant = Tenant::where('slug', $this->option('tenant'))->first();

        if (! $tenant) {
            $this->error("Tenant non trovato: {$this->option('tenant')}");

            return self::FAILURE;
        }

        $righe = $this->leggiCsv($file);

        if ($righe === []) {
            $this->error('Il CSV non ha righe utili (serve almeno la colonna "matricola").');

            return self::FAILURE;
        }

        $creati = 0;
        $saltati = 0;
        $tabella = [];

        foreach ($righe as $riga) {
            $matricola = trim((string) ($riga['matricola'] ?? ''));

            if ($matricola === '') {
                continue;
            }

            // withTrashed: una matricola cancellata resta unica nel DB, e
            // ricrearla darebbe un doppione invisibile in elenco.
            $esistente = MachineUnit::withTrashed()
                ->where('tenant_id', $tenant->id)
                ->where('serial_number', $matricola)
                ->first();

            if ($esistente) {
                $saltati++;
                $tabella[] = [$matricola, $riga['modello'] ?? '', '—', 'gia\' presente'];

                continue;
            }

            $sku = trim((string) ($riga['product_sku'] ?? ''));
            // Il catalogo macchine e' condiviso fra i tenant (tenant_id NULL,
            // vedi SharedAcrossTenants): cercare solo sul tenant non trova
            // nemmeno un modello.
            $product = $sku !== ''
                ? Product::where('sku', $sku)
                    ->where(fn ($q) => $q->whereNull('tenant_id')->orWhere('tenant_id', $tenant->id))
                    ->first()
                : null;

            if ($sku !== '' && ! $product) {
                // Non e' un errore fatale: la matricola entra lo stesso, ma
                // va detto, altrimenti il collegamento sparisce in silenzio.
                $this->warn("SKU non trovato, matricola caricata senza modello: {$sku} ({$matricola})");
            }

            $tabella[] = [
                $matricola,
                $riga['modello'] ?? '',
                $product?->name ?? '—',
                $this->option('dry') ? 'da creare' : 'creata',
            ];

            if (! $this->option('dry')) {
                MachineUnit::create([
                    'tenant_id' => $tenant->id,
                    'source' => MachineUnit::SOURCE_MANUALE,
                    'serial_number' => $matricola,
                    'model_name' => $riga['modello'] ?: null,
                    'product_id' => $product?->id,
                    'status' => MachineUnit::STATUS_IN_MAGAZZINO,
                    'notes' => $riga['note'] ?: null,
                ]);
            }

            $creati++;
        }

        $this->table(['Matricola', 'Modello (etichetta)', 'Modello a catalogo', 'Esito'], $tabella);

        $this->info($this->option('dry')
            ? "Prova: {$creati} da creare, {$saltati} gia' presenti. Rilancia senza --dry per scrivere."
            : "Fatto: {$creati} create, {$saltati} gia' presenti.");

        return self::SUCCESS;
    }

    /**
     * @return array<int, array<string, string>>
     */
    private function leggiCsv(string $file): array
    {
        $handle = fopen($file, 'r');
        $intestazione = fgetcsv($handle);

        if (! $intestazione) {
            fclose($handle);

            return [];
        }

        $intestazione = array_map(fn ($c) => strtolower(trim((string) $c)), $intestazione);
        $righe = [];

        while (($dati = fgetcsv($handle)) !== false) {
            // Una riga vuota in fondo al file non e' un errore da segnalare.
            if ($dati === [null] || $dati === []) {
                continue;
            }

            $righe[] = array_combine(
                $intestazione,
                array_pad(array_slice($dati, 0, count($intestazione)), count($intestazione), '')
            );
        }

        fclose($handle);

        return $righe;
    }
}
