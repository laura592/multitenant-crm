<?php

namespace App\Support\Gestionale;

use App\Models\ServiceReport;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Dopo un import da Eureka: ogni scheda nuova che e' un rapportino gia' fatto
 * nel CRM si unisce a quello, e i numeri restano progressivi.
 *
 * Regola dell'ufficio (21/09/2026), dopo i 58 rapportini recuperati quel
 * giorno di cui 35 erano doppioni: "quando fai un import dal gestionale devi
 * verificare e fare i combinamenti con il CRM", e "i numeri devono andare
 * progressivi senza doppioni".
 *
 * Per ogni scheda creata dall'import (AbbinamentoRapportini):
 * - UNICO: e' lo stesso intervento di un rapportino del CRM. Si fa quello che
 *   fa "Sono lo stesso intervento" (ServiceReport::confermaDuplicato: il
 *   rapportino del tecnico prende numero Eureka, articoli e stato), poi la
 *   copia si cancella del tutto — nessuno l'ha mai vista.
 * - AMBIGUO: piu' candidati a pari merito. Si propone il doppione sul primo,
 *   perche' compaia nella schermata di confronto: decide una persona.
 * - NESSUNO: e' un intervento che nel CRM non c'era. Resta.
 *
 * Poi i numeri. Si decide PRIMA di toccare qualcosa se si possono
 * ricompattare: serve che le schede dell'import siano in coda alla
 * numerazione (nessun rapportino creato dopo, con un numero gia' usato e
 * magari stampato) e che nessuna sia stata mandata per mail.
 * - Si puo': le copie unite si cancellano del tutto e le schede rimaste si
 *   rinumerano di seguito. Numeri progressivi, senza buchi e senza doppioni.
 * - Non si puo': le copie unite restano archiviate col loro numero, come fa
 *   "Sono lo stesso intervento" — meglio un numero "eliminato" che un buco
 *   (regola dell'ufficio, caso del Vidi) — e i numeri non si toccano.
 */
final class RiabbinaImportati
{
    /**
     * @param  Collection<int, ServiceReport>  $schede  le schede create dall'import, di un solo tenant
     * @return array{uniti: array<int, array{scheda: string, rapportino: string, motivo: string}>, ambigui: array<int, array{scheda: string, candidati: string}>, nuovi: int, rinumerati: array<string, string>, rinumerazione: string}
     */
    public static function esegui(Collection $schede, bool $prova = false): array
    {
        $esito = ['uniti' => [], 'ambigui' => [], 'nuovi' => 0, 'rinumerati' => [], 'rinumerazione' => 'non serve'];

        if ($schede->isEmpty()) {
            return $esito;
        }

        $schede = $schede->sortBy(fn (ServiceReport $s) => self::progressivo($s->number))->values();
        $numeriIniziali = $schede->pluck('number', 'id')->all();
        $tenantId = (string) $schede->first()->tenant_id;
        $daCancellare = [];

        [$compattabile, $perche] = self::compattabile($schede, $tenantId);
        $esito['rinumerazione'] = $compattabile ? 'non serve' : $perche;

        // Prima si decide tutto, a schede tutte presenti: cosi' due schede
        // gemelle si vedono a vicenda e l'esito non dipende dall'ordine.
        $decisioni = $schede->mapWithKeys(fn (ServiceReport $s) => [$s->id => AbbinamentoRapportini::perScheda($s)]);
        $nelGiro = $schede->pluck('id')->flip();
        $gestite = [];

        // Gli ambigui con un solo rapportino candidato si guardano dal suo
        // lato: piu' schede per un rapportino sono quasi sempre una visita
        // divisa per impianto o un doppione scritto su Eureka.
        $rapportiniConPiuSchede = $schede
            ->map(fn (ServiceReport $s) => $decisioni[$s->id])
            ->filter(fn (array $d) => $d['esito'] === AbbinamentoRapportini::AMBIGUO && $d['candidati']->count() === 1)
            ->map(fn (array $d) => $d['candidati']->first())
            ->unique('id');

        foreach ($rapportiniConPiuSchede as $nostro) {
            $candidate = self::schedeCandidate($nostro);
            $r = AbbinamentoRapportini::piuSchede($nostro, $candidate);

            if ($r['esito'] === AbbinamentoRapportini::AMBIGUO) {
                continue;
            }

            // Il rapportino del tecnico si lega alla scheda principale; le
            // altre schede restano, ognuna col suo rapportino, come su Eureka.
            $altre = $r['altre']->pluck('gestionale_number')->implode(', ');
            $esito['uniti'][] = [
                'scheda' => (string) $r['principale']->gestionale_number,
                'rapportino' => $nostro->number,
                'motivo' => $r['esito'] === AbbinamentoRapportini::DIVISA
                    ? "su Eureka la visita e' divisa per impianto: {$altre} tiene il suo rapportino"
                    : "su Eureka ci sono due schede uguali: {$altre} tiene il suo rapportino",
            ];

            $gestite[$r['principale']->id] = true;
            if (isset($nelGiro[$r['principale']->id])) {
                $daCancellare[] = $r['principale']->id;
            }

            foreach ($r['altre'] as $altra) {
                $gestite[$altra->id] = true;
                if (isset($nelGiro[$altra->id])) {
                    $esito['nuovi']++;
                }
            }

            if (! $prova) {
                self::unisci($nostro, $r['principale'], $esito['uniti'][array_key_last($esito['uniti'])]['motivo'], cancellaCopia: $compattabile && isset($nelGiro[$r['principale']->id]));
            }
        }

        foreach ($schede as $scheda) {
            if (isset($gestite[$scheda->id])) {
                continue;
            }

            $d = $decisioni[$scheda->id];

            if ($d['esito'] === AbbinamentoRapportini::UNICO) {
                $esito['uniti'][] = ['scheda' => (string) $scheda->gestionale_number, 'rapportino' => $d['rapportino']->number, 'motivo' => (string) $d['motivo']];
                $daCancellare[] = $scheda->id;

                if (! $prova) {
                    self::unisci($d['rapportino'], $scheda, (string) $d['motivo'], cancellaCopia: $compattabile);
                }

                continue;
            }

            if ($d['esito'] === AbbinamentoRapportini::AMBIGUO) {
                $esito['ambigui'][] = ['scheda' => (string) $scheda->gestionale_number, 'candidati' => $d['candidati']->pluck('number')->implode(', ')];

                if (! $prova) {
                    self::proponi($d['candidati']->first(), $scheda);
                }

                continue;
            }

            $esito['nuovi']++;
        }

        if ($compattabile) {
            $restano = $schede->reject(fn (ServiceReport $s) => in_array($s->id, $daCancellare, true))->values();
            $esito['rinumerati'] = self::rinumera($restano, $numeriIniziali, $prova);
            $esito['rinumerazione'] = $esito['rinumerati'] === [] ? 'non serve' : 'ricompattati';
        }

        return $esito;
    }

    /**
     * Si possono ricompattare i numeri di queste schede?
     *
     * @return array{0: bool, 1: string} [si', perche' no]
     */
    private static function compattabile(Collection $schede, string $tenantId): array
    {
        if ($schede->contains(fn (ServiceReport $s) => $s->emails()->exists())) {
            return [false, 'numeri non toccati: qualche scheda importata e\' gia\' stata mandata per mail'];
        }

        foreach ($schede->groupBy(fn (ServiceReport $s) => self::prefisso($s->number)) as $prefisso => $gruppo) {
            $minimo = $gruppo->min(fn (ServiceReport $s) => self::progressivo($s->number));

            $dopo = ServiceReport::withoutGlobalScopes()
                ->where('tenant_id', $tenantId)
                ->where('number', 'like', $prefisso.'%')
                ->whereNotIn('id', $schede->pluck('id')->all())
                ->pluck('number')
                ->filter(fn (string $n) => self::progressivo($n) > $minimo)
                ->sortBy(fn (string $n) => self::progressivo($n));

            if ($dopo->isNotEmpty()) {
                return [false, "numeri non toccati: dopo le schede importate c'e' gia' {$dopo->first()}, e il suo numero non si cambia"];
            }
        }

        return [true, ''];
    }

    private static function unisci(ServiceReport $nostro, ServiceReport $copia, string $motivo, bool $cancellaCopia): void
    {
        DB::transaction(function () use ($nostro, $copia, $motivo, $cancellaCopia) {
            $nostro->forceFill(['duplicato_suggerito_id' => $copia->id, 'duplicato_suggerito_motivo' => $motivo])->saveQuietly();
            $nostro->unsetRelation('duplicatoSuggerito');
            $nostro->confermaDuplicato();

            // Le proposte che puntavano la copia non hanno piu' niente da
            // decidere. confermaDuplicato() l'ha archiviata; se i numeri si
            // ricompattano va via del tutto, altrimenti resta a tenere il
            // suo numero.
            ServiceReport::withoutGlobalScopes()->where('duplicato_suggerito_id', $copia->id)
                ->update(['duplicato_suggerito_id' => null, 'duplicato_suggerito_motivo' => null]);

            if ($cancellaCopia) {
                ServiceReport::withoutGlobalScopes()->whereKey($copia->id)->first()?->forceDelete();
            }

            RegistroSync::movimento('doppioni', 'unito all\'import', [
                'rapportino' => $nostro->number,
                'scheda' => $nostro->gestionale_number,
                'motivo' => $motivo,
            ]);
        });
    }

    /**
     * Le schede Eureka ancora senza rapportino che somigliano a questo
     * rapportino, anche fuori dal giro: la gemella puo' essere entrata prima.
     *
     * @return Collection<int, ServiceReport>
     */
    private static function schedeCandidate(ServiceReport $nostro): Collection
    {
        return ServiceReport::withoutGlobalScopes()
            ->whereNull('deleted_at')
            ->where('tenant_id', $nostro->tenant_id)
            ->where('source', ServiceReport::SOURCE_EUREKA)
            ->where('customer_id', $nostro->customer_id)
            ->whereDate('intervention_date', $nostro->intervention_date->toDateString())
            ->with(['machineUnit', 'materialsUsed.material'])
            ->get()
            ->filter(fn (ServiceReport $s) => ConfrontoRapportini::confidenza($nostro, $s) !== null)
            ->values();
    }

    private static function proponi(?ServiceReport $nostro, ServiceReport $copia): void
    {
        if (! $nostro || $nostro->duplicato_suggerito_id !== null) {
            return;
        }

        $nostro->forceFill([
            'duplicato_suggerito_id' => $copia->id,
            'duplicato_suggerito_motivo' => 'ambiguo: piu\' schede Eureka lo stesso giorno',
        ])->saveQuietly();

        RegistroSync::movimento('doppioni', 'ambiguo, da decidere', ['rapportino' => $nostro->number, 'scheda' => $copia->gestionale_number]);
    }

    /**
     * Le schede rimaste prendono, in ordine, i numeri piu' bassi fra quelli
     * che l'import aveva usato: le copie cancellate non lasciano buchi.
     * Chiamata solo se compattabile().
     *
     * @param  array<string, string>  $numeriIniziali  id => numero, di tutte le schede dell'import
     * @return array<string, string> vecchio => nuovo
     */
    private static function rinumera(Collection $restano, array $numeriIniziali, bool $prova): array
    {
        $cambi = [];

        foreach (collect($numeriIniziali)->groupBy(fn (string $n) => self::prefisso($n), preserveKeys: true) as $prefisso => $numeri) {
            $minimo = $numeri->map(fn (string $n) => self::progressivo($n))->min();

            $mie = $restano->filter(fn (ServiceReport $s) => self::prefisso($numeriIniziali[$s->id]) === $prefisso)
                ->sortBy(fn (ServiceReport $s) => self::progressivo($numeriIniziali[$s->id]))->values();

            foreach ($mie as $i => $scheda) {
                $nuovo = $prefisso.str_pad((string) ($minimo + $i), 4, '0', STR_PAD_LEFT);

                if ($nuovo === $numeriIniziali[$scheda->id]) {
                    continue;
                }

                $cambi[$numeriIniziali[$scheda->id]] = $nuovo;

                if (! $prova) {
                    // Si scende sempre, in ordine: il numero di arrivo e' di
                    // una copia cancellata o si e' liberato al giro prima.
                    ServiceReport::withoutGlobalScopes()->whereKey($scheda->id)->toBase()->update(['number' => $nuovo]);
                    RegistroSync::movimento('doppioni', 'rinumerato', ['da' => $numeriIniziali[$scheda->id], 'a' => $nuovo]);
                }
            }
        }

        return $cambi;
    }

    private static function prefisso(string $numero): string
    {
        return substr($numero, 0, -4);
    }

    private static function progressivo(?string $numero): int
    {
        return (int) substr((string) $numero, -4);
    }
}
