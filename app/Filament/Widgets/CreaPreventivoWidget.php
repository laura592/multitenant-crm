<?php

namespace App\Filament\Widgets;

use App\Filament\Resources\QuoteResource;
use BezhanSalleh\FilamentShield\Traits\HasWidgetShield;
use Filament\Widgets\Widget;

/**
 * Scorciatoia "Crea preventivo" in cima alla dashboard: per l'admin e' il
 * primo gesto piu' frequente (a differenza di TimbraWidget, pensato per chi
 * timbra). Gated con HasWidgetShield come TimbraWidget/RiepilogoOre (vedi
 * docs/architecture.md §5.3): e' solo un bottone verso QuoteResource::create,
 * non un riepilogo dati come gli altri widget dashboard (quelli si gatano su
 * view_any_* della Resource che riassumono): qui serve invece il permesso
 * create_quote, che nessun view_any_* coprirebbe da solo - un dipendente o un
 * profilo amministrazione non hanno comunque quel permesso, mostrargliela
 * sarebbe solo un bottone morto.
 */
class CreaPreventivoWidget extends Widget
{
    use HasWidgetShield;

    protected static string $view = 'filament.widgets.crea-preventivo-widget';

    protected int|string|array $columnSpan = 'full';

    // Prima di TimbraWidget (sort -1): sulla dashboard admin questo e' il
    // gesto piu' frequente, deve essere la prima cosa visibile.
    protected static ?int $sort = -2;

    public function getCreateUrl(): string
    {
        return QuoteResource::getUrl('create');
    }
}
