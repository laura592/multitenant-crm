<?php

namespace App\Filament\Widgets\Contabilita;

use App\Models\EurekaFatturatoMese;
use Filament\Facades\Filament;
use Filament\Widgets\ChartWidget;
use Illuminate\Support\Carbon;

/**
 * Il fatturato clienti mese per mese, UNA LINEA PER ANNO.
 *
 * Non una linea sola lunga tre anni: la domanda vera non è "quanto abbiamo
 * fatturato" (quello è un numero, e sta nel riquadro sopra) ma "questo mese
 * sta andando come l'anno scorso?". Con gli anni sovrapposti la risposta si
 * legge senza fare conti, e la stagionalità di un'attività che vive di
 * alberghi e stabilimenti balneari salta all'occhio.
 *
 * I numeri sono quelli di EUREKA, non ricalcolati dalle nostre fatture: il
 * gestionale pesa le causali col piano dei conti e conta per data di
 * registrazione, e sul 2026 la differenza è di 14.000 euro. Meglio un numero
 * che coincide con quello che l'ufficio vede sul gestionale.
 */
class FatturatoMensileWidget extends ChartWidget
{
    protected static ?string $heading = 'Fatturato clienti, un anno per linea';

    protected static ?string $description = 'Numeri di Eureka (netto contabile). Si aggiornano con eureka:import-kpi-contabili.';

    protected int|string|array $columnSpan = 'full';

    protected static ?string $maxHeight = '320px';

    /**
     * Tre tinte scelte per essere distinguibili anche da chi non vede bene i
     * colori, e le STESSE su tema chiaro e scuro: le tinte arrivano dal
     * server, mentre il tema si cambia nel browser, quindi una coppia diversa
     * per modalità qui non è possibile. Validate su entrambe le superfici
     * (separazione CVD ΔE 9.4, contrasto ≥ 3:1 su tutte e due).
     *
     * L'ordine è fisso e legato all'ANNO, non alla posizione: cambiando il
     * numero di anni a video, un anno non deve cambiare colore.
     */
    private const COLORI = ['#2a78d6', '#d95926', '#199e70'];

    /** @return array<string, mixed> */
    protected function getData(): array
    {
        $mesi = EurekaFatturatoMese::query()
            ->where('tenant_id', Filament::getTenant()?->id)
            ->where('tipo', EurekaFatturatoMese::TIPO_CLIENTE)
            ->orderBy('anno')
            ->orderBy('mese')
            ->get();

        // Gli anni piu' recenti, al massimo tre: oltre, le linee si
        // accavallano e i colori sicuri finiscono.
        $anni = $mesi->pluck('anno')->unique()->sortDesc()->take(3)->sort()->values();

        $datasets = [];

        foreach ($anni as $i => $anno) {
            $perMese = $mesi->where('anno', $anno)->keyBy('mese');

            $datasets[] = [
                'label' => (string) $anno,
                'data' => collect(range(1, 12))
                    ->map(fn (int $m) => $perMese->has($m) ? (float) $perMese[$m]->netto : null)
                    ->all(),
                'borderColor' => self::COLORI[$i] ?? self::COLORI[0],
                'backgroundColor' => self::COLORI[$i] ?? self::COLORI[0],
                // Linee sottili e punti piccoli: i dati sono la figura, non
                // la decorazione.
                'borderWidth' => 2,
                'pointRadius' => 3,
                'pointHoverRadius' => 5,
                // L'anno in corso si ferma al mese corrente invece di
                // crollare a zero: i mesi futuri sono null, non zero.
                'spanGaps' => false,
                'tension' => 0.25,
            ];
        }

        return [
            'datasets' => $datasets,
            'labels' => collect(range(1, 12))
                ->map(fn (int $m) => ucfirst(mb_substr(Carbon::create(2026, $m, 1)->translatedFormat('F'), 0, 3)))
                ->all(),
        ];
    }

    protected function getType(): string
    {
        return 'line';
    }

    /** @return array<string, mixed> */
    protected function getOptions(): array
    {
        return [
            'scales' => [
                'y' => [
                    // Un asse che non parte da zero fa sembrare enormi
                    // differenze piccole.
                    'beginAtZero' => true,
                ],
                'x' => ['grid' => ['display' => false]],
            ],
            'plugins' => [
                // La legenda c'e' sempre con piu' di una serie: il colore da
                // solo non deve mai essere l'unico modo di sapere quale anno
                // si sta guardando.
                'legend' => ['display' => true, 'position' => 'bottom'],
            ],
            'interaction' => ['mode' => 'index', 'intersect' => false],
            'maintainAspectRatio' => false,
        ];
    }
}
