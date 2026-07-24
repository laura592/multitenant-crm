<?php

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

Route::middleware(['auth'])->group(function () {
    Route::get('service-reports/{serviceReport}/pdf', [ServiceReportController::class, 'pdf'])->name('service-reports.pdf');
});
