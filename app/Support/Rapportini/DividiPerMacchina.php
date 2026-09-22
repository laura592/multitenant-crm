<?php

namespace App\Support\Rapportini;

use App\Models\MachineUnit;
use App\Models\MaintenanceSchedule;
use App\Models\ServiceReport;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * "Dividi per macchina" (22/09/2026): da un rapportino fatto per due
 * macchine se ne ricava un secondo per l'altra macchina.
 *
 * Il caso vero e' RT-2026-0807 di Hotel Olanda: due X20, un rapportino solo
 * con MANUTENZIONE X20 x2, sdoppiato a mano la mattina dopo in RT-2026-0833
 * rimasto senza firma. Qui il nuovo rapportino eredita tutto quello che vale
 * per la visita - cliente, data, tecnico, tipo, pagante e la stessa firma,
 * perche' il cliente aveva firmato il lavoro su tutte e due - e prende le
 * righe (o le quantita') che gli si assegnano. I due diventano della stessa
 * visita (visita_id).
 *
 * Mai su un rapportino gia' su Eureka: li' la scheda e' una, e il CRM la
 * rispecchia 1:1.
 */
class DividiPerMacchina
{
    /**
     * @param  array<string, float|int|string|null>  $daSpostare  id riga ServiceReportMaterial => quantita' che passa al nuovo
     */
    public static function esegui(
        ServiceReport $originale,
        MachineUnit $macchina,
        array $daSpostare,
        ?string $lavoroNuovo = null,
        ?string $lavoroOriginale = null,
    ): ServiceReport {
        if ($originale->isLocked()) {
            throw new InvalidArgumentException("Il rapportino {$originale->number} e' gia' su Eureka: non si divide.");
        }

        if ($macchina->id === $originale->machine_unit_id) {
            throw new InvalidArgumentException('Scegli una macchina diversa da quella del rapportino.');
        }

        return DB::transaction(function () use ($originale, $macchina, $daSpostare, $lavoroNuovo, $lavoroOriginale) {
            $visita = $originale->visita_id ?? (string) Str::uuid();

            // Gli impianti scelti sul rapportino che sono della macchina
            // nuova passano con lei, con le loro vie; gli altri restano.
            $impianti = LavaggioFields::resolveLavaggioImpiantiDefaults($originale);
            $pianiNuova = MaintenanceSchedule::query()
                ->whereIn('id', collect($impianti)->pluck('maintenance_schedule_id'))
                ->where('machine_unit_id', $macchina->id)
                ->pluck('id')
                ->all();
            [$impiantiNuovo, $impiantiRestano] = collect($impianti)
                ->partition(fn (array $riga) => in_array($riga['maintenance_schedule_id'], $pianiNuova, true))
                ->map(fn ($parte) => $parte->values()->all())
                ->all();
            $vieNuovo = self::vie($impiantiNuovo);

            $nuovo = ServiceReport::create([
                'tenant_id' => $originale->tenant_id,
                'visita_id' => $visita,
                'customer_id' => $originale->customer_id,
                'billing_customer_id' => $originale->billing_customer_id,
                'quote_id' => $originale->quote_id,
                'technician_id' => $originale->technician_id,
                'intervention_type' => $originale->intervention_type,
                'intervention_date' => $originale->intervention_date,
                'arrival_at' => $originale->arrival_at,
                'departure_at' => $originale->departure_at,
                'status' => $originale->status,
                'machine_unit_id' => $macchina->id,
                'machine_product_id' => $macchina->product_id,
                'machine_material_id' => $macchina->material_id,
                'machine_serial_number' => $macchina->serial_number,
                'problem_description' => $originale->problem_description,
                'work_performed' => filled($lavoroNuovo) ? $lavoroNuovo : $originale->work_performed,
                'notes' => $originale->notes,
                'lavaggio_vie_count' => $vieNuovo ?: null,
                // La stessa firma, con la sua data: il cliente l'ha data una
                // volta per il lavoro su tutte e due le macchine.
                'customer_signature_name' => $originale->customer_signature_name,
                'customer_signature_path' => $originale->customer_signature_path,
                'technician_signature_path' => $originale->technician_signature_path,
                'signed_at' => $originale->signed_at,
            ]);

            foreach ($originale->materialsUsed()->get() as $riga) {
                $quantita = min((float) ($daSpostare[$riga->id] ?? 0), (float) $riga->quantity);

                if ($quantita <= 0) {
                    continue;
                }

                if ($quantita >= (float) $riga->quantity) {
                    // Passa tutta: si sposta la riga, prezzo compreso.
                    $riga->update(['service_report_id' => $nuovo->id]);

                    continue;
                }

                // Passa una parte: MANUTENZIONE X20 x2 diventa x1 e x1, allo
                // stesso prezzo della riga di partenza.
                $riga->update(['quantity' => (float) $riga->quantity - $quantita]);
                $nuovo->materialsUsed()->create([
                    'material_id' => $riga->material_id,
                    'quantity' => $quantita,
                    'unit_cost_snapshot' => $riga->unit_cost_snapshot,
                    'notes' => $riga->notes,
                ]);
            }

            $aggiorna = ['visita_id' => $visita];

            if (filled($lavoroOriginale)) {
                $aggiorna['work_performed'] = $lavoroOriginale;
            }

            // Le vie degli impianti passati al nuovo non sono piu' di questo.
            if ($vieNuovo) {
                $aggiorna['lavaggio_vie_count'] = max(0, (int) $originale->lavaggio_vie_count - $vieNuovo) ?: null;
            }

            $originale->update($aggiorna);

            LavaggioFields::syncLavaggioImpianti($nuovo, $impiantiNuovo);
            LavaggioFields::syncLavaggioImpianti($originale->fresh(), $impiantiRestano);

            return $nuovo;
        });
    }

    /**
     * Quante righe passano al nuovo se nessuno decide altro: di una riga da
     * 2 o piu' ne passa meta' (MANUTENZIONE X20 x2 -> x1), di una riga
     * singola niente.
     */
    public static function proposta(float $quantita): float
    {
        return $quantita >= 2 ? floor($quantita / 2) : 0;
    }

    /**
     * @param  array<int, array{maintenance_schedule_id: string, lines_washed: ?int}>  $impianti
     */
    private static function vie(array $impianti): int
    {
        return (int) collect($impianti)->sum(fn (array $riga) => (int) ($riga['lines_washed'] ?? 0));
    }
}
