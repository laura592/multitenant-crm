<?php

namespace App\Support\Gestionale;

use App\Models\MachineUnit;
use Illuminate\Support\Collection;

/**
 * Riconosce quando due MachineUnit sono lo stesso apparecchio.
 *
 * Il problema, visto dal vivo il 02/09/2026: la stessa macchina entra due
 * volte perche' chi la registra scrive nel campo matricola quello che ha
 * sott'occhio. Tre forme ricorrenti:
 *
 *  - la punteggiatura: "BRL 003 020002113218" contro "BRL003020002113218",
 *    "-0819352" contro "0819352";
 *  - il modello davanti al seriale: "A 300 3400000310192" contro
 *    "3400000310192";
 *  - la descrizione al posto del seriale: "Bevco petit ICE 50" per una
 *    macchina che su Eureka e' "AL25284350".
 *
 * Le prime due si riconoscono dai dati. La terza no, e resta al confronto
 * con Eureka.
 *
 * Qui non si decide niente: fondere due macchine sposta rapportini e storico
 * di manutenzione, e la conferma resta a una persona.
 */
class ConfrontoMacchine
{
    public const STESSA_MATRICOLA = 'stessa matricola, scritta diversamente';

    public const ZERI_INIZIALI = 'stessa matricola, cambiano solo gli zeri iniziali';

    public const MATRICOLA_CONTENUTA = 'la matricola di una contiene quella dell\'altra';

    /**
     * La chiave con cui due matricole si confrontano: senza punteggiatura,
     * che in un numero di serie non porta informazione.
     */
    public static function chiave(?string $matricola): string
    {
        return MachineUnit::chiaveMatricola($matricola);
    }

    /**
     * Una matricola di soli zeri ("000000", "0000000") non e' un numero di
     * serie: e' il campo lasciato in bianco. Confrontarle fra loro
     * proporrebbe di fondere macchine che non c'entrano niente.
     */
    private static function segnaposto(string $chiave): bool
    {
        return $chiave === '' || trim($chiave, '0') === '';
    }

    /**
     * Le coppie da proporre: la prima e' quella da tenere, la seconda quella
     * da assorbire.
     *
     * Si tiene la macchina piu' "vera": quella collegata a Eureka
     * (gestionale_code) prima di tutto, poi quella con un modello, poi la
     * piu' vecchia. La matricola scritta a mano e' quasi sempre la copia.
     *
     * @param  Collection<int, MachineUnit>  $macchine
     * @return array<int, array{tenere: MachineUnit, assorbire: MachineUnit, motivo: string}>
     */
    public static function proposte(Collection $macchine): array
    {
        $macchine = $macchine->reject(fn (MachineUnit $m) => self::segnaposto(self::chiave($m->serial_number)));

        $proposte = [];
        $gia = [];

        // 1) Stessa matricola a meno di punteggiatura, e stessa matricola a
        //    meno degli zeri iniziali ("028019" e "28019"). Sono i due casi
        //    certi: nessun apparecchio ha due seriali che differiscono solo
        //    per uno spazio o per uno zero davanti.
        foreach ([
            [fn (MachineUnit $m) => self::chiave($m->serial_number), self::STESSA_MATRICOLA],
            [fn (MachineUnit $m) => ltrim(self::chiave($m->serial_number), '0'), self::ZERI_INIZIALI],
        ] as [$chiave, $motivo]) {
            foreach ($macchine->groupBy($chiave) as $valore => $gruppo) {
                if ((string) $valore === '' || $gruppo->count() < 2) {
                    continue;
                }

                $ordinate = self::perAffidabilita($gruppo->reject(fn (MachineUnit $m) => isset($gia[$m->id])));

                if ($ordinate->count() < 2) {
                    continue;
                }

                $tenere = $ordinate->shift();

                foreach ($ordinate as $assorbire) {
                    $proposte[] = ['tenere' => $tenere, 'assorbire' => $assorbire, 'motivo' => $motivo];
                    $gia[$assorbire->id] = true;
                }
            }
        }

        // 2) Una matricola contiene l'altra COME TOKEN A SE': il modello o un
        //    codice interno scritti davanti al seriale ("MC 031653 PK905",
        //    "708561-103073", "AA25106852").
        //
        //    Il confine e' obbligatorio. Senza, "1955952" dentro
        //    "1955952741" verrebbe proposto come doppione: sono due seriali
        //    diversi che per caso condividono un prefisso, e fonderli
        //    perderebbe una macchina vera.
        foreach ($macchine->groupBy('current_customer_id') as $cliente => $gruppo) {
            if ((string) $cliente === '' || $gruppo->count() < 2) {
                continue;
            }

            foreach ($gruppo as $lunga) {
                foreach ($gruppo as $corta) {
                    if ($lunga->id === $corta->id || isset($gia[$lunga->id]) || isset($gia[$corta->id])) {
                        continue;
                    }

                    if (self::contieneComeToken($lunga->serial_number, $corta->serial_number)) {
                        $proposte[] = ['tenere' => $corta, 'assorbire' => $lunga, 'motivo' => self::MATRICOLA_CONTENUTA];
                        $gia[$lunga->id] = true;
                    }
                }
            }
        }

        return $proposte;
    }

