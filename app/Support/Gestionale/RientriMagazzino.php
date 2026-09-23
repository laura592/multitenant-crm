<?php

namespace App\Support\Gestionale;

use App\Models\MachineUnit;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Le macchine tornate in magazzino, lette dai rapportini.
 *
 * Il ritiro su Eureka e' un DDT di ritiro, e l'elenco delle macchine
 * installate non lo riflette: una macchina che rientra continua a
 * risultare dal cliente di prima (23/09/2026: all'Hotel Bellevue ne sono
 * state ritirate otto il 21/09 e nel CRM erano ancora li'). Dall'API i DDT
 * non si leggono.
 *
 * Il ritiro pero' lascia una traccia nel rapportino: la riga
 * "DISIN/RITIRO" (o un altro articolo che comincia per DISIN). Se e' l'ultima
 * cosa successa a quella macchina presso quel cliente, il sync propone il
 * rientro in magazzino; a confermarlo e' una persona.
 */
final class RientriMagazzino
{
    /** Gli articoli del ritiro: DISIN/RITIRO, DISINMAC, DISINACQUA... */
    public static function eRitiro(?string $codice): bool
    {
        $codice = Str::upper(trim((string) $codice));

        return $codice !== '' && (str_starts_with($codice, 'DISIN') || str_contains($codice, 'RITIR'));
    }

    /**
     * Il rientro da proporre, o null.
     *
     * @param  Collection<int, object{data: Carbon, numero: string, customer_id: string}>  $ritiri  i ritiri nei rapportini di questa macchina
     * @param  ?Carbon  $dal  da quando la macchina e' dov'e' ora
     * @param  ?Carbon  $ultimaConsegna  l'ultima bolla di consegna nota su Eureka
     * @param  ?Carbon  $ultimoIntervento  l'ultima volta che un tecnico e' andato su questa macchina, li'
     * @return ?array{data: Carbon, motivo: string}
     */
    public static function proposta(MachineUnit $macchina, Collection $ritiri, ?Carbon $dal, ?Carbon $ultimaConsegna = null, ?Carbon $ultimoIntervento = null): ?array
    {
        if (! $macchina->current_customer_id) {
            return null;
        }

        $ultimo = $ritiri
            ->filter(fn ($r) => $r->customer_id === $macchina->current_customer_id)
            ->filter(fn ($r) => ! $dal || $r->data->gte($dal->copy()->startOfDay()))
            // Ritirata e poi riconsegnata li': della consegna se ne occupa
            // SpostamentiMacchine, qui non c'e' niente da proporre.
            ->filter(fn ($r) => ! $ultimaConsegna || $r->data->gt($ultimaConsegna->copy()->startOfDay()))
            ->sortByDesc(fn ($r) => $r->data)
            ->first();

        if (! $ultimo) {
            return null;
        }

        // Dopo il ritiro il tecnico e' tornato su quella macchina, li': vuol
        // dire che e' ancora al suo posto (rientrata e riportata, oppure era
        // il ritiro di un pezzo e non della macchina).
        if ($ultimoIntervento && $ultimoIntervento->copy()->startOfDay()->gt($ultimo->data->copy()->startOfDay())) {
            return null;
        }

        if ($macchina->spostamento_scartato === MachineUnit::chiaveSpostamento(null, $ultimo->data)) {
            return null;
        }

        return [
            'data' => $ultimo->data->copy()->startOfDay(),
            'motivo' => "ritirata il {$ultimo->data->format('d/m/Y')} (rapportino {$ultimo->numero})",
        ];
    }
}
