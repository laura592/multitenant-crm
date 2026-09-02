<?php

use App\Http\Controllers\CustomerSchedaAnagraficaController;
use App\Http\Controllers\QuoteController;
use App\Http\Controllers\RiepilogoRapportiniController;
use App\Http\Controllers\ServiceReportController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return redirect('/admin');
});

// L'unica superficie di autenticazione dell'app e' il pannello Filament
// (nessun form di login "generico" a livello Laravel): senza questa route
// il middleware 'auth' di default, non potendo risolvere route('login'),
// restituisce 500 invece di reindirizzare un ospite al login corretto.
Route::get('/login', fn () => redirect()->route('filament.admin.auth.login'))->name('login');

// Pagina dedicata per la sessione scaduta: sia il 419 "pieno" (submit di un
// form non-Livewire con CSRF token ormai vecchio) sia le richieste AJAX di
// Livewire (intercettate in resources/js/app.js, che altrimenti mostrerebbero
// solo un confirm() del browser) puntano qui, cosi' l'utente vede sempre lo
// stesso messaggio invece di un errore grezzo.
Route::get('/sessione-scaduta', fn () => response()->view('errors.419', [], 419))->name('session.expired');

Route::middleware(['auth'])->group(function () {
    Route::get('service-reports/{serviceReport}/pdf', [ServiceReportController::class, 'pdf'])->name('service-reports.pdf');
    Route::get('service-reports/riepilogo', RiepilogoRapportiniController::class)->name('service-reports.riepilogo');
    Route::get('quotes/{quote}/pdf', [QuoteController::class, 'pdf'])->name('quotes.pdf');
    Route::get('customers/{customer}/scheda-anagrafica', CustomerSchedaAnagraficaController::class)
        ->name('customers.scheda-anagrafica');
});
