<?php

namespace App\Support\Presenze;

use Carbon\Carbon;
use Carbon\CarbonInterface;

/**
 * Festivita' nazionali italiane. Un festivo non e' mai un giorno di ferie:
 * o e' festa, o e' lavorato (e allora conta come ore lavorate), quindi le
 * ferie a cavallo di un festivo non lo scalano (vedi LeaveRequest::daysWithin).
 *
 * Il patrono non c'e': dipende dalla sede, e nessun tenant lo ha ancora
 * configurato.
 */
final class Festivi
{
    private const FISSI = ['01-01', '01-06', '04-25', '05-01', '06-02', '08-15', '11-01', '12-08', '12-25', '12-26'];

    /** @var array<int, array<int, string>> anno -> date Y-m-d */
    private static array $cache = [];

    public static function isFestivo(CarbonInterface $day): bool
    {
        return in_array($day->format('Y-m-d'), self::perAnno($day->year), true);
    }

    /** Sabato, domenica o festivo nazionale. */
    public static function isNonLavorativo(CarbonInterface $day): bool
    {
        return $day->isWeekend() || self::isFestivo($day);
    }

    /** @return array<int, string> */
    public static function perAnno(int $year): array
    {
        return self::$cache[$year] ??= [
            ...array_map(fn (string $md) => "{$year}-{$md}", self::FISSI),
            self::pasqua($year)->addDay()->format('Y-m-d'), // Pasquetta
        ];
    }

    /**
     * Domenica di Pasqua (calendario gregoriano, algoritmo di Meeus/Jones/
     * Butcher): easter_date() richiederebbe l'estensione calendar, che nel
     * container di produzione non e' garantita.
     */
    public static function pasqua(int $year): Carbon
    {
        $a = $year % 19;
        $b = intdiv($year, 100);
        $c = $year % 100;
        $d = intdiv($b, 4);
        $e = $b % 4;
        $f = intdiv($b + 8, 25);
        $g = intdiv($b - $f + 1, 3);
        $h = (19 * $a + $b - $d - $g + 15) % 30;
        $i = intdiv($c, 4);
        $k = $c % 4;
        $l = (32 + 2 * $e + 2 * $i - $h - $k) % 7;
        $m = intdiv($a + 11 * $h + 22 * $l, 451);
        $month = intdiv($h + $l - 7 * $m + 114, 31);
        $day = (($h + $l - 7 * $m + 114) % 31) + 1;

        return Carbon::create($year, $month, $day)->startOfDay();
    }
}
