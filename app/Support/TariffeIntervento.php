<?php

namespace App\Support;

use App\Models\Customer;

/**
 * Sceglie i codici tariffa di un rapportino a partire dal pagante.
 *
 * Le scorciatoie del rapportino inserivano codici fissi (CHIORD/CHIVE per
 * citta', ORE, LAV2, ULTVIA): chi compila doveva ricordarsi di sostituirli a
 * mano quando il cliente e' fatturato a un pagante con listino proprio, e nei
 * fatti non succedeva quasi mai. La tabella sta in config/tariffe.php.
 */
class TariffeIntervento
{
    /**
     * @return array{chiamata: ?string, manodopera: ?string, lavaggio: ?string, lavaggio_ulteriore_via: ?string, pagante: ?string}
     */
    public static function per(?Customer $cliente, bool $festivo = false): array
    {
        $standard = config('tariffe.standard');
        $pagante = $cliente?->billingCustomer;
        $listino = $pagante?->gestionale_code
            ? (config('tariffe.paganti')[(int) $pagante->gestionale_code] ?? null)
            : null;

        // Sul pagante il codice e' lo stesso ovunque si vada: e' il listino
        // concordato con lui, non la difficolta' del viaggio.
        $chiamata = $listino
            ? self::voce($listino, 'chiamata', $festivo)
            : ($festivo
                ? $standard['chiamata_festiva']
                : ($cliente?->city === 'Venezia' ? $standard['chiamata_venezia'] : $standard['chiamata']));

        return [
            'chiamata' => $chiamata,
            'manodopera' => $listino
                ? self::voce($listino, 'manodopera', $festivo)
                : ($festivo ? $standard['manodopera_festiva'] : $standard['manodopera']),
            'lavaggio' => $listino['lavaggio'] ?? $standard['lavaggio'],
            'lavaggio_ulteriore_via' => $listino['lavaggio_ulteriore_via'] ?? $standard['lavaggio_ulteriore_via'],
            'pagante' => $listino['nome'] ?? null,
        ];
    }

    /**
     * La voce festiva quando esiste; altrimenti quella ordinaria del pagante,
     * che e' comunque piu' giusta del codice standard.
     */
    private static function voce(array $listino, string $chiave, bool $festivo): ?string
    {
        if ($festivo && filled($listino[$chiave.'_festiva'] ?? null)) {
            return $listino[$chiave.'_festiva'];
        }

        return $listino[$chiave] ?? null;
    }
}
