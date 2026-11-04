<?php

namespace App\Console\Commands;

use App\Models\InformationRequest;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Collection;

/**
 * Riempie "Prodotti di interesse" sulle richieste, prendendoli dal
 * preventivo che ne e' nato.
 *
 * Il campo c'era da sempre ma era praticamente vuoto: 54 richieste su 316
 * (04/11/2026). Non perche' il dato manchi -- quando una richiesta diventa
 * un preventivo, li' dentro c'e' scritto esattamente di quali macchine si
 * parlava -- ma perche' andava ricopiato a mano in una select su tutto il
 * catalogo, e nessuno lo faceva. Un campo che si riempie solo a mano, e
 * solo dopo averlo gia' scritto altrove, resta vuoto.
 *
 * Si prendono solo le righe base del preventivo (`baseQuoteProducts`): le
 * opzioni figlie sono accessori di una macchina gia' contata, e metterle
 * fra i "prodotti di interesse" allungherebbe l'elenco senza dire niente
 * di nuovo.
 *
 * Da qui in avanti ci pensa QuoteProduct::booted(): questo comando serve
 * per lo storico, e per rimettere a posto se qualcosa si disallinea.
 */
class CollegaProdottiRichieste extends Command
{
    protected $signature = 'richieste:collega-prodotti
        {--tutte    : Rilegge anche le richieste che qualche prodotto ce l\'hanno gia\'}
        {--dry-run  : Mostra cosa collegherebbe senza scrivere}';

    protected $description = 'Collega alle richieste i prodotti del preventivo che ne e\' nato';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');

        $query = InformationRequest::query()
            ->whereHas('quotes.baseQuoteProducts', fn ($q) => $q->whereNotNull('product_id'))
            ->with(['quotes.baseQuoteProducts.product.category:id,name', 'products:id,name', 'customer:id,company_name']);

        // Di default non si tocca chi ha gia' qualcosa: se qualcuno ha
        // scelto i prodotti a mano, quella scelta vale piu' della nostra.
        if (! $this->option('tutte')) {
            $query->whereDoesntHave('products');
        }

        $conti = ['viste' => 0, 'collegate' => 0, 'gia_a_posto' => 0, 'prodotti' => 0];
        $righe = [];

        $query->chunkById(100, function (Collection $richieste) use ($dryRun, &$conti, &$righe) {
            foreach ($richieste as $r) {
                $conti['viste']++;

                $ids = $r->quotes
                    ->flatMap(fn ($q) => $q->baseQuoteProducts)
                    ->filter(fn ($qp) => $qp->diceCosaInteressa())
                    ->pluck('product_id')
                    ->filter()
                    ->unique()
                    ->values();

                $nuovi = $ids->diff($r->products->pluck('id'));

                if ($nuovi->isEmpty()) {
                    $conti['gia_a_posto']++;

                    continue;
                }

                $nomi = $r->quotes
                    ->flatMap(fn ($q) => $q->baseQuoteProducts)
                    ->filter(fn ($qp) => $nuovi->contains($qp->product_id))
                    ->map(fn ($qp) => $qp->product?->name)
                    ->filter()->unique()->implode(', ');

                $righe[] = [$r->number, mb_substr((string) $r->customer?->company_name, 0, 28), mb_substr($nomi, 0, 46)];
                $conti['collegate']++;
                $conti['prodotti'] += $nuovi->count();

                if (! $dryRun) {
                    // syncWithoutDetaching e non sync: con --tutte non si
                    // devono buttare via i prodotti scelti a mano.
                    $r->products()->syncWithoutDetaching($nuovi->all());
                }
            }
        });

        $this->newLine();

        if ($righe !== []) {
            $this->table(['Richiesta', 'Cliente', 'Prodotti dal preventivo'], $righe);
        }

        $this->table(['', 'Richieste'], [
            ['Viste', $conti['viste']],
            ['Collegate', $conti['collegate']],
            ['Gia\' a posto', $conti['gia_a_posto']],
            ['Prodotti collegati in tutto', $conti['prodotti']],
        ]);

        if ($dryRun) {
            $this->warn('--dry-run: niente e\' stato scritto.');
        }

        return self::SUCCESS;
    }
}
