<?php

namespace App\Filament\Widgets\Contabilita;

use App\Models\EurekaCashflowMese;
use Filament\Facades\Filament;
use Filament\Widgets\ChartWidget;

/**
 * Entrate e uscite previste, mese per mese.
 *
 * Le uscite vanno SOTTO LA LINEA, non accanto alle entrate: entrare e uscire
 * sono versi opposti, e disegnarli come due barre affiancate costringe a
 * confrontare due altezze per capire una cosa sola — se il mese chiude sopra
 * o sotto zero. Con lo zero al centro si legge a colpo d'occhio.
 *
 * Blu ed rosso sono la coppia divergente: due tinte che si leggono come
 * opposte, non due colori qualsiasi. Le stesse su tema chiaro e scuro,
 * perché i colori arrivano dal server mentre il tema si sceglie nel browser.
 */
class CashflowMensileWidget extends ChartWidget
{
    // "Previste" solo nei riquadri sopra, che contano davvero solo il
    // futuro. Qui dentro ci sono anche i mesi gia' passati dell'anno in
    // corso: Eureka li restituisce e servono da termine di paragone, ma
    // chiamare "previsione" gennaio a settembre sarebbe falso.
    protected static ?string $heading = 'Entrate e uscite, mese per mese';

    protected static ?string $description = 'Dallo scadenziario e dai documenti ancora aperti su Eureka. Le uscite stanno sotto lo zero; i mesi già passati restano per confronto.';

    protected int|string|array $columnSpan = 'full';

    protected static ?string $maxHeight = '320px';

    private const ENTRATE = '#2a78d6';

    private const USCITE = '#e34948';

    /** @return array<string, mixed> */
    protected function getData(): array
    {
        $mesi = EurekaCashflowMese::query()
            ->where('tenant_id', Filament::getTenant()?->id)
            ->orderBy('anno')
            ->orderBy('mese')
            ->get();

        return [
            'datasets' => [
                [
                    'label' => 'Entrate',
                    'data' => $mesi->map(fn (EurekaCashflowMese $m) => (float) $m->entrate)->all(),
                    'backgroundColor' => self::ENTRATE,
                    'borderColor' => self::ENTRATE,
                    // Angoli arrotondati sull'estremita' del dato, non su
                    // tutta la barra: la base resta ancorata allo zero.
                    'borderRadius' => 4,
                ],
                [
                    'label' => 'Uscite',
                    // Il segno meno e' il punto: sotto la linea.
                    'data' => $mesi->map(fn (EurekaCashflowMese $m) => -(float) $m->uscite)->all(),
                    'backgroundColor' => self::USCITE,
                    'borderColor' => self::USCITE,
                    'borderRadius' => 4,
                ],
            ],
            'labels' => $mesi->map(fn (EurekaCashflowMese $m) => $m->etichetta())->all(),
        ];
    }

    protected function getType(): string
    {
        return 'bar';
    }

    /** @return array<string, mixed> */
    protected function getOptions(): array
    {
        return [
            'scales' => [
                'x' => ['stacked' => false, 'grid' => ['display' => false]],
                'y' => ['beginAtZero' => true],
            ],
            'plugins' => [
                'legend' => ['display' => true, 'position' => 'bottom'],
            ],
            'interaction' => ['mode' => 'index', 'intersect' => false],
            'maintainAspectRatio' => false,
        ];
    }
}
