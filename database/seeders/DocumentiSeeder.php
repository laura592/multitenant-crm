<?php

namespace Database\Seeders;

use App\Console\Commands\CategorizzaDocumenti;
use App\Models\PriceList;
use App\Models\Tenant;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Rimette in piedi la sezione Documenti quando le righe e i file sul disco
 * non si trovano piu' (21/09/2026): in produzione i documenti puntavano a
 * price-lists/01KYJ1....pdf, mentre i PDF sono stati ricaricati a mano
 * sul server col nome leggibile e nelle cartelle per categoria
 * (price-lists/listini/liebherr.pdf).
 *
 *   php artisan db:seed --class=DocumentiSeeder --force
 *
 * Per ogni documento:
 * - la categoria giusta (CategorizzaDocumenti::DOCUMENTI);
 * - se il file non c'e' dove dice file_path, lo cerca in tutto
 *   price-lists/ con lo stesso nome di file o col nome del documento;
 * - trovato, lo porta nella cartella della sua categoria.
 * I PDF sul disco che nessun documento usa diventano documenti nuovi,
 * col nome preso dal file. Alla fine carica i contratti di assistenza se
 * mancano (ContrattiAssistenzaSeeder).
 *
 * Non cancella niente, ne' righe ne' file. Prima mostra cosa farebbe e
 * chiede conferma.
 */
class DocumentiSeeder extends Seeder
{
    /** Cartella sul disco -> categoria, per i PDF senza documento. */
    private const CATEGORIA_DA_CARTELLA = [
        'listini' => PriceList::LISTINO,
        'cataloghi' => PriceList::CATALOGO,
        'altro' => PriceList::ALTRO,
        '' => PriceList::LISTINO,
    ];

    public function run(): void
    {
        $disco = Storage::disk('public');
        $documenti = PriceList::query()->withoutGlobalScopes()->orderBy('created_at')->get();
        $sulDisco = collect($disco->allFiles(PriceList::CARTELLA))
            ->filter(fn (string $f) => Str::lower(pathinfo($f, PATHINFO_EXTENSION)) === 'pdf')
            ->values();

        $cambi = [];
        $mancanti = [];

        foreach ($documenti as $d) {
            $d->category = CategorizzaDocumenti::DOCUMENTI[$d->id] ?? $d->category;

            if (filled($d->file_path) && $disco->exists($d->file_path)) {
                continue;
            }

            $trovato = $this->cerca($d, $sulDisco);

            if ($trovato === null) {
                $mancanti[] = [$d->name, $d->file_path ?: '—'];

                continue;
            }

            $cambi[$d->id] = [$d->name, $d->file_path ?: '—', $trovato];
            $d->file_path = $trovato;
        }

        $usati = $documenti->pluck('file_path')->filter()->all();
        $nuovi = $sulDisco
            ->reject(fn (string $f) => in_array($f, $usati, true))
            ->filter(fn (string $f) => array_key_exists($this->sottocartella($f), self::CATEGORIA_DA_CARTELLA))
            ->values();

        $this->mostra($cambi, $nuovi, $mancanti);

        if ($cambi === [] && $nuovi->isEmpty()) {
            $this->command?->info('Nessun file da ricollegare.');
            $this->call(ContrattiAssistenzaSeeder::class);

            return;
        }

        if ($this->command && ! $this->command->confirm('Applico?', false)) {
            $this->command->line('Annullato: nessuna modifica.');

            return;
        }

        foreach ($documenti as $d) {
            $d->sistemaFile();
            $d->saveQuietly();
        }

        $tenantId = Tenant::query()->where('slug', 'alex')->value('id');

        foreach ($nuovi as $file) {
            $nuovo = new PriceList([
                'tenant_id' => $tenantId,
                'category' => self::CATEGORIA_DA_CARTELLA[$this->sottocartella($file)],
                'name' => Str::of(pathinfo($file, PATHINFO_FILENAME))->replace(['-', '_'], ' ')->title()->toString(),
                'file_path' => $file,
            ]);
            $nuovo->sistemaFile();
            $nuovo->saveQuietly();
        }

        $this->command?->info(count($cambi).' documenti ricollegati al loro file, '.$nuovi->count().' documenti nuovi dai PDF sul disco.');

        $this->call(ContrattiAssistenzaSeeder::class);
    }

    /**
     * Il PDF del documento fra quelli sul disco: prima lo stesso nome di
     * file, poi il nome del documento ("Dalla Corte Listino 2026" ->
     * dalla-corte-listino-2026.pdf). Vince quello nella cartella della
     * categoria.
     *
     * @param  \Illuminate\Support\Collection<int, string>  $sulDisco
     */
    private function cerca(PriceList $d, $sulDisco): ?string
    {
        $nomi = array_filter([
            filled($d->file_path) && ! PriceList::haNomeACaso($d->file_path) ? Str::lower(basename($d->file_path)) : null,
            Str::slug((string) $d->name).'.pdf',
        ]);

        $candidati = $sulDisco->filter(fn (string $f) => in_array(Str::lower(basename($f)), $nomi, true));

        return $candidati->first(fn (string $f) => dirname($f) === PriceList::cartella($d->category))
            ?? $candidati->first();
    }

    /** "price-lists/listini/x.pdf" -> "listini"; "price-lists/x.pdf" -> "". */
    private function sottocartella(string $file): string
    {
        $cartella = dirname($file);

        return $cartella === PriceList::CARTELLA ? '' : Str::after($cartella, PriceList::CARTELLA.'/');
    }

    /**
     * @param  array<string, array{0: string, 1: string, 2: string}>  $cambi
     * @param  \Illuminate\Support\Collection<int, string>  $nuovi
     * @param  array<int, array{0: string, 1: string}>  $mancanti
     */
    private function mostra(array $cambi, $nuovi, array $mancanti): void
    {
        if (! $this->command) {
            return;
        }

        if ($cambi !== []) {
            $this->command->table(['Documento', 'File che non c\'e\'', 'File trovato'], array_values($cambi));
        }

        if ($nuovi->isNotEmpty()) {
            $this->command->table(['PDF sul disco senza documento (diventa un documento nuovo)'], $nuovi->map(fn ($f) => [$f])->all());
        }

        if ($mancanti !== []) {
            $this->command->warn('Documenti il cui PDF non si trova da nessuna parte (restano come sono, vanno ricaricati dal pannello):');
            $this->command->table(['Documento', 'File cercato'], $mancanti);
        }
    }
}
