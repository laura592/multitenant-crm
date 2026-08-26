<?php

use App\Http\Controllers\LeadIntakeController;
use App\Http\Middleware\VerifyLeadSignature;
use Illuminate\Support\Facades\Route;

/*
| L'unica superficie API del CRM: l'intake dei lead da alexcaffe.com.
|
| Nessun Sanctum e nessun token utente — c'e' un solo chiamante, conosciuto,
| e una firma HMAC con segreto condiviso e' sufficiente. Il throttle e' un
| freno di sicurezza: un form pubblico e' pur sempre un form pubblico.
*/
Route::middleware([VerifyLeadSignature::class, 'throttle:30,1'])
    ->prefix('v1')
    ->group(function () {
        Route::post('lead', [LeadIntakeController::class, 'store'])->name('api.lead.store');
    });
