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
        $lavori = DiarioEsecuzioni::stato();

        // Un verdetto prima dell'elenco: la domanda che si fa aprendo questa
        // pagina e' "c'e' qualcosa che non va?", e rispondeva solo leggendo
        // riga per riga sei lavori (Laura, 07/10/2026).
        $per = fn (string $stato) => count(array_filter($lavori, fn ($l) => $l['stato'] === $stato));

        $guai = array_filter([
            $per('errore') ? $per('errore').' '.($per('errore') === 1 ? 'lavoro fallito' : 'lavori falliti') : null,
            $per('fermo') ? $per('fermo').' che non '.($per('fermo') === 1 ? 'gira' : 'girano').' da troppo' : null,
            $per('mai') ? $per('mai').' mai '.($per('mai') === 1 ? 'partito' : 'partiti') : null,
        ]);

        return [
            'lavori' => $lavori,
            'inCorso' => $per('in_corso') > 0,
            'verdetto' => $guai ? implode(' · ', $guai) : 'Tutti i lavori con Eureka sono in regola.',
            'tuttoBene' => $guai === [],
        ];
    }
}
