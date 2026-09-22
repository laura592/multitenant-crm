<?php

namespace App\Support\Macchine;

use App\Models\MachineUnit;
use App\Models\MachineUnitPlacement;
use App\Support\DisplayName;
use Illuminate\Support\Facades\DB;

/**
 * Toglie dallo storico un posizionamento sbagliato, anche non l'ultimo
 * (22/09/2026: "Annulla ultimo spostamento" non bastava).
 *
 * Lo storico si ricuce: MachineUnit::moveTo() chiude la posizione vecchia
 * nello stesso istante in cui apre la nuova, quindi la posizione subito
 * prima (se attaccata) si allunga fino a dove finiva quella tolta. Se quella
 * tolta era la posizione attuale, la precedente torna aperta. Se prima c'era
 * il magazzino (nessuna posizione attaccata), resta il magazzino.
 */
class EliminaPosizionamento
{
    public static function precedente(MachineUnitPlacement $posizionamento): ?MachineUnitPlacement
    {
        return MachineUnitPlacement::query()
            ->where('machine_unit_id', $posizionamento->machine_unit_id)
            ->whereKeyNot($posizionamento->getKey())
            ->whereBetween('removed_at', [
                $posizionamento->placed_at->copy()->subMinute(),
                $posizionamento->placed_at->copy()->addMinute(),
            ])
            ->latest('removed_at')
            ->first();
    }

    /**
     * Cosa succede eliminando questa riga, detto in chiaro per la conferma.
     */
    public static function descrizione(MachineUnitPlacement $posizionamento): string
    {
        $precedente = static::precedente($posizionamento);
        $dove = $precedente?->customer ? DisplayName::customerOption($precedente->customer) : 'in magazzino';

        if ($posizionamento->removed_at === null) {
            return "La macchina torna {$dove}".($precedente ? ', come prima di questo spostamento.' : '.');
        }

        return $precedente
            ? "Il periodo passa a {$dove}, che resta fino al ".$posizionamento->removed_at->format('d/m/Y').'.'
            : 'In quel periodo la macchina risultera\' in magazzino.';
    }

    public static function esegui(MachineUnitPlacement $posizionamento): void
    {
        DB::transaction(function () use ($posizionamento) {
            $precedente = static::precedente($posizionamento);

            $posizionamento->delete();
            $precedente?->update(['removed_at' => $posizionamento->removed_at]);

            // La posizione attuale si rilegge dallo storico ricucito.
            $macchina = MachineUnit::find($posizionamento->machine_unit_id);
            $aperta = $macchina?->placements()->whereNull('removed_at')->latest('placed_at')->first();

            $macchina?->update([
                'current_customer_id' => $aperta?->customer_id,
                // Chi paga torna quello della posizione riaperta.
                'billing_customer_id' => $aperta?->billing_customer_id,
                'eureka_billing_customer_code' => $aperta?->eureka_billing_customer_code,
                'status' => $aperta?->customer_id ? MachineUnit::STATUS_INSTALLATA : MachineUnit::STATUS_IN_MAGAZZINO,
            ]);
        });
    }
}
