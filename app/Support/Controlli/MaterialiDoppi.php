<?php

namespace App\Support\Controlli;

use App\Models\Material;
use Illuminate\Support\Collection;

/**
 * Lo stesso materiale a catalogo due volte, con due prezzi diversi.
 *
 * Non e' un problema estetico: chi compila il rapportino sceglie dalla
 * tendina, nella tendina le due voci si leggono identiche, e il prezzo che
 * finisce in fattura dipende da quale delle due ha preso. HLU-30 e HLU30
 * sono entrambi "DETERGENTE HOBART HLU30 12 KG" ma uno costa 64,80 e
 * l'altro 83,00.
 *
 * IL PUNTO DELICATO: lo stesso nome con due prezzi quasi sempre NON e' un
 * doppione. Sono 64 gruppi (27/11/2026) e la gran parte e' legittima per
 * due motivi diversi:
 *
 *  - il nome e' generico. A catalogo ci sono nove "GUARNIZIONE", cinque
 *    "MOLLA", sei "ELETTROVALVOLA": pezzi davvero diversi che per Eureka si
 *    chiamano tutti uguale. Che costino cifre diverse e' ovvio.
 *  - il prezzo diverso e' voluto. I paganti con listino proprio hanno la
 *    loro variante dello stesso codice (F2 / F2HTS / F2GOPPION / F2DAN,
 *    ORE / OREHTS, HLU30 / HLU30ZAMBON): e' tutto il senso di
 *    config/tariffe.php, e segnalarle come doppioni vorrebbe dire dire di
 *    sbagliato proprio cio' che si e' costruito.
 *
 * Quello che distingue il doppione vero non e' il nome, e' il CODICE: e' lo
 * stesso codice scritto due volte in due modi. Una volta col trattino e una
 * volta senza (HLU-30 / HLU30), una volta con lo zero davanti e una volta
 * senza (05000038 / 5000038), o con una cifra scappata nel copia-incolla
 * (22000007 / 2200007, 92722100 / 927221000).
 *
 * La differenza di UNA cifra non e' un dettaglio arbitrario: una cifra in
 * piu' o in meno e' un errore di battitura, mentre una cifra CAMBIATA e' un
 * altro pezzo della stessa serie (419504020 e 419506020 sono due valvole di
 * ritegno diverse, e 983066040 / 983066050 due camme diverse). Per lo
 * stesso motivo il suffisso del pagante resta fuori da solo: "HTS",
 * "GOPPION", "ZAMBON" sono piu' lunghi di un carattere.
 *
 * Con questi due filtri i gruppi scendono da 64 a 11, e sono 11 doppioni
 * veri. Resta comunque un filtro, non un oracolo: dice "guarda qui", non
 * "cancella".
 *
 * @see AnagraficheDoppie per lo stesso ragionamento sulle anagrafiche
 */
class MaterialiDoppi
{
    /**
     * I gruppi di materiali che sembrano la stessa voce di catalogo.
     *
     * @return Collection<int, Collection<int, Material>>
     */
    public static function gruppi(): Collection
    {
        return Material::query()
            ->get(['id', 'code', 'type', 'variant', 'list_price'])
            // Material non ha un campo nome: il nome leggibile e' type +
            // variant (vedi Material::getDisplayLabelAttribute). La
            // normalizzazione e' quella delle anagrafiche — minuscole,
            // punteggiatura via, spazi via — perche' il problema e' lo
            // stesso: due stringhe che una persona legge come uguali. Le
            // forme societarie che quel metodo scarta qui non compaiono.
            ->groupBy(fn (Material $m) => AnagraficheDoppie::normalizza(trim($m->type.' '.$m->variant)))
            ->filter(fn ($g) => $g->count() > 1)
            // Il nome uguale apre il sospetto, il codice lo conferma. Si
            // confronta a coppie dentro il gruppo (sono gruppi da due o tre
            // voci: nove nel caso peggiore) e basta una coppia per dire che
            // il gruppo va guardato.
            ->filter(fn ($g) => self::contieneUnDoppione($g->values()->toBase()))
            ->map(fn ($g) => $g->toBase())
            ->values()
            ->toBase();
    }

    /**
     * Gli id dei materiali dentro quei gruppi.
     *
     * Il confronto fra codici e' a coppie dentro ogni gruppo, in PHP: in
     * SQL non si scrive, quindi il filtro della tabella materiali non puo'
     * essere un where ed e' un whereIn su questi id. Che escono dalla
     * stessa gruppi() che conta sulla scheda, cosi' il numero annunciato e
     * le righe che si aprono sono per costruzione lo stesso insieme.
     *
     * Costa la lettura del catalogo (3600 materiali) e il raggruppamento:
     * 100 ms (27/11/2026). E' il prezzo che la pagina dei controlli paga
     * gia' per contarli, e si paga una volta per apertura dell'elenco.
     *
     * Gli id sono UUID e si tengono come stringhe: castarli a int li
     * riduce alle prime cifre, il whereIn non trova piu' niente di sensato
     * e l'elenco si apre sbagliato senza dare errore.
     *
     * @return array<int, string>
     */
    public static function ids(): array
    {
        return self::gruppi()
            ->flatten(1)
            ->pluck('id')
            ->map(fn ($id) => (string) $id)
            ->all();
    }

    /** @param  Collection<int, Material>  $gruppo */
    private static function contieneUnDoppione(Collection $gruppo): bool
    {
        $n = $gruppo->count();

        for ($i = 0; $i < $n - 1; $i++) {
            for ($j = $i + 1; $j < $n; $j++) {
                $a = $gruppo->get($i);
                $b = $gruppo->get($j);

                if (! $a instanceof Material || ! $b instanceof Material) {
                    continue;
                }

                // Due prezzi uguali non fanno danno in fattura: il doppione
                // c'e' ma chiunque scelga paga lo stesso. Non e' questo il
                // controllo che la titolare ha chiesto.
                if (self::prezzo($a) === self::prezzo($b)) {
                    continue;
                }

                if (self::stessoCodice(self::normalizzaCodice($a->code), self::normalizzaCodice($b->code))) {
                    return true;
                }
            }
        }

        return false;
    }

    private static function prezzo(Material $m): float
    {
        return (float) ($m->list_price ?? 0);
    }

    /** Trattini, punti, spazi e barre: modi diversi di scrivere lo stesso codice. */
    private static function normalizzaCodice(string $codice): string
    {
        return mb_strtoupper((string) preg_replace('/[^A-Za-z0-9]/', '', $codice));
    }

    /**
     * Due codici che sono lo stesso codice: identici, oppure uno e' l'altro
     * con un carattere in piu' (lo zero iniziale perso, la cifra battuta
     * due volte).
     */
    private static function stessoCodice(string $a, string $b): bool
    {
        if ($a === $b) {
            return $a !== '';
        }

        if (abs(strlen($a) - strlen($b)) !== 1) {
            return false;
        }

        [$lungo, $corto] = strlen($a) > strlen($b) ? [$a, $b] : [$b, $a];

        for ($i = 0, $len = strlen($lungo); $i < $len; $i++) {
            if (substr($lungo, 0, $i).substr($lungo, $i + 1) === $corto) {
                return true;
            }
        }

        return false;
    }
}
