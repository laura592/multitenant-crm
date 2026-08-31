<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        // Registrato solo per l'intake dei lead dal sito: prima non
        // esisteva nessuna superficie API.
        api: __DIR__.'/../routes/api.php',
        apiPrefix: 'api',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        //
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        /*
         | Toast che dicono qualcosa.
         |
         | Su un errore non gestito Filament mostra "Qualcosa e' andato
         | storto", che non aiuta nessuno: chi lo legge non sa se ha
         | sbagliato lui, se deve riprovare o se deve chiamare qualcuno.
         |
         | Qui traduciamo gli errori di database piu' comuni in una frase
         | che indica la causa. Il messaggio SQL grezzo non si mostra mai —
         | contiene nomi di tabelle e frammenti di query — ma finisce nei
         | log come sempre.
         */
        $exceptions->reportable(function (\Illuminate\Database\QueryException $e) {
            if (! request()->hasHeader('X-Livewire')) {
                return;
            }

            $codice = $e->errorInfo[1] ?? null;

            $messaggio = match ($codice) {
                1048 => 'Un campo obbligatorio e\' rimasto vuoto. Controlla quantita\', prezzo, sconto e IVA.',
                1062 => 'Esiste gia\' un record con questi dati: controlla se non l\'hai gia\' inserito.',
                1451, 1452 => 'Questo dato e\' collegato a qualcos\'altro e non puo\' essere salvato o eliminato cosi\'.',
                1264 => 'Un valore e\' fuori dai limiti consentiti: controlla percentuali e importi.',
                1406 => 'Un testo e\' troppo lungo per il campo in cui lo stai mettendo.',
                default => null,
            };

            if ($messaggio) {
                \Filament\Notifications\Notification::make()
                    ->title('Salvataggio non riuscito')
                    ->body($messaggio)
                    ->danger()
                    ->persistent()
                    ->send();
            }
        });
    })->create();
