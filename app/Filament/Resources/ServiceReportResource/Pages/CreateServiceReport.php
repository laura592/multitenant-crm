<?php

namespace App\Filament\Resources\ServiceReportResource\Pages;

use App\Filament\Resources\ServiceReportResource;
use Filament\Actions;
use Filament\Resources\Pages\CreateRecord;

class CreateServiceReport extends CreateRecord
{
    protected static string $resource = ServiceReportResource::class;

    /**
     * Precompila il cliente quando si arriva da "Clienti vicini"
     * (?customer_id=...), cosi' il tecnico non deve ricercarlo a mano.
     * form->fill($state) con uno stato esplicito rimpiazza l'intero stato del
     * form (non lo fonde): prima si lascia risolvere normalmente ogni default
     * di campo (es. tecnico = utente loggato), poi si sovrascrive solo
     * customer_id sullo stato gia' risolto.
     */
    protected function fillForm(): void
    {
        $this->callHook('beforeFill');

        $this->form->fill();

        if ($customerId = request()->query('customer_id')) {
            $this->form->fill(array_merge($this->form->getRawState(), [
                'customer_id' => $customerId,
            ]));
        }

        $this->callHook('afterFill');
    }
}
