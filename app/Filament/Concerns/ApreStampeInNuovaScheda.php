<?php

namespace App\Filament\Concerns;

use App\Support\Pdf\StampaTemporanea;
use Filament\Notifications\Notification;

/**
 * Le stampe del pannello si aprono in una scheda nuova, non si scaricano.
 *
 * Sostituisce StreamsPdfDownloads: era lo stesso boilerplate ma ritornava una
 * response, e una response ritornata da un'azione Livewire diventa sempre un
 * download. Qui il PDF viene parcheggiato (StampaTemporanea) e l'azione apre
 * l'URL in una scheda: l'elenco resta dov'era, coi suoi filtri e la sua
 * pagina, mentre si guarda la stampa — stesso gesto gia' in uso per il
 * riepilogo rapportini in ListServiceReports.
 *
 * Il try/catch resta: un errore di rendering (dati mancanti, dataset limite)
 * deve mostrare una notifica, non un 500 grezzo.
 */
trait ApreStampeInNuovaScheda
{
    /**
     * @param  \Closure  $buildPdf  ritorna il PDF gia' costruito (non l'output)
     * @param  mixed  $livewire  il componente da cui parte l'azione, per il window.open
     */
    protected static function apriPdfInNuovaScheda(\Closure $buildPdf, string $nomeFile, $livewire): void
    {
        $url = static::urlStampa($buildPdf, $nomeFile);

        if ($url !== null) {
            static::apriUrlInNuovaScheda($url, $livewire);
        }
    }

    /**
     * Genera e parcheggia, senza aprire niente: e' la meta' che non ha
     * bisogno di un componente Livewire, e quindi l'unica verificabile in un
     * test senza montare la pagina.
     *
     * @param  \Closure  $buildPdf  ritorna il PDF gia' costruito (non l'output)
     */
    protected static function urlStampa(\Closure $buildPdf, string $nomeFile): ?string
    {
        try {
            $output = $buildPdf()->output();
        } catch (\Throwable $e) {
            report($e);

            Notification::make()
                ->danger()
                ->title('Generazione PDF non riuscita')
                ->body('Si e\' verificato un errore imprevisto durante la generazione del PDF. Riprova o contatta l\'assistenza.')
                ->send();

            return null;
        }

        return StampaTemporanea::parcheggia($output, $nomeFile);
    }

    /**
     * JSON_UNESCAPED_SLASHES: senza, l'URL finisce nel JS come
     * "http:\/\/localhost/..." — funziona, ma e' illeggibile in console e nei
     * log del browser.
     */
    protected static function apriUrlInNuovaScheda(string $url, $livewire): void
    {
        $livewire->js('window.open('.json_encode($url, JSON_UNESCAPED_SLASHES).", '_blank')");
    }
}
