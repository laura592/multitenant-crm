<?php

namespace App\Support\Gestionale;

use App\Models\ServiceReport;
use Illuminate\Support\Collection;

/**
 * Una scheda che arriva da Eureka e' un rapportino che il CRM ha gia'?
 *
 * Succede ogni volta che il tecnico compila il rapportino nel CRM e l'ufficio
 * lo riscrive a mano su Eureka invece di premere "Invia a gestionale": l'import
 * non puo' saperlo dal numero e, finche' non lo capiva, creava un secondo
 * rapportino (Hotel Cambridge, 21/09/2026: RT-2026-0792 del tecnico e
 * RT-2026-0822 importato, stesso intervento). Il riconoscimento c'era gia'
 * (ConfrontoRapportini), ma girava solo di notte come proposta da confermare.
 *
 * Qui si decide nel momento dell'import:
 *
 * - UNICO: un solo rapportino del CRM somiglia alla scheda, e per quel
 *   rapportino questa e' la scheda che somiglia di piu', staccando le altre.
 *   Stesso intervento: si aggiorna il rapportino del CRM.
 * - AMBIGUO: piu' candidati a pari merito, da una parte o dall'altra
 *   (Cambridge 06/09: un rapportino del CRM e due schede Eureka uguali). Qui
 *   decide una persona.
 * - NESSUNO: nessun rapportino del CRM quel giorno per quel cliente. E' un
 *   intervento che esiste solo su Eureka.
 *
 * Un AMBIGUO si guarda poi dal lato del rapportino (piuSchede()): su Eureka
 * ogni scheda ha un impianto solo, e il tecnico fa un rapportino per visita.
 * Due schede per un rapportino sono quasi sempre una di queste due cose:
 * - DIVISA: la visita toccava due impianti e l'ufficio ha fatto una scheda
 *   per impianto (Strana Coppia 18/09/2026: SL-767 impianto acqua, SL-766
 *   spina 5 vie, insieme = RT-2026-0804);
 * - DOPPIONE_EUREKA: due schede con articoli identici (Cambridge 06/09:
 *   SL-747 e SL-753, CHIORD tutte e due).
 *
 * In tutti e due i casi Eureka non si tocca: e' la fonte, e quello che c'e'
 * li' non si cancella mai (indicazione dell'ufficio, 21/09/2026). Il CRM gli
 * va dietro, una scheda un rapportino: quello del tecnico si lega a una
 * scheda, le altre tengono il loro.
 */
final class AbbinamentoRapportini
{
    public const UNICO = 'unico';

    public const AMBIGUO = 'ambiguo';

    public const NESSUNO = 'nessuno';

    public const DIVISA = 'divisa';

    public const DOPPIONE_EUREKA = 'doppione_eureka';

    /**
     * @return array{esito: string, rapportino: ?ServiceReport, motivo: ?string, candidati: Collection<int, ServiceReport>}
     */
    public static function perScheda(ServiceReport $scheda): array
    {
        $nostri = self::rapportiniDelCrmDelGiorno($scheda)
            ->map(fn (ServiceReport $n) => [
                'rapportino' => $n,
                'motivo' => $motivo = ConfrontoRapportini::confidenza($n, $scheda),
                'peso' => ConfrontoRapportini::peso($motivo),
                'articoli' => ConfrontoRapportini::quantiArticoliInComune($n, $scheda),
            ])
            ->filter(fn (array $c) => $c['motivo'] !== null)
            ->sortByDesc(fn (array $c) => [$c['peso'], $c['articoli']])
            ->values();

        if ($nostri->isEmpty()) {
            return ['esito' => self::NESSUNO, 'rapportino' => null, 'motivo' => null, 'candidati' => collect()];
        }

        $primo = $nostri[0];

        // Due rapportini del CRM somigliano alla scheda allo stesso modo.
        if (isset($nostri[1]) && [$nostri[1]['peso'], $nostri[1]['articoli']] === [$primo['peso'], $primo['articoli']]) {
            return ['esito' => self::AMBIGUO, 'rapportino' => null, 'motivo' => null, 'candidati' => $nostri->pluck('rapportino')];
        }

        // E dall'altra parte: per quel rapportino, questa scheda deve staccare
        // le altre schede Eureka dello stesso giorno ancora senza rapportino.
        foreach (self::altreSchedeDelGiorno($scheda) as $altra) {
            $motivo = ConfrontoRapportini::confidenza($primo['rapportino'], $altra);

            if ($motivo !== null
                && [ConfrontoRapportini::peso($motivo), ConfrontoRapportini::quantiArticoliInComune($primo['rapportino'], $altra)]
                    >= [$primo['peso'], $primo['articoli']]) {
                return ['esito' => self::AMBIGUO, 'rapportino' => null, 'motivo' => null, 'candidati' => $nostri->pluck('rapportino')];
            }
        }

        return ['esito' => self::UNICO, 'rapportino' => $primo['rapportino'], 'motivo' => $primo['motivo'], 'candidati' => $nostri->pluck('rapportino')];
    }

