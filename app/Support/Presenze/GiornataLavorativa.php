<?php

namespace App\Support\Presenze;

use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;

/**
 * Come si divide una giornata di lavoro fra ore ordinarie, ore coperte dalla
 * trasferta e straordinario.
 *
 * Unica regola per tutto il riepilogo — mensile, dettaglio giornaliero e i
 * due Excel per il commercialista leggono da qui — perche' prima lo stesso
 * conto era scritto due volte in RiepilogoOre, e una regola nuova messa in un
 * posto solo avrebbe dato due numeri diversi per lo stesso giorno.
 *
 * Nel giorno di trasferta le ore comprese nell'indennita'
 * (config presenze.trasferta_ore_incluse) non sono ne' ordinarie ne'
 * straordinario: sono "coperte". Tenerle fuori dalle ordinarie conta, perche'
 * lo straordinario settimanale si calcola sulle ordinarie della settimana, e
 * altrimenti l'ora pagata dalla trasferta rientrerebbe da li'.
 *
 * Dal 02/11/2026 quella regola si puo' scrivere anche sul singolo dipendente
 * (users.trasferta_ore_incluse): Igor e' in giro tutti i giorni e la sua
 * indennita' si porta dentro un'ora. Quando c'e' il valore sulla persona
 * vince sempre, anche sui mesi passati — e' una sua condizione, non una
 * regola aziendale cambiata a una certa data.
 *
 * Dal 01/10/2026 quel valore e' zero — la trasferta non toglie piu' niente —
 * ma per i giorni precedenti resta un'ora, perche' i mesi chiusi vanno
 * calcolati con la regola con cui sono stati pagati. Per questo la
 * ripartizione ha bisogno di sapere di che giorno si tratta: senza la data
 * userebbe la regola di oggi anche per settembre, e un riepilogo ristampato
 * contraddirebbe una busta paga gia' emessa.
 */
final class GiornataLavorativa
{
    private function __construct(
        public readonly float $ordinarie,
        public readonly float $coperteDaTrasferta,
        public readonly float $straordinario,
    ) {}

    /**
     * Le ore di una giornata al netto della pausa.
     *
     * La pausa si toglie SOLO se non e' gia' nel cartellino. Chi timbra due
     * turni — 08:00-12:00 e 13:00-17:00 — la pausa ce l'ha gia' fuori: 8 ore
     * sono 8 ore, e toglierne una seconda ne farebbe 7. Chi invece timbra
     * una volta sola, 08:00-19:00, quelle 11 ore la pausa ce l'hanno dentro.
     *
     * La soglia e' il contratto: una mezza giornata timbrata in un colpo solo
     * (08:00-12:00) non ha pausa da togliere, e senza questo controllo
     * diventerebbe di tre ore.
     *
     * @param  int  $timbrature  quante timbrature ha quel giorno
     */
    public static function lavorateNette(float $lavorate, int $timbrature, float $contratto, float $pausaOre): float
    {
        if ($pausaOre <= 0 || $timbrature !== 1 || $lavorate <= $contratto) {
            return $lavorate;
        }

        return max(0.0, round($lavorate - $pausaOre, 2));
    }

    /**
     * @param  CarbonInterface|string|null  $giorno  il giorno della
     *                                               prestazione, che decide quale regola applicare. Null = regola di
     *                                               oggi: e' il caso di un calcolo senza data, non di un giorno
     *                                               vecchio.
     */
    public static function ripartisci(float $lavorate, float $contratto, bool $trasferta, $giorno = null, ?float $oreIncluseUtente = null): self
    {
        $lavorate = max(0.0, $lavorate);
        $contratto = max(0.0, $contratto);

        // Sotto il contratto non cambia niente, trasferta o no: le ore che
        // mancano restano mancanti come in qualunque altro giorno — 6 ore in
        // trasferta sono 6 ordinarie (confermato dall'ufficio, 21/09/2026).
        $ordinarie = min($lavorate, $contratto);
        $oltre = $lavorate - $ordinarie;

        $incluse = $trasferta ? ($oreIncluseUtente ?? self::oreIncluse($giorno)) : 0.0;
        $coperte = min($oltre, $incluse);

        return new self(
            ordinarie: $ordinarie,
            coperteDaTrasferta: $coperte,
            straordinario: $oltre - $coperte,
        );
    }

    /**
     * Quante ore la trasferta si mangia in quel giorno.
     *
     * Il confronto e' sul giorno, non sull'istante: una timbratura del
     * 30/09 alle 23 sta ancora nella regola vecchia.
     */
    private static function oreIncluse($giorno): float
    {
        $dal = config('presenze.trasferta_regola_nuova_dal');

        if ($giorno !== null && filled($dal)
            && Carbon::parse($giorno)->startOfDay()
                ->lt(Carbon::parse($dal)->startOfDay())) {
            return (float) config('presenze.trasferta_ore_incluse_prima', 1);
        }

        return (float) config('presenze.trasferta_ore_incluse', 0);
    }
}
