<?php

namespace App\Support\Gestionale;

use App\Models\ServiceReport;
use Illuminate\Support\Collection;

/**
 * Riconosce quando un rapportino compilato nel CRM e una scheda lavoro
 * importata da Eureka documentano lo STESSO intervento.
 *
 * Il problema: l'ufficio inserisce in Eureka interventi che il tecnico ha
 * gia' registrato qui, e dopo ogni import lo stesso lavoro compare due
 * volte. La data da sola non basta — un cliente con piu' macchine ne riceve
 * due nello stesso giorno — e nemmeno la matricola, perche' sulla stessa
 * macchina si torna piu' volte in settimane diverse.
 *
 * Si confrontano quindi tre cose, come chiesto dall'ufficio: cliente e
 * giorno per restringere il campo, poi MATRICOLA e ARTICOLI USATI per
 * decidere. Due interventi distinti sulla stessa macchina lo stesso giorno
 * quasi mai consumano gli stessi ricambi.
 *
 * IMPORTANTE: qui non si decide niente. La classe assegna solo un livello di
 * confidenza; unire due rapportini cancella dati firmati dal cliente ed e'
 * irreversibile, quindi la conferma resta a una persona.
 */
class ConfrontoRapportini
{
    public const CERTO = 'stessa macchina e stessi ricambi';

    public const PROBABILE = 'stessa macchina, stesso giorno';

    public const DA_VERIFICARE = 'stesso cliente e giorno, matricola assente';

    /**
     * Confidenza dell'abbinamento fra due rapportini, o null se non sono
     * confrontabili (cliente o giorno diversi).
     */
    public static function confidenza(ServiceReport $nostro, ServiceReport $importato): ?string
    {
        if ($nostro->customer_id === null || $nostro->customer_id !== $importato->customer_id) {
            return null;
        }

        if ($nostro->intervention_date === null || ! $nostro->intervention_date->isSameDay($importato->intervention_date)) {
            return null;
        }

        $matrNostra = self::matricola($nostro);
        $matrLoro = self::matricola($importato);

        // Senza matricola su almeno uno dei due resta solo cliente + giorno:
        // troppo poco per dire che sono lo stesso intervento.
        if ($matrNostra === '' || $matrLoro === '') {
            return self::DA_VERIFICARE;
        }

        if ($matrNostra !== $matrLoro) {
            // Macchine diverse dello stesso cliente nello stesso giorno: e'
            // il caso normale di una visita con piu' interventi, NON un
            // doppione.
            return null;
        }

        return self::articoliInComune($nostro, $importato) ? self::CERTO : self::PROBABILE;
    }

    /**
     * True se i due rapportini condividono almeno un articolo.
     *
     * Basta uno: le due registrazioni raramente elencano gli stessi
     * identici ricambi — l'ufficio ne salta qualcuno, il tecnico ne aggiunge
     * — ma un ricambio in comune sullo stesso giorno e sulla stessa macchina
     * e' un indizio forte.
     */
    public static function articoliInComune(ServiceReport $a, ServiceReport $b): bool
    {
        $ma = self::materiali($a);

        return $ma->isNotEmpty() && $ma->intersect(self::materiali($b))->isNotEmpty();
    }

    /**
     * Quanti articoli condividono: serve a scegliere fra piu' candidati.
     *
     * Eureka spezza spesso un intervento del tecnico in piu' schede, una per
     * macchina (RT-2026-0618: disinstallazione e installazione finite su
     * SL-695 e SL-696). Il rapportino nostro somiglia allora a due schede
     * dello stesso cliente e giorno, e a decidere e' quanti articoli ha in
     * comune con l'una e con l'altra.
     */
    public static function quantiArticoliInComune(ServiceReport $a, ServiceReport $b): int
    {
        return self::materiali($a)->intersect(self::materiali($b))->count();
    }

    /**
     * Quanto vale un livello di confidenza, per ordinare i candidati.
     *
     * Piu' alto = piu' affidabile. Non e' un punteggio da mostrare: serve
     * solo a mettere in fila due candidati e vedere se uno stacca l'altro.
     */
    public static function peso(?string $motivo): int
    {
        return match ($motivo) {
            self::CERTO => 3,
            self::PROBABILE => 2,
            self::DA_VERIFICARE => 1,
            default => 0,
        };
    }

    /** @return Collection<int, string> */
    private static function materiali(ServiceReport $r): Collection
    {
        return $r->materialsUsed
            ->pluck('material_id')
            ->filter()
            ->unique()
            ->values();
    }

    /**
     * Matricola normalizzata, o stringa vuota se non c'e'.
     *
     * Le schede di Eureka portano spesso una matricola di soli zeri
     * ("0000000", "000000", ...): non e' un numero di serie, e' il campo
     * lasciato in bianco dall'ufficio. Trattarla come un valore vero
     * significherebbe dichiarare diverse due macchine per un segnaposto.
     */
    public static function matricola(ServiceReport $r): string
    {
        $matricola = trim((string) ($r->machineUnit->serial_number ?? $r->machine_serial_number ?? ''));

        if ($matricola === '' || trim($matricola, '0') === '') {
            return '';
        }

        // Stessa normalizzazione dell'import: "BRL 003 020..." e "BRL003020..."
        // sono la stessa macchina, non due.
        return \App\Models\MachineUnit::chiaveMatricola($matricola);
    }

    /**
     * Ripulisce un testo importato dalla boilerplate di Eureka.
     *
     * L'import scrive nelle note "Numero documento Eureka: NNN" e nient'altro:
     * alla verifica sono cosi' tutte e 3.703 le schede importate. E' un
     * riferimento che il CRM tiene gia' in gestionale_number, quindi non e'
     * una differenza da segnalare ne' qualcosa da fondere nelle note del
     * tecnico — sarebbe solo rumore su ogni riga.
     */
    public static function testoUtile(?string $testo): string
    {
        $testo = trim((string) $testo);
        $testo = preg_replace('/^\s*Numero documento Eureka:\s*\S+\s*$/mu', '', $testo);

        return trim((string) $testo);
    }
}
