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

    public const IMPIANTO_ORA_SU_EUREKA = 'impianto segnato a mano, ora arriva dal gestionale';

    /**
     * Gli impianti nati nel CRM: non hanno una matricola, hanno un codice
     * nostro (IMP-SPINA-022, CASETTA-ACQUA-1). Quando poi il gestionale
     * registra lo stesso impianto, la matricola e' un'altra e nessuna
     * regola sulle matricole li mette insieme.
     */
    private const MATRICOLE_NOSTRE = ['IMP-', 'IMPIANTO-', 'CASETTA'];

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
     * (gestionale_code) prima di tutto, poi quella la cui matricola Eureka
     * elenca oggi fra gli installati, poi quella con un modello, poi la piu'
     * vecchia. La matricola scritta a mano e' quasi sempre la copia.
     *
     * $matricoleEureka sono le chiavi (chiave()) delle matricole che Eureka
     * elenca fra gli installati. Tenere quella li' conta: e' per matricola
     * che il sync ritrova la macchina, e con la matricola "sbagliata" gli
     * spostamenti proposti da Eureka non la trovavano piu' (22/09/2026,
     * tenuta "1919045", Eureka "1919045-21679").
     *
     * Se Eureka le elenca TUTTE E DUE, per Eureka sono due apparecchi: non si
     * propone niente ("1502475" e "1502475-CM103290", due TEOREMA A2 presso
     * lo stesso hotel con due bolle diverse).
     *
     * @param  Collection<int, MachineUnit>  $macchine
     * @param  array<string, true>  $matricoleEureka
     * @return array<int, array{tenere: MachineUnit, assorbire: MachineUnit, motivo: string}>
     */
    public static function proposte(Collection $macchine, array $matricoleEureka = []): array
    {
        $macchine = $macchine->reject(fn (MachineUnit $m) => self::segnaposto(self::chiave($m->serial_number)));
        $suEureka = fn (MachineUnit $m) => isset($matricoleEureka[self::chiave($m->serial_number)]);

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

                $ordinate = self::perAffidabilita($gruppo->reject(fn (MachineUnit $m) => isset($gia[$m->id])), $matricoleEureka);

                if ($ordinate->count() < 2) {
                    continue;
                }

                // "031814" e "31814" entrambe su Eureka: per Eureka sono due.
                if ($ordinate->filter($suEureka)->map(fn (MachineUnit $m) => self::chiave($m->serial_number))->uniqueStrict()->count() > 1) {
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

                    if (! self::contieneComeToken($lunga->serial_number, $corta->serial_number)) {
                        continue;
                    }

                    if ($suEureka($lunga) && $suEureka($corta)) {
                        continue;
                    }

                    // Di solito la corta e' il seriale e la lunga ha il
                    // modello davanti; ma se Eureka la scrive lunga, si
                    // tiene come la scrive Eureka.
                    [$tenere, $assorbire] = $suEureka($lunga) ? [$lunga, $corta] : [$corta, $lunga];

                    $proposte[] = ['tenere' => $tenere, 'assorbire' => $assorbire, 'motivo' => self::MATRICOLA_CONTENUTA];
                    $gia[$assorbire->id] = true;
                }
            }
        }

        // 3) L'impianto segnato a mano che ora il gestionale registra con una
        //    matricola sua (23/09/2026, Bar Miki: "IMP-SPINA-022" scritto qui
        //    e "SPINAMIKI" arrivato da Eureka sono lo stesso impianto alla
        //    spina). Le matricole non si somigliano per niente: l'unico
        //    appiglio e' che presso quel cliente c'e' un impianto solo di
        //    quel genere per parte. Se ce n'e' piu' d'uno non si propone
        //    niente: quale sia quale non lo si puo' dire.
        foreach ($macchine->groupBy('current_customer_id') as $cliente => $gruppo) {
            if ((string) $cliente === '' || $gruppo->count() < 2) {
                continue;
            }

            $disponibili = $gruppo->reject(fn (MachineUnit $m) => isset($gia[$m->id]));

            foreach (['spina', 'acqua'] as $genere) {
                $nostri = $disponibili->filter(fn (MachineUnit $m) => self::matricolaNostra($m) && self::genereImpianto($m) === $genere);
                $loro = $disponibili->filter(fn (MachineUnit $m) => ! self::matricolaNostra($m) && self::genereImpianto($m) === $genere && $suEureka($m));

                if ($nostri->count() !== 1 || $loro->count() !== 1) {
                    continue;
                }

                $assorbire = $nostri->first();
                $proposte[] = ['tenere' => $loro->first(), 'assorbire' => $assorbire, 'motivo' => self::IMPIANTO_ORA_SU_EUREKA];
                $gia[$assorbire->id] = true;
            }
        }

        return $proposte;
    }

    /** La matricola non e' una matricola: e' un codice messo da noi. */
    private static function matricolaNostra(MachineUnit $macchina): bool
    {
        $matricola = mb_strtoupper(trim((string) $macchina->serial_number));

        foreach (self::MATRICOLE_NOSTRE as $inizio) {
            if (str_starts_with($matricola, $inizio)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Che genere di impianto e': alla spina (birra, vino, selz) o acqua
     * (casette e impianti acqua). Null quando non e' un impianto — una
     * macchina da caffe' non si fonde con niente per somiglianza di nome.
     */
    private static function genereImpianto(MachineUnit $macchina): ?string
    {
        $nome = mb_strtolower(trim($macchina->model_name.' '.$macchina->serial_number));

        if ($macchina->type === MachineUnit::TYPE_IMPIANTO_ACQUA) {
            return 'acqua';
        }

        if ($macchina->type === MachineUnit::TYPE_COLONNA_SPINA) {
            return 'spina';
        }

        if (str_contains($nome, 'casetta') || str_contains($nome, 'casa dell\'acqua') || str_contains($nome, 'impianto acqua')) {
            return 'acqua';
        }

        if (str_contains($nome, 'spina') || str_contains($nome, 'birra')) {
            return 'spina';
        }

        return null;
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
     * la matricola che Eureka elenca, poi chi ha un modello, poi la piu'
     * vecchia.
     *
     * @param  Collection<int, MachineUnit>  $gruppo
     * @param  array<string, true>  $matricoleEureka
     * @return Collection<int, MachineUnit>
     */
    private static function perAffidabilita(Collection $gruppo, array $matricoleEureka = []): Collection
    {
        // Una chiave composita, non un array di closure: sortBy() in quella
        // forma vuole coppie [campo, verso] e con le sole closure non ordina
        // come ci si aspetta (teneva la matricola scritta a mano).
        return $gruppo->sortBy(fn (MachineUnit $m) => sprintf(
            '%d%d%d%020d',
            $m->gestionale_code === null ? 1 : 0,
            isset($matricoleEureka[self::chiave($m->serial_number)]) ? 0 : 1,
            trim((string) $m->model_name) === '' ? 1 : 0,
            $m->created_at?->timestamp ?? 0,
        ))->values();
    }
}
