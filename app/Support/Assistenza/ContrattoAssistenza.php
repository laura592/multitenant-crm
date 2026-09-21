<?php

namespace App\Support\Assistenza;

use App\Models\Product;
use App\Models\QuoteProduct;
use Illuminate\Support\Collection;

/**
 * I due contratti di assistenza Franke di Alex, e quanto costano.
 *
 * Il canone e' una percentuale del listino ufficiale Franke (RRP), non del
 * prezzo scontato: lo dicono i contratti stessi (Easy art. 8, Full art. 9).
 * Cambia pero' cosa entra nel conto:
 *
 * - Full-Service, 10%: macchina, sistema latte ed eventuali optional.
 * - Easy-Service, 5%: macchina e sistema latte, senza gli altri optional.
 *
 * "Sistema latte" comprende i frigoriferi (indicazione dell'ufficio,
 * 21/09/2026) e tutto quello che porta il latte: pompe, DualMilk,
 * IndividualMilk, monitoraggio. Si riconosce dal nome dell'opzione, vedi
 * eSistemaLatte().
 *
 * I testi dei contratti non stanno qui ma nei PDF dell'ufficio, in
 * resources/contratti: vedi ContrattoAssistenzaPdf.
 */
final class ContrattoAssistenza
{
    public const FULL = 'full';

    public const EASY = 'easy';

    /**
     * @var array<string, array{nome: string, percentuale: float, file: string, conOptional: bool}>
     */
    public const TIPI = [
        self::FULL => [
            'nome' => 'Full-Service',
            'percentuale' => 10.0,
            'file' => 'full-service.pdf',
            'conOptional' => true,
        ],
        self::EASY => [
            'nome' => 'Easy-Service',
            'percentuale' => 5.0,
            'file' => 'easy-service.pdf',
            'conOptional' => false,
        ],
    ];

    /** I contratti sono Franke: vedi l'intestazione dei due PDF. */
    public const MARCA = 'Franke';

    public static function nome(string $tipo): string
    {
        return self::TIPI[$tipo]['nome'] ?? $tipo;
    }

    public static function percentuale(string $tipo): float
    {
        return self::TIPI[$tipo]['percentuale'] ?? 0.0;
    }

    /** Solo le Franke: gli altri marchi non hanno un contratto. */
    public static function perMacchina(?Product $macchina): bool
    {
        return $macchina !== null
            && $macchina->loadMissing('brand')->brand?->name === self::MARCA;
    }

    /**
     * L'Easy-Service si attiva dal secondo anno, dopo la garanzia (art. 2).
     * Su una macchina nuova si propone lo stesso, dicendolo.
     */
    public static function attivabileDalSecondoAnno(string $tipo): bool
    {
        return $tipo === self::EASY;
    }

    /**
     * Il Full-Service chiede un trattamento acqua dedicato, salvo le Franke
     * col sistema acqua W3 (art. 3). W3 sta nel nome della variante:
     * "A300 NM 1G H1 W3".
     */
    public static function serveTrattamentoAcqua(string $tipo, ?Product $macchina): bool
    {
        return $tipo === self::FULL
            && ! preg_match('/\bW3\b/i', (string) $macchina?->name);
    }

    /**
     * Frigoriferi, pompe e moduli latte: tutto quello che porta il latte.
     *
     * Dal nome ("SU05 EC - Unità di raffreddamento", "MU EC - Modulo pompa
     * latte", "DualMilk"), piu' il tipo "unita' ausiliaria", che nel modello
     * del catalogo e' proprio il frigorifero anche quando il nome e' solo la
     * sigla ("SU03 EC").
     */
    public static function eSistemaLatte(?Product $opzione): bool
    {
        if ($opzione === null) {
            return false;
        }

        return $opzione->type === Product::TYPE_AUXILIARY_UNIT
            || (bool) preg_match('/raffreddament|latte|milk/i', (string) $opzione->name);
    }

    /**
     * Le voci che entrano nel conto di un contratto, col loro prezzo di
     * listino. La prima e' sempre la macchina.
     *
     * @param  iterable<int, array{prodotto: ?Product, prezzo: float}>  $opzioni
     * @return Collection<int, array{nome: string, prezzo: float, perche: string}>
     */
    public static function vociDelConto(string $tipo, Product $macchina, float $prezzoMacchina, iterable $opzioni): Collection
    {
        $conOptional = self::TIPI[$tipo]['conOptional'] ?? false;

        $voci = collect([['nome' => $macchina->name, 'prezzo' => $prezzoMacchina, 'perche' => 'macchina']]);

        foreach ($opzioni as $opzione) {
            $latte = self::eSistemaLatte($opzione['prodotto']);

            if (! $latte && ! $conOptional) {
                continue;
            }

            $voci->push([
                'nome' => $opzione['prodotto']?->name ?? '—',
                'prezzo' => (float) $opzione['prezzo'],
                'perche' => $latte ? 'sistema latte' : 'optional',
            ]);
        }

        return $voci;
    }

    /**
     * @param  iterable<int, array{prodotto: ?Product, prezzo: float}>  $opzioni
     * @return array{base: float, canone: float}
     */
    public static function calcola(string $tipo, Product $macchina, float $prezzoMacchina, iterable $opzioni): array
    {
        $base = (float) self::vociDelConto($tipo, $macchina, $prezzoMacchina, $opzioni)->sum('prezzo');

        return [
            'base' => round($base, 2),
            'canone' => round($base * self::percentuale($tipo) / 100, 2),
        ];
    }

    /**
     * Le opzioni di una macchina gia' in preventivo, col prezzo di listino
     * che la riga ha fissato. Lo sconto sta nel suo campo e qui non conta:
     * il canone e' sul listino.
     *
     * @return Collection<int, array{prodotto: ?Product, prezzo: float}>
     */
    public static function opzioniDellaRiga(QuoteProduct $rigaMacchina): Collection
    {
        return $rigaMacchina->options()->with('product')->get()
            ->map(fn (QuoteProduct $r) => [
                'prodotto' => $r->product,
                'prezzo' => (float) $r->price * max(1, (float) $r->quantity),
            ]);
    }

    /**
     * Il contratto scelto su una macchina gia' in preventivo, gia' calcolato.
     *
     * @return array{tipo: string, nome: string, percentuale: float, base: float, canone: float, voci: Collection}|null
     */
    public static function dellaRiga(QuoteProduct $rigaMacchina): ?array
    {
        $tipo = $rigaMacchina->contratto_assistenza;
        $macchina = $rigaMacchina->product;

        // Solo su una macchina Franke. Il wizard salva il contratto solo li',
        // ma il calcolo non si fida del dato: letto su una riga qualsiasi
        // calcolava un "Full-Service" anche sull'installazione o sul Brita.
        if (! isset(self::TIPI[$tipo]) || ! $macchina
            || $macchina->type !== Product::TYPE_MACHINE || ! self::perMacchina($macchina)) {
            return null;
        }

        $opzioni = self::opzioniDellaRiga($rigaMacchina);
        $prezzo = (float) $rigaMacchina->price;

        return [
            'tipo' => $tipo,
            'nome' => self::nome($tipo),
            'percentuale' => self::percentuale($tipo),
            'voci' => self::vociDelConto($tipo, $macchina, $prezzo, $opzioni),
            ...self::calcola($tipo, $macchina, $prezzo, $opzioni),
        ];
    }
}
