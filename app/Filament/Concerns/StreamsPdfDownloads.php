<?php

namespace App\Filament\Concerns;

use Filament\Notifications\Notification;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Boilerplate condiviso per scaricare un PDF generato da un'azione Filament
 * (Resource statica o Page): prima duplicato identicamente in
 * QuoteResource, MaterialOrderResource e RiepilogoOre. Racchiude la
 * generazione in un try/catch cosi' un errore di rendering (dati mancanti,
 * dataset limite) mostra una notifica invece di un 500 grezzo.
 */
trait StreamsPdfDownloads
{
    protected static function streamPdfDownload(\Closure $buildPdf, string $filename): ?StreamedResponse
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

        return response()->streamDownload(fn () => print ($output), $filename);
    }
}
