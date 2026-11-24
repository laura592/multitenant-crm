<?php

namespace App\Support\Controlli;

use App\Models\Customer;
use Illuminate\Support\Collection;

/**
 * Le anagrafiche che sembrano la stessa cosa scritta due volte.
 *
 * IL PUNTO DELICATO: la stessa P.IVA su piu' anagrafiche quasi sempre NON
 * e' un errore. Airest ha diciannove punti vendita negli aeroporti di
 * Venezia e Treviso, Gromart quattro negozi, A.M.G. tre locali: in Eureka
 * un'anagrafica per destinazione e' il modo giusto di tenerli, e segnalarli
 * come doppioni vorrebbe dire sommergere di rumore l'unica cosa che conta.
 * Su 22 gruppi con P.IVA ripetuta (24/11/2026, 65 anagrafiche) solo una
 * manciata erano doppioni veri.
 *
 * Quello che distingue Airest da "Hotel Serena SAS" / "Hotel Serena S.R.L."
 * non e' la P.IVA: e' che i punti vendita hanno NOMI DIVERSI (Culto, Sala
 * Vip, Bricocaffe') mentre il doppione ha lo stesso nome scritto in due
 * modi, nello stesso posto. Quindi si segnala solo quando coincidono tutte
 * e tre le cose: P.IVA, nome (a meno della forma societaria) e citta'.
 *
 * Resta un filtro, non un oracolo: dice "guarda qui", non "cancella".
 */
class AnagraficheDoppie
{
    /** Forme societarie e punteggiatura: rumore quando si confrontano due nomi. */
    private const RUMORE = [
        's.r.l.', 'srl', 's.a.s.', 'sas', 's.n.c.', 'snc', 's.p.a.', 'spa',
        'srls', 's.r.l.s.', 'societa', 'società', 'unipersonale', 'di', '&', 'c.',
    ];

    public static function normalizza(string $testo): string
    {
        $t = mb_strtolower(trim($testo));
        $t = str_replace(["'", '"', '.', ',', '-', '(', ')', '/'], ' ', $t);

        $parole = array_filter(
            preg_split('/\s+/', $t) ?: [],
            fn (string $p) => $p !== '' && ! in_array($p, self::RUMORE, true),
        );

        return implode('', $parole);
    }

    public static function normalizzaPiva(?string $piva): string
    {
        return mb_strtoupper(str_replace([' ', '.', '-'], '', (string) $piva));
    }

    /**
     * I gruppi di anagrafiche che sembrano la stessa azienda.
     *
     * @return Collection<int, Collection<int, Customer>>
     */
    public static function gruppi(): Collection
    {
        return Customer::query()
            ->whereNotNull('vat_number')
            ->whereRaw("TRIM(vat_number) <> ''")
            ->whereNotNull('company_name')
            ->whereNotNull('city')
            ->whereRaw("TRIM(city) <> ''")
            ->get(['id', 'company_name', 'city', 'vat_number', 'gestionale_code'])
            // La chiave e' P.IVA + nome + citta' tutti e tre normalizzati.
            // Raggruppare (e non confrontare a coppie) non e' un dettaglio:
            // cinque "Culto Caffe' & Cioccolato" identici a Tessera fanno
            // dieci coppie, e la pagina direbbe "dieci doppioni" dove il
            // problema da guardare e' uno.
            ->groupBy(fn (Customer $c) => implode('|', [
                self::normalizzaPiva($c->vat_number),
                self::normalizza((string) $c->company_name),
                self::normalizza((string) $c->city),
            ]))
            ->filter(fn ($g) => $g->count() > 1)
            ->map(fn ($g) => $g->toBase())
            ->values()
            ->toBase();
    }

    /**
     * I codici fiscali di persona (16 caratteri) su piu' anagrafiche.
     *
     * Qui il doppio e' piu' spesso legittimo che nelle P.IVA — lo stesso
     * titolare con due attivita', come Zamuner col bar e la pasticceria —
     * ma vale la pena saperlo, perche' e' anche il modo in cui un codice
     * finisce incollato sull'anagrafica sbagliata.
     *
     * @return Collection<array-key, Collection<int, Customer>>
     */
    public static function codiciFiscaliRipetuti(): Collection
    {
        return Customer::query()
            ->whereNotNull('tax_code')
            ->whereRaw('CHAR_LENGTH(TRIM(tax_code)) = 16')
            ->get(['id', 'company_name', 'city', 'tax_code'])
            ->groupBy(fn (Customer $c) => mb_strtoupper(trim((string) $c->tax_code)))
            ->filter(fn ($g) => $g->count() > 1)
            ->map(fn ($g) => $g->toBase())
            ->toBase();
    }
}
