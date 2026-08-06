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
     * Precompila anche la machine_unit_id quando si arriva da Macchinari
     * (?machine_unit_id=...), così il tecnico non deve ricercarla a mano.
     * Precompila data intervento e lavoro svolto quando si arriva da un
     * lavaggio appena registrato (MaintenanceScheduleResource\LavaggiRelationManager,
     * ?intervention_date=...&work_performed=...).
     * form->fill($state) con uno stato esplicito rimpiazza l'intero stato del
     * form (non lo fonde): prima si lascia risolvere normalmente ogni default
     * di campo (es. tecnico = utente loggato), poi si sovrascrivono solo i
     * parametri query sullo stato gia' risolto.
     */
    protected function fillForm(): void
    {
        $this->callHook('beforeFill');

        $this->form->fill();

        $fillData = [];

        if ($customerId = request()->query('customer_id')) {
            $fillData['customer_id'] = $customerId;
        }

        if ($machineUnitId = request()->query('machine_unit_id')) {
            $fillData['machine_unit_id'] = $machineUnitId;
        }

        if ($interventionDate = request()->query('intervention_date')) {
            $fillData['intervention_date'] = $interventionDate;
        }

        if ($workPerformed = request()->query('work_performed')) {
            $fillData['work_performed'] = $workPerformed;
        }

        if (!empty($fillData)) {
            $this->form->fill(array_merge($this->form->getRawState(), $fillData));
        }

        $this->callHook('afterFill');
    }
}
