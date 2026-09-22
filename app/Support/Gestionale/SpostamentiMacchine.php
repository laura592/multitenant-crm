<?php

namespace App\Support\Gestionale;

use App\Models\Customer;
use App\Models\MachineUnit;
use Illuminate\Support\Carbon;

/**
 * Dove si trova davvero una macchina, secondo le bolle di Eureka.
 *
 * Eureka non chiude la consegna vecchia quando una macchina va da un altro
 * cliente: in /show/q/art_installati la stessa matricola compare presso
 * entrambi, ognuno con la sua bolla (22/09/2026: 17 macchine su 30 fuori
 * posto). La posizione vera e' la bolla piu' recente, e quasi sempre e'
 * anche dove il tecnico ha fatto l'ultimo intervento.
 *
 * Il CRM invece metteva la macchina dal primo cliente in cui la vedeva e
 * non la spostava piu'. Qui si decide solo se proporre lo spostamento:
 * lo conferma una persona, perche' uno spostamento sbagliato si porta
 * dietro rapportini e piani di lavaggio.
 */
final class SpostamentiMacchine
{
    /**
     * @param  array<int, array{cliente: Customer, data: ?Carbon, bolla: int, pagante?: ?int}>  $consegne  la matricola su Eureka, una riga per cliente
     * @param  ?Carbon  $dal  da quando la macchina e' dove dice il CRM
     * @return ?array{cliente: Customer, data: Carbon, motivo: string, pagante: ?int}
     */
    public static function proposta(MachineUnit $macchina, array $consegne, ?Carbon $dal): ?array
    {
        $datate = array_values(array_filter($consegne, fn (array $c) => $c['data'] !== null));

        if ($datate === []) {
            return null;
        }

        usort($datate, fn (array $a, array $b) => $b['data'] <=> $a['data']);
        $ultima = $datate[0];

        // Due clienti con la bolla dello stesso giorno (spesso il 01/01 del
        // saldo iniziale): non si sa quale sia quella vera.
        $pari = array_filter($datate, fn (array $c) => $c['data']->isSameDay($ultima['data']) && $c['cliente']->id !== $ultima['cliente']->id);

        if ($pari !== [] || $ultima['cliente']->id === $macchina->current_customer_id) {
            return null;
        }

        // Il CRM sa gia' qualcosa di piu' recente (uno "Sposta" fatto a mano).
        // Lo stesso giorno no: e' la macchina che il CRM ha messo dal primo
        // cliente trovato con la data della bolla dell'altro (1901850).
        if ($dal && $ultima['data']->copy()->startOfDay()->lt($dal->copy()->startOfDay())) {
            return null;
        }

        if ($macchina->spostamento_scartato === MachineUnit::chiaveSpostamento($ultima['cliente']->id, $ultima['data'])) {
            return null;
        }

        $altrove = collect($datate)->skip(1)
            ->map(fn (array $c) => $c['cliente']->company_name.' dal '.$c['data']->format('d/m/Y'))
            ->implode('; ');

        return [
            'cliente' => $ultima['cliente'],
            'pagante' => $ultima['pagante'] ?? null,
            'data' => $ultima['data']->copy()->startOfDay(),
            'motivo' => trim(($ultima['bolla'] > 0 ? "bolla n. {$ultima['bolla']} " : 'bolla ').'del '.$ultima['data']->format('d/m/Y')
                .($altrove !== '' ? " (su Eureka risulta anche presso: {$altrove})" : '')),
        ];
    }
}
