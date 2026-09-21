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
 */
final class AbbinamentoRapportini
{
    public const UNICO = 'unico';

    public const AMBIGUO = 'ambiguo';

    public const NESSUNO = 'nessuno';

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
