<?php

namespace App\Console\Commands;

use App\Models\Lavaggio;
use App\Models\Tenant;
use App\Support\DisplayName;
use Illuminate\Console\Command;

/**
 * Rimette il numero di vie sui lavaggi che ce l'hanno perso per strada
 * (24/09/2026).
 *
 * Su 989 lavaggi, 293 non hanno il numero di vie — ed è il campo su cui si
 * fattura. Due sorgenti possibili, nell'ordine:
 *
 * 1. la descrizione, dove il tecnico l'ha scritto a mano: "1 Via",
 *    "6 Vie + Chiusura", "4 Vie + Acqua";
 * 2. le vie del piano collegato (`lines_count`), che dice quante ne ha
 *    quell'impianto per quella bevanda.
 *
 * Non inventa: se nessuna delle due dice niente, il lavaggio resta com'è e
 * finisce nell'elenco di quelli da guardare a mano.
 */
class VieMancantiNeiLavaggi extends Command
{
    protected $signature = 'lavaggi:vie-mancanti
                            {--tenant=alex : slug del tenant}
                            {--dal= : solo i lavaggi da questa data (YYYY-MM-DD)}
                            {--solo-descrizione : non usare le vie del piano, solo quelle scritte a mano}
                            {--dry-run : mostra cosa cambierebbe senza scrivere}';

    protected $description = 'Rimette il numero di vie sui lavaggi che non ce l\'hanno, leggendolo dalla descrizione o dal piano';

    public function handle(): int
    {
        $tenant = Tenant::query()->where('slug', $this->option('tenant'))->firstOrFail();

        $lavaggi = Lavaggio::query()
            ->withoutGlobalScopes()
            ->where('tenant_id', $tenant->id)
            ->where(fn ($q) => $q->whereNull('lines_washed')->orWhere('lines_washed', 0))
            ->when($this->option('dal'), fn ($q, $dal) => $q->whereDate('data', '>=', $dal))
            ->with(['customer', 'maintenanceSchedule'])
            ->orderBy('data')
            ->get();

        if ($lavaggi->isEmpty()) {
            $this->info('Tutti i lavaggi hanno il numero di vie.');

            return self::SUCCESS;
        }

        $daScrivere = [];
        $senzaFonte = [];

        foreach ($lavaggi as $lavaggio) {
            $vie = self::vieDallaDescrizione($lavaggio->descrizione);
            $fonte = 'descrizione';

            if (! $vie && ! $this->option('solo-descrizione')) {
                $vie = $lavaggio->maintenanceSchedule?->lines_count ?: null;
                $fonte = 'piano';
            }

            if (! $vie) {
                $senzaFonte[] = $lavaggio;

                continue;
            }

            $daScrivere[] = ['lavaggio' => $lavaggio, 'vie' => $vie, 'fonte' => $fonte];
        }

        if ($daScrivere !== []) {
            $this->table(
                ['Data', 'Cliente', 'Descrizione', 'Vie', 'Da dove'],
                collect($daScrivere)->take(25)->map(fn (array $r) => [
                    $r['lavaggio']->data->format('d/m/Y'),
                    mb_substr(DisplayName::titleCase($r['lavaggio']->customer?->company_name) ?? '—', 0, 30),
                    mb_substr((string) $r['lavaggio']->descrizione, 0, 28) ?: '—',
                    $r['vie'],
                    $r['fonte'],
                ])->all(),
            );

            if (count($daScrivere) > 25) {
                $this->line('  …e altri '.(count($daScrivere) - 25).'.');
            }
        }

        $perFonte = collect($daScrivere)->countBy('fonte');
        $this->info(sprintf(
            'Da sistemare: %d (%d dalla descrizione, %d dal piano). Senza niente da cui ricavarle: %d.',
            count($daScrivere),
            $perFonte['descrizione'] ?? 0,
            $perFonte['piano'] ?? 0,
            count($senzaFonte),
        ));

        if ($this->option('dry-run')) {
            $this->warn('--dry-run: niente e\' stato scritto.');

            return self::SUCCESS;
        }

        if ($daScrivere === [] || ! $this->confirm('Scrivo le vie su '.count($daScrivere).' lavaggi?', false)) {
            $this->line('Annullato: nessuna modifica.');

            return self::SUCCESS;
        }

        foreach ($daScrivere as $r) {
            $r['lavaggio']->update(['lines_washed' => $r['vie']]);
        }

        $this->info('Fatto: '.count($daScrivere).' lavaggi con le vie al loro posto.');
        $this->line('I '.count($senzaFonte).' rimasti si guardano dal rapportino, uno per uno.');

        return self::SUCCESS;
    }

    /**
     * Le vie scritte nella descrizione: "1 Via", "6 Vie + Chiusura",
     * "2 Vie (Vino)". Il numero è sempre in testa, prima della parola.
     */
    public static function vieDallaDescrizione(?string $descrizione): ?int
    {
        if (! $descrizione || preg_match('/^generato da rapportino/i', $descrizione)) {
            return null;
        }

        if (! preg_match('/(\d+)\s*vi[ae]\b/i', $descrizione, $m)) {
            return null;
        }

        $vie = (int) $m[1];

        // Oltre la dozzina non è un impianto, è un errore di battitura.
        return $vie > 0 && $vie <= 12 ? $vie : null;
    }
}
