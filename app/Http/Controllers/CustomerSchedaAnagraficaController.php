<?php

namespace App\Http\Controllers;

use App\Models\Customer;
use App\Support\Pdf\SchedaAnagraficaData;
use App\Support\Pdf\SchedaAnagraficaPdf;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Spatie\Permission\PermissionRegistrar;

class CustomerSchedaAnagraficaController extends Controller
{
    public function __invoke(Customer $customer)
    {
        // Stessa avvertenza di ServiceReportController::pdf(): questa rotta sta
        // fuori dal pannello Filament, quindi ne' SetPermissionsTeamId ne' lo
        // scope tenant automatico girano qui. Senza queste due righe il
        // permesso non verrebbe trovato (ruoli assegnati per tenant) e
        // qualunque utente autenticato potrebbe scaricare l'anagrafica di un
        // altro tenant indovinandone l'id.
        app(PermissionRegistrar::class)->setPermissionsTeamId(auth()->user()?->tenant_id);

        Gate::authorize('view', $customer);

        // CustomerPolicy::view() controlla solo il permesso di ruolo, perche'
        // finora un cliente si raggiungeva solo dal pannello, dove ci pensa lo
        // scope tenant di BelongsToTenant. Qui quello scope non c'e': senza
        // questo controllo il permesso da solo basterebbe a scaricare
        // l'anagrafica di un ALTRO tenant indovinandone l'id (stesso problema
        // gia' corretto a suo tempo su ServiceReportPolicy::view()). Si usa
        // canAccessTenant() invece del confronto secco sui tenant_id per
        // restare allineati a come il pannello definisce l'accesso, super
        // admin inclusi.
        abort_unless(
            $customer->tenant && auth()->user()?->canAccessTenant($customer->tenant),
            403,
        );

        $pdf = new SchedaAnagraficaPdf(
            SchedaAnagraficaData::for($customer),
            $customer->tenant,
            SchedaAnagraficaData::conteggi($customer),
        );

        $nome = Str::slug($customer->company_name ?: $customer->full_name) ?: 'cliente';

        // "attachment" e non "inline": aperto nel browser il modulo finisce in
        // mano al visualizzatore PDF del browser, e con l'estensione Acrobat
        // installata in Chrome quello svuota i campi precompilati (il file e'
        // corretto: Anteprima, l'applicazione Acrobat e gli altri motori lo
        // mostrano pieno). Scaricandolo, si apre nell'applicazione PDF di
        // sistema e i dati si vedono. E' anche il gesto giusto per un modulo
        // che va allegato a una mail e mandato al cliente.
        return response($pdf->render(), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="scheda-anagrafica-'.$nome.'.pdf"',
        ]);
    }
}
