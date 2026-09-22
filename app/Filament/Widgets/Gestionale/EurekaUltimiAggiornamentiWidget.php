<?php

namespace App\Filament\Widgets\Gestionale;

use App\Support\Gestionale\DiarioEsecuzioni;
use Filament\Widgets\Widget;

/**
 * In cima alla revisione del sync: quando e' girato ogni lavoro con Eureka,
 * com'e' finito e cosa ha trovato (DiarioEsecuzioni). Rosso se e' fallito,
 * arancione se non gira da troppo.
 */
class EurekaUltimiAggiornamentiWidget extends Widget
{
    protected static string $view = 'filament.widgets.eureka-ultimi-aggiornamenti';

    protected int|string|array $columnSpan = 'full';

    protected static bool $isLazy = false;

    // Si aggiorna da solo mentre un "Sincronizza ora" e' in corso.
    protected static ?string $pollingInterval = '30s';

    protected function getViewData(): array
    {
        return ['lavori' => DiarioEsecuzioni::stato()];
    }
}
