<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

/**
 * Sposta le firme dei rapportini dal disco pubblico a quello privato.
 *
 * Fino al 23/09/2026 SignaturePad scriveva su "public", cioe' dentro
 * storage/app/public, che /storage pubblica senza chiedere niente a nessuno:
 * la firma autografa di un cliente si apriva conoscendo l'indirizzo. Da ora
 * si scrive su "local" e si serve dalla rotta service-reports.firma, che
 * chiede il permesso (vedi App\Support\Rapportini\FirmaCliente).
 *
 * Le firme raccolte prima stanno pero' ancora sul pubblico. Il codice le
 * legge da entrambi i dischi, quindi niente si rompe: ma finche' non girano
 * di qua restano raggiungibili da fuori. Questo comando le copia sul
 * privato, verifica di averle copiate davvero e solo allora toglie
 * l'originale.
 *
 * Di default non tocca niente e mostra cosa farebbe: si scrive solo con
 * --applica.
 */
class PortaFirmeAlPrivato extends Command
{
    protected $signature = 'firme:porta-al-privato
        {--applica : Sposta per davvero (senza questo mostra soltanto cosa farebbe)}
        {--cartella=signatures : La cartella da spostare sul disco pubblico}';

    protected $description = 'Sposta le firme dei rapportini dal disco pubblico a quello privato';

    public function handle(): int
    {
        $cartella = trim((string) $this->option('cartella'), '/');
        $pubblico = Storage::disk('public');
        $privato = Storage::disk('local');

        $file = collect($pubblico->allFiles($cartella))
            // .gitignore e simili non sono firme.
            ->reject(fn (string $percorso) => str_starts_with(basename($percorso), '.'))
            ->values();

        if ($file->isEmpty()) {
            $this->info("Niente da spostare: sul disco pubblico non c'e' nessun file in \"{$cartella}/\".");

            return self::SUCCESS;
        }

        $peso = collect($file)->sum(fn (string $percorso) => $pubblico->size($percorso));

        $this->line('');
        $this->line("File da spostare da public/{$cartella} a local/{$cartella}: <options=bold>{$file->count()}</> (".$this->leggibile($peso).')');
        $this->line('Da qui in poi si apriranno solo da /service-reports/{id}/firma, con il permesso.');
        $this->line('');

        foreach ($file->take(5) as $percorso) {
            $this->line("  {$percorso}");
        }

        if ($file->count() > 5) {
            $this->line('  ... e altri '.($file->count() - 5));
        }

        $this->line('');

        if (! $this->option('applica')) {
            $this->warn('Prova a vuoto: non ho spostato niente. Rilancia con --applica per farlo davvero.');

            return self::SUCCESS;
        }

        if (! $this->confirm("Sposto {$file->count()} firme sul disco privato e le tolgo dal pubblico?", false)) {
            $this->info('Lasciato tutto com\'era.');

            return self::SUCCESS;
        }

        $spostati = 0;
        $saltati = 0;

        $barra = $this->output->createProgressBar($file->count());
        $barra->start();

        foreach ($file as $percorso) {
            $contenuto = $pubblico->get($percorso);

            if ($contenuto === null) {
                $saltati++;
                $barra->advance();

                continue;
            }

            // Se sul privato c'e' gia' un file identico, e' un giro
            // ripetuto: niente da riscrivere, ma l'originale va tolto.
            $giaLi = $privato->exists($percorso) && $privato->get($percorso) === $contenuto;

            if (! $giaLi) {
                $privato->put($percorso, $contenuto);
            }

            // Si cancella solo dopo aver verificato che la copia c'e' e ha
            // gli stessi byte: una firma persa non si rifa'.
            if ($privato->exists($percorso) && $privato->get($percorso) === $contenuto) {
                $pubblico->delete($percorso);
                $spostati++;
            } else {
                $saltati++;
            }

            $barra->advance();
        }

        $barra->finish();
        $this->line('');
        $this->line('');
        $this->info("Spostate: {$spostati}");

        if ($saltati > 0) {
            $this->warn("Non spostate (copia non verificata, originale lasciato dov'era): {$saltati}");
        }

        return $saltati > 0 ? self::FAILURE : self::SUCCESS;
    }

    private function leggibile(int $byte): string
    {
        return $byte > 1048576
            ? round($byte / 1048576, 1).' MB'
            : round($byte / 1024).' KB';
    }
}
