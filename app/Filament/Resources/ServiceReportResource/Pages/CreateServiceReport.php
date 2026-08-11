<?php

namespace App\Filament\Resources\ServiceReportResource\Pages;

use App\Filament\Resources\ServiceReportResource;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;

class CreateServiceReport extends CreateRecord
{
    protected static string $resource = ServiceReportResource::class;

    /**
     * Vedi lo stesso override su EditServiceReport.
     */
    protected function getCreatedNotification(): ?Notification
    {
        return Notification::make()
            ->success()
            ->title('Rapportino creato')
            ->body('Il rapportino è stato salvato.');
    }

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

        $prefill = array_filter([
            'customer_id' => request()->query('customer_id'),
            'machine_unit_id' => request()->query('machine_unit_id'),
            'intervention_date' => request()->query('intervention_date'),
            'intervention_type' => request()->query('intervention_type'),
            'problem_description' => request()->query('problem_description'),
            'work_performed' => request()->query('work_performed'),
            'notes' => request()->query('notes'),
        ], fn ($value) => filled($value));

        if ($prefill !== []) {
            $this->form->fill(array_merge($this->form->getRawState(), $prefill));
        }

        $this->callHook('afterFill');
    }
}