    /**
     * True se $corta e' uno dei "pezzi" di $lunga.
     *
     * Non basta str_contains sulla stringa normalizzata: li' "A 300" diventa
     * "a300" e il seriale che segue non ha piu' nessun confine, mentre
     * "1955952" dentro "1955952741" ne avrebbe uno inesistente. Si spezza
     * quindi la matricola lunga dov'e' scritta davvero — sulla
     * punteggiatura e sul passaggio fra lettere e cifre — e si guarda se
     * qualche sequenza di pezzi consecutivi fa esattamente la matricola
     * corta.
     *
     * "A 300 3400000310192" -> [a, 300, 3400000310192]  contiene 3400000310192
     * "AA25106852"          -> [aa, 25106852]           contiene 25106852
     * "MC 031653 PK905"     -> [mc, 031653, pk, 905]    contiene pk+905
     * "1955952741"          -> [1955952741]             NON contiene 1955952
     */
    private static function contieneComeToken(?string $lunga, ?string $corta): bool
    {
        $b = self::chiave($corta);

        // Sotto i cinque caratteri la somiglianza e' rumore: un seriale di
        // due cifre sta dentro mezza anagrafica.
        if ($b === '' || mb_strlen($b) < 5 || self::chiave($lunga) === $b) {
            return false;
        }

        $pezzi = self::pezzi($lunga);

        for ($i = 0; $i < count($pezzi); $i++) {
            $accumulato = '';

            for ($j = $i; $j < count($pezzi); $j++) {
                $accumulato .= $pezzi[$j];

                if ($accumulato === $b) {
                    return true;
                }

                if (mb_strlen($accumulato) > mb_strlen($b)) {
                    break;
                }
            }
        }

        return false;
    }

    /**
     * I pezzi di una matricola: separati dalla punteggiatura e dal passaggio
     * fra lettere e cifre.
     *
     * @return array<int, string>
     */
    private static function pezzi(?string $matricola): array
    {
        $normalizzata = mb_strtolower(trim((string) $matricola));
        $separati = preg_split('/[\s\-.\/]+/u', $normalizzata, -1, PREG_SPLIT_NO_EMPTY) ?: [];

        $pezzi = [];

        foreach ($separati as $pezzo) {
            preg_match_all('/\d+|\D+/u', $pezzo, $trovati);
            foreach ($trovati[0] as $t) {
                $pezzi[] = $t;
            }
        }

        return $pezzi;
    }

    /**
     * Dalla piu' attendibile alla meno: chi ha un codice Eureka, poi chi ha
     * un modello, poi la piu' vecchia.
     *
     * @param  Collection<int, MachineUnit>  $gruppo
     * @return Collection<int, MachineUnit>
     */
    private static function perAffidabilita(Collection $gruppo): Collection
    {
        // Una chiave composita, non un array di closure: sortBy() in quella
        // forma vuole coppie [campo, verso] e con le sole closure non ordina
        // come ci si aspetta (teneva la matricola scritta a mano).
        return $gruppo->sortBy(fn (MachineUnit $m) => sprintf(
            '%d%d%020d',
            $m->gestionale_code === null ? 1 : 0,
            trim((string) $m->model_name) === '' ? 1 : 0,
            $m->created_at?->timestamp ?? 0,
        ))->values();
    }
}
