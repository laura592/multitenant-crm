<?php

namespace App\Console\Commands;

use App\Models\Material;
use Illuminate\Console\Command;

/**
 * Propone il codice manutenzione sui modelli di macchina a catalogo.
 *
 * Il codice (F2, F3, DC2, MANA300...) dipende da marca e numero di gruppi, e
 * il nome del modello quasi sempre li dice gia': "FAEMA TEOREMA A/2" e' una
 * F2, "MACCHINA PER CAFFE' FRANKE A300" e' una MANA300. Compilarli a mano
 * significherebbe aprire centinaia di schede per riscrivere un'informazione
 * che e' gia' li'.
 *
 * Deliberatamente prudente: propone SOLO dove marca e gruppi sono
 * riconoscibili senza indovinare. I modelli che hanno i gruppi ma una marca
 * che non sappiamo mappare (E 61 LEGEND, CASADIO UNDICI) finiscono in un
 * elenco a parte, da decidere a mano — meglio una casella vuota che un
 * codice sbagliato, che finirebbe in fattura.
 *
 * Non tocca i modelli che un codice ce l'hanno gia': quello lo ha messo una
 * persona e vale piu' di una regola. Con --sovrascrivi si riallineano anche
 * quelli, ma e' un gesto separato e da chiedere.
 */
class ProponiCodiciManutenzione extends Command
{
    protected $signature = 'macchine:proponi-codici-manutenzione
        {--dry-run     : Mostra le proposte senza scrivere}
        {--sovrascrivi : Riallinea anche i modelli che hanno gia un codice}
        {--force       : Applica senza chiedere conferma}';

    protected $description = 'Propone il codice manutenzione sui modelli di macchina, leggendolo dal nome';

    public function handle(): int
    {
        // Solo i modelli davvero collegati a una macchina: il catalogo Eureka
        // ha migliaia di ricambi, e proporre un codice manutenzione su una
        // guarnizione non ha senso.
        $modelli = Material::query()
            ->whereHas('machineUnits')
            ->withCount('machineUnits')
            ->orderBy('type')
            ->get();

        $proposte = [];
        $daDecidere = [];

        foreach ($modelli as $modello) {
            if (filled($modello->maintenance_code) && ! $this->option('sovrascrivi')) {
                continue;
            }

            $codice = self::codicePer($modello->type);

            if ($codice === null) {
                if (self::sembraUnaMacchinaDaCaffe($modello->type)) {
                    $daDecidere[] = $modello;
                }

                continue;
            }

            if ($codice === $modello->maintenance_code) {
                continue;
            }

            $proposte[] = ['modello' => $modello, 'codice' => $codice];
        }

        $this->mostra($proposte, $daDecidere);

        if ($proposte === []) {
            return self::SUCCESS;
        }

        if ($this->option('dry-run')) {
            $this->comment('--dry-run: niente e stato scritto.');

            return self::SUCCESS;
        }

        if (! $this->option('force') && ! $this->confirm('Scrivo i codici proposti sui modelli?')) {
            $this->comment('Annullato: nessuna modifica.');

            return self::SUCCESS;
        }

        foreach ($proposte as $p) {
            $p['modello']->update(['maintenance_code' => $p['codice']]);
        }

        $this->info('Modelli aggiornati: '.count($proposte).'.');

        return self::SUCCESS;
    }

    /**
     * Il codice manutenzione leggibile dal nome del modello, o null.
     *
     * L'ordine conta: le marche con codice proprio (Dalla Corte, Franke,
     * le superautomatiche) vanno riconosciute PRIMA della regola generica
     * sui gruppi, altrimenti "DALLA CORTE DC PRO 3 GRUPPI" diventerebbe una
     * F3.
     */
    public static function codicePer(?string $type): ?string
    {
        $t = mb_strtoupper((string) $type);

        if ($t === '') {
            return null;
        }

        // Franke: il modello e' gia' il codice (A300 -> MANA300). Le sigle
        // portano suffissi (A600FM, A800FM) che non fanno parte del codice.
        if (str_contains($t, 'FRANKE') && preg_match('/\bA\s?(300|400|600|800)/', $t, $m)) {
            return 'MANA'.$m[1];
        }

        // Superautomatiche, ognuna col suo codice: non si contano a gruppi.
        if (preg_match('/\bX\s?15\b/', $t)) {
            return 'MAX15';
        }

        if (preg_match('/\bX\s?20\b/', $t)) {
            return 'MANX20';
        }

        if (str_contains($t, 'CIMBALI') && (str_contains($t, 'SUPERAUTOMATICA') || preg_match('/\bS\s?15\b/', $t))) {
            return 'MANS15';
        }

        $gruppi = self::gruppi($t);

        if ($gruppi === null) {
            return null;
        }

        if (str_contains($t, 'DALLA CORTE')) {
            return in_array($gruppi, [2, 3], true) ? 'DC'.$gruppi : null;
        }

        if (str_contains($t, 'CIMBALI')) {
            return in_array($gruppi, [2, 3], true) ? 'C'.$gruppi : null;
        }

        // Faema e Royal condividono i codici F (indicazione dell'ufficio,
        // 04/09/2026). Le altre marche NON si indovinano: vanno in
        // "da decidere".
        if (str_contains($t, 'FAEMA') || str_contains($t, 'ROYAL')) {
            return in_array($gruppi, [2, 3, 4], true) ? 'F'.$gruppi : null;
        }

        return null;
    }

    /**
     * Quanti gruppi dice il nome: "A/2", "A 2 GRUPPI", "2 GRUPPI", "A/3".
     */
    private static function gruppi(string $t): ?int
    {
        if (preg_match('/\bA\s?\/\s?([1-4])\b/', $t, $m)) {
            return (int) $m[1];
        }

        if (preg_match('/\b([1-4])\s*GRUPPI?\b/', $t, $m)) {
            return (int) $m[1];
        }

        return null;
    }

    /**
     * Ha i gruppi ma non una marca che sappiamo mappare: e' una macchina da
     * caffe', ma il codice lo deve dire una persona.
     */
    private static function sembraUnaMacchinaDaCaffe(?string $type): bool
    {
        return self::gruppi(mb_strtoupper((string) $type)) !== null;
    }

    /**
     * @param  array<int, array{modello: Material, codice: string}>  $proposte
     * @param  array<int, Material>  $daDecidere
     */
    private function mostra(array $proposte, array $daDecidere): void
    {
        if ($proposte === []) {
            $this->info('Nessun codice da proporre: i modelli riconoscibili sono gia a posto.');
        } else {
            $this->line('');
            $this->info(count($proposte).' modelli riconosciuti:');
            $this->table(
                ['Modello', 'Codice', 'Macchine'],
                array_map(fn (array $p) => [
                    mb_substr((string) $p['modello']->type, 0, 46),
                    $p['codice'],
                    $p['modello']->machine_units_count,
                ], $proposte),
            );
        }

        if ($daDecidere !== []) {
            $this->line('');
            $this->warn(count($daDecidere).' modelli hanno i gruppi ma una marca che non mappiamo: il codice va messo a mano.');
            $this->table(
                ['Modello', 'Macchine'],
                array_map(fn (Material $m) => [
                    mb_substr((string) $m->type, 0, 60),
                    $m->machine_units_count,
                ], $daDecidere),
            );
        }
    }
}
