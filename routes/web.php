<?php

use App\Http\Controllers\ServiceReportController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

Route::middleware(['auth'])->group(function () {
    Route::get('service-reports/{serviceReport}/pdf', [ServiceReportController::class, 'pdf'])->name('service-reports.pdf');
});