    /**
     * Un rapportino del CRM e le sue schede candidate: divisa per impianto,
     * doppione su Eureka, o davvero ambiguo?
     *
     * - DOPPIONE_EUREKA: tutte le schede hanno gli stessi articoli, stesse
     *   quantita'. Si tiene la prima scritta (id Eureka piu' basso).
     * - DIVISA: ogni scheda e' su un impianto diverso, ognuna ha almeno un
     *   articolo del rapportino, e insieme li coprono tutti. Se una scheda
     *   avesse articoli che col rapportino non c'entrano, o se ne mancasse
     *   qualcuno, non e' la stessa visita divisa: decide una persona.
     *   Principale e' quella con piu' articoli in comune.
     *
     * @param  Collection<int, ServiceReport>  $schede
     * @return array{esito: string, principale: ?ServiceReport, altre: Collection<int, ServiceReport>}
     */
    public static function piuSchede(ServiceReport $nostro, Collection $schede): array
    {
        $ambiguo = ['esito' => self::AMBIGUO, 'principale' => null, 'altre' => collect()];

        if ($schede->count() < 2) {
            return $ambiguo;
        }

        $perId = $schede->sortBy(fn (ServiceReport $s) => (int) $s->eureka_service_report_id)->values();

        $firme = $perId->map(fn (ServiceReport $s) => self::firmaArticoli($s))->unique();

        if ($firme->count() === 1 && $firme->first() !== '') {
            return ['esito' => self::DOPPIONE_EUREKA, 'principale' => $perId->first(), 'altre' => $perId->slice(1)->values()];
        }

        $impianti = $perId->map(fn (ServiceReport $s) => self::impianto($s));
        $codiciNostri = self::codici($nostro);

        $diviso = $impianti->doesntContain(null)
            && $impianti->unique()->count() === $perId->count()
            && $perId->every(fn (ServiceReport $s) => self::codici($s)->intersect($codiciNostri)->isNotEmpty())
            && $codiciNostri->diff($perId->flatMap(fn (ServiceReport $s) => self::codici($s)))->isEmpty();

        if (! $diviso) {
            return $ambiguo;
        }

        // Il rapportino del tecnico va sulla scheda del SUO impianto: la
        // macchina che ha indicato dice su cosa ha lavorato, e il resto e'
        // l'altro impianto finito per errore nello stesso rapportino
        // (Strana Coppia 18/09/2026: RT-2026-0804 e' sulla spina, e la spina
        // e' SL-766 — l'acqua e' SL-767, e ha il suo rapportino). A parita',
        // piu' articoli in comune, poi lo stesso tipo d'intervento, poi la
        // prima scritta.
        $principale = $perId
            ->sortBy(fn (ServiceReport $s) => [
                self::stessoImpianto($nostro, $s) ? 0 : 1,
                -self::codici($s)->intersect($codiciNostri)->count(),
                $s->intervention_type === $nostro->intervention_type ? 0 : 1,
                (int) $s->eureka_service_report_id,
            ])
            ->first();

        return [
            'esito' => self::DIVISA,
            'principale' => $principale,
            'altre' => $perId->reject(fn (ServiceReport $s) => $s->is($principale))->values(),
        ];
    }

