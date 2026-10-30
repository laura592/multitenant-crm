<?php

namespace App\Support\Casette;

use App\Models\LetturaCasetta;
use Filament\Notifications\Notification;

/**
 * L'avviso sul filtro a carboni attivi, dato appena la lettura e' salvata.
 *
 * La soglia sui fogli di Casale era scritta a mano in un angolo ("PROX CAMBIO
 * FILTRO 467 M3"): la sapeva chi teneva il foglio, e chi andava sul posto
 * doveva ricordarsela. Qui la casetta la porta con se'
 * (machine_units.filtro_ogni) e il conto lo fa il programma: il tecnico lo
 * legge quando e' ancora davanti alla macchina.
 *
 * Nessun avviso se la casetta non ha un'autonomia configurata o se non
 * risulta nessun cambio filtro precedente: non si sa quando scade, e un
 * avviso inventato e' peggio di nessun avviso.
 */
class AvvisoFiltro
{
    public static function perLettura(LetturaCasetta $lettura): void
    {
        $manca = $lettura->mancaAlFiltro();

        if ($manca === null || $lettura->sostituzione_filtro) {
            return;
        }

        $casetta = $lettura->machineUnit;
        $autonomia = (float) ($casetta ? $casetta->filtro_ogni : 0);
        $unita = $lettura->unitaBreve();

        if ($manca <= 0) {
            Notification::make()
                ->title('Filtro da cambiare')
                ->body('Questa casetta ha superato di '.number_format(abs($manca), 0, ',', '.')." {$unita} la soglia del cambio filtro.")
                ->warning()
                ->persistent()
                ->send();

            return;
        }

        // L'ultimo decimo dell'autonomia: serve a caricare il filtro sul
        // furgone prima di ripartire, non a tornare indietro.
        if ($autonomia > 0 && $manca <= $autonomia / 10) {
            Notification::make()
                ->title('Filtro quasi a fine corsa')
                ->body('Mancano '.number_format($manca, 0, ',', '.')." {$unita} al cambio del filtro.")
                ->warning()
                ->send();
        }
    }
}
