<?php

namespace App\Support\Noleggio;

/**
 * Il canone di un noleggio operativo: macchina di Alex, servizi a canone.
 *
 * Non e' il leasing. Nei preventivi a noleggio il canone nasce da un
 * coefficiente (2,02-2,09% dell'imponibile su 60 mesi): quello e' il listino
 * di Grenke, cioe' il prezzo del denaro quando e' il CLIENTE a finanziarsi, e
 * ad Alex l'imponibile arriva subito e intero. Qui e' il contrario: la
 * macchina resta di Alex, il capitale e' suo, e il canone deve ricomprarsela
 * un mese alla volta oltre a pagare il servizio.
 *
 * Tre voci, tenute separate perche' si muovono per ragioni diverse:
 *
 * - MACCHINA: (costo - valore residuo) / mesi, maggiorato. La maggiorazione
 *   copre insieme il margine e il costo del denaro immobilizzato: una sola
 *   leva, perche' due si litigano e nessuno sa piu' quale muovere.
 *   Si parte dal COSTO d'acquisto, non dal listino: sul listino il conto
 *   torna sempre e il margine e' immaginario.
 *
 * - SERVIZIO: il contratto Full-Service costa il 10% annuo del listino
 *   Franke (art. 9), macchina piu' sistema latte piu' optional. E' una
 *   percentuale del LISTINO, non del costo: e' il prezzo di vendita del
 *   servizio, non il suo costo industriale.
 *
 * - DETERGENTI: non sono nel Full-Service, che copre i ricambi e non i
 *   consumabili d'uso. Se non si mettono qui, il canone sembra coprire tutto
 *   e ogni mese se ne va un pezzo di margine in detersivo.
 *
 * Il caffe' resta fuori: fatturato a consumo. Dentro il canone, chi consuma
 * piu' del previsto erode il margine e chi consuma meno si sente truffato.
 */
final class CanoneOperativo
{
    /** Full-Service: percentuale annua del listino (contratto, art. 9). */
    public const FULL_SERVICE_ANNUO = 0.10;

    private function __construct(
        public readonly float $quotaMacchina,
        public readonly float $quotaServizio,
        public readonly float $quotaDetergenti,
        public readonly float $canone,
        public readonly int $mesi,
        public readonly float $costo,
        public readonly float $incassoTotale,
        /** Il mese in cui l'incassato copre il costo della macchina. */
        public readonly ?int $mesePareggio,
    ) {}

    public static function calcola(
        float $costoMacchina,
        float $listinoMacchina,
        int $mesi,
        float $valoreResiduo = 0.0,
        float $margine = 0.0,
        float $detergentiMese = 0.0,
        ?float $fullServiceAnnuo = null,
        float $ricaricoDetergenti = 0.0,
    ): self {
        $mesi = max(1, $mesi);
        $costoMacchina = max(0.0, $costoMacchina);
        // Un residuo piu' alto del costo renderebbe negativa la quota
        // macchina, cioe' il cliente pagherebbe meno del servizio.
        $valoreResiduo = max(0.0, min($valoreResiduo, $costoMacchina));

        $quotaMacchina = ($costoMacchina - $valoreResiduo) / $mesi * (1 + $margine);
        $quotaServizio = $listinoMacchina * ($fullServiceAnnuo ?? self::FULL_SERVICE_ANNUO) / 12;
        // I detergenti hanno una leva propria: se i 100 euro al mese sono un
        // costo e non un prezzo, su di essi non si guadagna niente — ed e' la
        // seconda voce del canone. Separata dal margine della macchina perche'
        // le due si decidono in momenti diversi.
        $quotaDetergenti = max(0.0, $detergentiMese) * (1 + max(0.0, $ricaricoDetergenti));

        $canone = round($quotaMacchina + $quotaServizio + $quotaDetergenti, 2);

        // Pareggio sulla sola quota macchina: servizio e detergenti pagano
        // costi che Alex sostiene mese per mese, non il capitale anticipato.
        // E' il mese da cui una disdetta non fa piu' male.
        $pareggio = $quotaMacchina > 0
            ? (int) ceil(($costoMacchina - $valoreResiduo) / $quotaMacchina)
            : null;

        return new self(
            quotaMacchina: round($quotaMacchina, 2),
            quotaServizio: round($quotaServizio, 2),
            quotaDetergenti: round($quotaDetergenti, 2),
            canone: $canone,
            mesi: $mesi,
            costo: round($costoMacchina, 2),
            incassoTotale: round($canone * $mesi, 2),
            mesePareggio: $pareggio !== null && $pareggio <= $mesi ? $pareggio : $pareggio,
        );
    }

    /** Quanto resta scoperto se il cliente disdice al mese indicato. */
    public function scopertoSeDisdettaAl(int $mese): float
    {
        $incassatoMacchina = $this->quotaMacchina * max(0, min($mese, $this->mesi));

        return round(max(0.0, $this->costo - $incassatoMacchina), 2);
    }
}