    /**
     * La scheda e' sull'impianto della macchina del rapportino? Col modello a
     * catalogo quando c'e'; altrimenti dal nome, che per gli impianti dice
     * gia' tutto ("Impianto Spina (birra+vino...)" contro "IMPIANTO ALLA
     * SPINA 5 VIE" e "IMPIANTO ACQUA").
     */
    private static function stessoImpianto(ServiceReport $nostro, ServiceReport $scheda): bool
    {
        $macchina = $nostro->machineUnit;

        if (! $macchina) {
            return false;
        }

        if ($macchina->material_id && $macchina->material_id === $scheda->machine_material_id) {
            return true;
        }

        $parole = fn (?string $testo) => collect(preg_split('/[^\p{L}]+/u', mb_strtolower((string) $testo)))
            ->filter(fn (string $p) => mb_strlen($p) >= 4)
            ->reject(fn (string $p) => in_array($p, ['impianto', 'macchina', 'alla', 'gruppi'], true));

        $nomeScheda = $scheda->machineMaterial?->type ?? $scheda->machineMaterial?->description ?? '';

        return $parole($macchina->model_name)->intersect($parole($nomeScheda))->isNotEmpty();
    }

    /** Su che impianto e' la scheda: l'articolo installato di Eureka, o la matricola vera. */
    private static function impianto(ServiceReport $s): ?string
    {
        if ($s->machine_material_id) {
            return 'art:'.$s->machine_material_id;
        }

        $matricola = ConfrontoRapportini::matricola($s);

        return $matricola !== '' ? 'mat:'.$matricola : null;
    }

    /** @return Collection<int, string> i codici articolo della scheda */
    private static function codici(ServiceReport $r): Collection
    {
        return $r->materialsUsed->map(fn ($m) => $m->material?->code)->filter()->unique()->values();
    }

    /** "CHIORD x1|ORE x2": articoli e quantita', per riconoscere due schede identiche. */
    private static function firmaArticoli(ServiceReport $r): string
    {
        return $r->materialsUsed
            ->map(fn ($m) => ($m->material?->code ?? '?').' x'.rtrim(rtrim(number_format((float) $m->quantity, 3, '.', ''), '0'), '.'))
            ->sort()
            ->implode('|');
    }

    /** I rapportini fatti nel CRM, non ancora legati a Eureka, dello stesso cliente e giorno. */
    private static function rapportiniDelCrmDelGiorno(ServiceReport $scheda): Collection
    {
        if (! $scheda->customer_id || ! $scheda->intervention_date) {
            return collect();
        }

        return ServiceReport::query()
            ->withoutGlobalScopes()
            ->whereNull('deleted_at')
            ->where('tenant_id', $scheda->tenant_id)
            ->where('source', ServiceReport::SOURCE_MANUALE)
            ->whereNull('eureka_service_report_id')
            ->where('customer_id', $scheda->customer_id)
            ->whereDate('intervention_date', $scheda->intervention_date->toDateString())
            ->whereKeyNot($scheda->getKey())
            ->with(['machineUnit', 'materialsUsed.material'])
            ->get();
    }

    /** Le altre schede Eureka dello stesso cliente e giorno. */
    private static function altreSchedeDelGiorno(ServiceReport $scheda): Collection
    {
        return ServiceReport::query()
            ->withoutGlobalScopes()
            ->whereNull('deleted_at')
            ->where('tenant_id', $scheda->tenant_id)
            ->where('source', ServiceReport::SOURCE_EUREKA)
            ->where('customer_id', $scheda->customer_id)
            ->whereDate('intervention_date', $scheda->intervention_date->toDateString())
            ->whereKeyNot($scheda->getKey())
            ->with(['machineUnit', 'materialsUsed.material'])
            ->get();
    }
}
