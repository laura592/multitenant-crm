<?php

namespace App\Support\Preventivi;

use App\Models\Product;
use App\Models\Quote;
use App\Models\QuoteProduct;
use Illuminate\Support\Collection;

/**
 * Come si chiamano, in parole, le alternative di un'offerta.
 *
 * "Soluzione 1" e "Soluzione 2" non dicono niente: sono due numeri d'ordine
 * (Laura, 26/11/2026 -- "continua a non essere chiara"). Su OFF-2026-0028
 * le due alternative erano A400 MS EC a 10.714,50 e A400 FM CM a 13.351,50,
 * e dal titolo il cliente non poteva sapere ne' che cosa cambiava ne'
 * perche' una costasse 2.637 EUR di piu'.
 *
 * Qui si risponde a tre domande, le stesse che si fa chi riceve l'offerta:
 *
 *   - CHE COS'E'          -> titolo(): la macchina, la voce principale
 *   - CHE COSA CAMBIA     -> differenza(): solo le voci che le altre
 *                            alternative non hanno
 *   - QUANTO COSTA IN PIU'-> maggiorazione(): la differenza rispetto alla
 *                            piu' economica
 *
 * Le voci presenti in TUTTE le alternative si tacciono. Su due
 * configurazioni dello stesso modello sono quasi tutte: macinacaffe',
 * dosatori, trattamento acqua, installazione. Elencarle di nuovo accanto a
 * ogni soluzione nasconde le due righe che cambiano davvero.
 *
 * Vive qui e non dentro un template perche' serve identica in due posti --
 * la mail dell'offerta e la pagina del cliente -- e due copie della stessa
 * regola avrebbero cominciato a dire cose diverse al primo ritocco.
 */
class ConfrontoSoluzioni
{
    /** @var array<int, string> i nomi presenti in ogni alternativa */
    private array $comuni;

    /**
     * @param  Collection<int, Quote>  $soluzioni
     */
    private function __construct(private Collection $soluzioni)
    {
        $this->comuni = $soluzioni->count() > 1
            ? array_intersect(...array_map(
                fn (Quote $q) => $this->voci($q),
                $soluzioni->values()->all()
            ))
            : [];
    }

    /**
     * @param  Collection<int, Quote>  $soluzioni
     */
    public static function per(Collection $soluzioni): self
    {
        return new self($soluzioni);
    }

    /**
     * Il titolo: la macchina. Se non ce n'e' una a catalogo (preventivi di
     * soli materiali, o righe scritte a mano) resta il numero, che e'
     * sempre meglio di niente.
     */
    public function titolo(Quote $quote): string
    {
        $macchine = $this->macchine($quote);

        return $macchine->isNotEmpty()
            ? $macchine->implode(' + ')
            : (string) $quote->number;
    }

    /**
     * Che cosa distingue questa alternativa dalle altre, in parole.
     *
     * @return Collection<int, string>
     */
    public function differenza(Quote $quote): Collection
    {
        $macchine = $this->macchine($quote)->all();

        return collect($this->voci($quote))
            ->reject(fn (string $nome) => in_array($nome, $this->comuni, true)
                || in_array($nome, $macchine, true))
            ->map(fn (string $nome) => self::inParole($nome))
            ->values();
    }

    /**
     * Quanto costa in piu' della piu' economica. Null se e' lei, o se le
     * alternative non sono confrontabili.
     */
    public function maggiorazione(Quote $quote): ?float
    {
        $base = $this->piuEconomica();

        if (! $base || $base->is($quote) || ! $this->confrontabili()) {
            return null;
        }

        $differenza = $this->prezzo($quote) - $this->prezzo($base);

        return $differenza > 0 ? $differenza : null;
    }

    /** Se la maggiorazione e' un canone mensile o un importo secco. */
    public function aCanone(): bool
    {
        return $this->confrontabili()
            && $this->soluzioni->every(fn (Quote $q) => $this->eNoleggio($q));
    }

    public function piuEconomica(): ?Quote
    {
        if (! $this->confrontabili()) {
            return null;
        }

        return $this->soluzioni->sortBy(fn (Quote $q) => $this->prezzo($q))->first();
    }

