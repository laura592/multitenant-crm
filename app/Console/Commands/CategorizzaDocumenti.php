<?php

namespace App\Console\Commands;

use App\Models\PriceList;
use App\Support\Assistenza\ContrattoAssistenza;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;

/**
 * Sistema i documenti caricati quando la sezione era ancora "Listini"
 * (21/09/2026):
 *
 * - la categoria: la migrazione li ha fatti tutti "Listino", ma in
 *   produzione ce n'erano anche di altro tipo (il manuale del CRM, la
 *   scheda cliente, due cataloghi senza prezzi). Si riconoscono dall'id:
 *   sono righe di produzione viste una per una, non una regola, e un id che
 *   non c'e' si salta;
 * - il file: stavano tutti in price-lists/, e quelli caricati dal pannello
 *   col codice a caso di Filament (01KYJ1NWCPG7YP26XKG0FY2DT3.pdf). Vanno
 *   nella cartella della categoria, col nome del documento, come i
 *   caricamenti nuovi (PriceList::sistemaFile()).
 *
 * I contratti di assistenza non li carica: li carica l'ufficio da
 * Documenti. Alla fine pero' dice se ne manca uno, perche' senza il
 * pulsante "Contratto" dei preventivi resta spento.
 */
class CategorizzaDocumenti extends Command
{
    /** @var array<string, string> id => categoria */
    public const DOCUMENTI = [
        '01a032ee-e744-71af-a584-6a947c6d394c' => PriceList::ALTRO, // Manuale Alex CRM
        '01a06153-2dcf-70ca-bd0f-5017c15e67d6' => PriceList::ALTRO, // Scheda Cliente
        '019f8d92-1ca7-732f-a373-ce27927d4b20' => PriceList::CATALOGO, // John Guest - Raccordi ad innesto rapido
        '019f8d9e-bd3e-7323-95af-aadd58ab4ea1' => PriceList::CATALOGO, // Global Fountain - Catalogo Generale
    ];

    protected $signature = 'documenti:categorizza
                            {--dry-run : mostra cosa cambierebbe, senza scrivere}';

    protected $description = 'Mette in categoria i documenti caricati come listini e ne ordina i file in cartelle';

    public function handle(): int
    {
        $documenti = PriceList::query()->withoutGlobalScopes()->orderBy('created_at')->get();

        // Prima la categoria, in memoria: e' lei a dire in che cartella va il file.
        $categorie = $documenti
            ->filter(fn (PriceList $d) => isset(self::DOCUMENTI[$d->id]) && $d->category !== self::DOCUMENTI[$d->id])
            ->mapWithKeys(fn (PriceList $d) => [$d->id => $d->category]);

        foreach ($documenti as $d) {
            $d->category = self::DOCUMENTI[$d->id] ?? $d->category;
        }

        /** @var Collection<int, PriceList> $file */
        $file = $documenti->filter(fn (PriceList $d) => $d->percorsoGiusto() !== null)->values();

        if ($categorie->isEmpty() && $file->isEmpty()) {
            $this->info('Niente da fare: i documenti sono gia\' in categoria e i file al loro posto.');
            $this->contrattiMancanti();

            return self::SUCCESS;
        }

        if ($categorie->isNotEmpty()) {
            $this->table(['Documento', 'Categoria prima', 'Categoria dopo'], $documenti
                ->filter(fn (PriceList $d) => $categorie->has($d->id))
                ->map(fn (PriceList $d) => [$d->name, PriceList::CATEGORIE[$categorie[$d->id]] ?? $categorie[$d->id], PriceList::CATEGORIE[$d->category]])
                ->all());
        }

        if ($file->isNotEmpty()) {
            // Due documenti con lo stesso nome qui risultano uguali: al
            // momento di scrivere il secondo prende -2.
            $this->table(['Documento', 'File prima', 'File dopo'], $file
                ->map(fn (PriceList $d) => [$d->name, $d->file_path, $d->percorsoGiusto()])
                ->all());
        }

        if ($this->option('dry-run')) {
            $this->warn('--dry-run: niente e\' stato scritto.');
            $this->contrattiMancanti();

            return self::SUCCESS;
        }

        if (! $this->confirm("Sposto {$categorie->count()} documenti di categoria e {$file->count()} file?", false)) {
            $this->line('Annullato: nessuna modifica.');

            return self::SUCCESS;
        }

        // Quietly: il saving sposterebbe il file lo stesso, ma il saved
        // ricomprimerebbe di nuovo i listini gia' ricompressi.
        foreach ($documenti as $d) {
            $spostato = $d->sistemaFile();

            if ($spostato || $categorie->has($d->id)) {
                $d->saveQuietly();
            }
        }

        $this->info("Fatto: {$categorie->count()} documenti in categoria, {$file->count()} file spostati.");
        $this->contrattiMancanti();

        return self::SUCCESS;
    }

    private function contrattiMancanti(): void
    {
        foreach (PriceList::CONTRATTI as $tipo => $categoria) {
            if (! PriceList::query()->withoutGlobalScopes()->where('category', $categoria)->exists()) {
                $this->warn('In Documenti manca il contratto '.ContrattoAssistenza::nome($tipo).': finche\' non lo carichi, dai preventivi non si scarica.');
            }
        }
    }
}
