<?php

namespace App\Filament\Resources\ServiceReportResource\Pages;

use App\Filament\Resources\ServiceReportResource;
use App\Models\Lavaggio;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;

class CreateServiceReport extends CreateRecord
{
    protected static string $resource = ServiceReportResource::class;

    /**
     * lavaggio_impianti non e' una colonna reale (vedi il campo sul form):
     * estratto qui prima del create() e riapplicato in afterCreate(), dove
     * il record ha finalmente un id da usare per collegare i piani e
     * scrivere le vie lavate sulle righe Lavaggio generate.
     */
    protected array $lavaggioImpianti = [];

    /**
     * Letto da fillForm() (mount, unica chiamata che vede davvero la query
     * string della pagina) e riusato in afterCreate(): le action Livewire
     * successive come "create" girano su una richiesta separata (l'endpoint
     * di update, senza piu' i query param originali - request()->query('lavaggio_id')
     * li' dentro sarebbe sempre vuoto) con un'istanza del componente
     * rideidratata da zero. Deve essere public: solo le proprieta' pubbliche
     * di un componente Livewire sopravvivono nello snapshot tra una
     * richiesta e l'altra, una protected/private tornerebbe sempre al
     * default ad ogni azione successiva al mount.
     */
    public ?string $sourceLavaggioId = null;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $this->lavaggioImpianti = $data['lavaggio_impianti'] ?? [];
        unset($data['lavaggio_impianti']);

        return $data;
    }

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

        $this->sourceLavaggioId = request()->query('lavaggio_id');

        $this->callHook('afterFill');
    }

    /**
     * ServiceReport::syncMaintenanceSchedule() gira gia' su static::saved()
     * durante handleRecordCreation() (vedi CreateRecord::create() nel core
     * Filament), ma a quel punto il campo "Impianti e vie lavate" (Repeater,
     * non ->relationship()) non e' ancora stato applicato: Filament non lo
     * salva da solo (vedi mutateFormDataBeforeCreate() sopra). Senza questo
     * ri-lancio (dentro ServiceReportResource::syncLavaggioImpianti()), il
     * primo salvataggio di una sanificazione multi-impianto genererebbe
     * ancora i lavaggi con la regola implicita vecchia (tutti i piani/quello
     * di machine_unit_id) invece che sulla selezione esplicita appena fatta
     * — corretto solo al salvataggio successivo.
     */
    protected function afterCreate(): void
    {
        $record = $this->getRecord();

        ServiceReportResource::syncLavaggioImpianti($record, $this->lavaggioImpianti);

        $this->linkSourceLavaggio($record);
    }

    /**
     * Quando si arriva da "Crea rapportino" su una riga Lavaggio
     * (?lavaggio_id=..., vedi MaintenanceScheduleResource\LavaggiRelationManager::serviceReportCreateUrl()),
     * syncMaintenanceSchedule() sopra ha appena generato una riga lavaggio
     * "gemella" per lo stesso piano (ServiceReport::syncGeneratedLavaggi()
     * cerca per service_report_id, che sulla riga originale e' ancora nullo,
     * quindi non la trova e ne crea una nuova). Qui si elimina quella
     * generata e si collega invece la riga originale, cosi' restano le
     * note/vie lavate/filtro gia' inseriti a mano invece del placeholder
     * generico "Generato da rapportino ...".
     */
    private function linkSourceLavaggio($record): void
    {
        if (! $this->sourceLavaggioId) {
            return;
        }

        $original = Lavaggio::find($this->sourceLavaggioId);

        if (! $original || $original->service_report_id) {
            return;
        }

        Lavaggio::where('service_report_id', $record->id)
            ->where('maintenance_schedule_id', $original->maintenance_schedule_id)
            ->where('id', '!=', $original->id)
            ->get()
            ->each->delete();

        $original->update(['service_report_id' => $record->id]);
    }
}