    /**
     * Confrontare un acquisto con un noleggio vorrebbe dire mettere a
     * paragone 13.000 euro una tantum e 180 euro al mese: due numeri che
     * non si sommano e non si sottraggono. In quel caso non si scrive
     * nessuna differenza di prezzo, che e' meglio di scriverne una falsa.
     */
    private function confrontabili(): bool
    {
        if ($this->soluzioni->count() < 2) {
            return false;
        }

        $noleggi = $this->soluzioni->filter(fn (Quote $q) => $this->eNoleggio($q))->count();

        return $noleggi === 0 || $noleggi === $this->soluzioni->count();
    }

    private function eNoleggio(Quote $quote): bool
    {
        return $quote->payment_method === 'noleggio-operativo'
            && (float) $quote->rental_monthly_fee > 0;
    }

    private function prezzo(Quote $quote): float
    {
        return $this->eNoleggio($quote)
            ? (float) $quote->rental_monthly_fee
            : (float) $quote->subtotal;
    }

    /**
     * I nomi delle macchine della soluzione.
     *
     * Ciclo esplicito e non una catena di map/filter: la relazione
     * quoteProducts e' dichiarata su Model e non su QuoteProduct, quindi
     * l'analisi statica non saprebbe che $riga->product esiste. Il
     * commento @var e' la dichiarazione che manca alla relazione.
     *
     * @return Collection<int, string>
     */
    private function macchine(Quote $quote): Collection
    {
        /** @var array<int, string> $nomi */
        $nomi = [];

        foreach (self::righe($quote) as $riga) {
            $prodotto = $riga->product;

            // instanceof e non un truthy: la relazione e' dichiarata senza
            // generici e per l'analisi statica torna un Model qualunque,
            // che un nome non ce l'ha. Il controllo serve anche davvero:
            // product_id puo' essere vuoto.
            if ($prodotto instanceof Product && $prodotto->type === Product::TYPE_MACHINE && $prodotto->name) {
                $nomi[] = $prodotto->name;
            }
        }

        return collect($nomi)->unique()->values();
    }

    /**
     * Tutte le voci della soluzione.
     *
     * Ogni riga di un preventivo punta a un articolo a catalogo -- in
     * quote_products non esiste nessun campo descrittivo libero -- quindi
     * il nome del prodotto e' l'unica cosa che il cliente legge.
     *
     * @return array<int, string>
     */
    private function voci(Quote $quote): array
    {
        $nomi = [];

        foreach (self::righe($quote) as $riga) {
            $prodotto = $riga->product;

            if ($prodotto instanceof Product && $prodotto->name) {
                $nomi[] = $prodotto->name;
            }
        }

        return array_values(array_unique($nomi));
    }

    /**
     * Le righe del preventivo, tipizzate.
     *
     * La relazione quoteProducts e' dichiarata HasMany senza generici,
     * quindi per l'analisi statica itera su Model e $riga->product non
     * esisterebbe. L'annotazione sull'assegnazione e' la dichiarazione che
     * manca alla relazione, e sta qui invece che in tre cicli diversi.
     *
     * @return array<int, QuoteProduct>
     */
    private static function righe(Quote $quote): array
    {
        /** @var array<int, QuoteProduct> $righe */
        $righe = $quote->quoteProducts->all();

        return $righe;
    }

    /**
     * Il nome del prodotto senza il codice davanti.
     *
     * A catalogo molti articoli si chiamano "SU05 CM - Unita' di
     * raffreddamento 5l con cartuccia pulizia integrata": la parte utile al
     * cliente e' dopo il trattino, il codice serve a noi. Nel dettaglio
     * voce per voce resta il nome intero; qui, dove si spiega la
     * differenza, si tiene la parte in italiano.
     *
     * Il trattino deve essere spaziato: "A400 FM CM 1G H1" e "SU06 EC Plus"
     * restano interi, e un nome che inizia con un trattino non viene
     * svuotato.
     */
    private static function inParole(string $nome): string
    {
        $pezzi = explode(' - ', $nome, 2);

        if (count($pezzi) === 2 && trim($pezzi[1]) !== '') {
            return trim($pezzi[1]);
        }

        return $nome;
    }
}
