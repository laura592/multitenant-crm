<?php

namespace App\Filament\Resources\ServiceReportResource\Pages;

use App\Support\Rapportini\LavaggioFields;
use App\Filament\Resources\ServiceReportResource;
use App\Models\Lavaggio;
use Filament\Actions\Action;
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

    /**
     * Firma in blocco: con "Salva e nuovo (stesso cliente)" il prossimo
     * rapportino riparte da cliente, data, tecnico e tipo di questo, e alla
     * fine "Salva e fai firmare" porta alla firma di tutti quelli della
     * visita (FirmaRapportini).
     *
     * @var array<string, mixed>
     */
    public array $stessaVisita = [];

    /** @var array<int, string> */
    public array $rapportiniVisita = [];

    public bool $firmaDopo = false;

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
        if ($this->firmaDopo) {
            return null;
        }

        $n = count($this->rapportiniVisita);

        return Notification::make()
            ->success()
            ->title("Rapportino {$this->getRecord()?->number} salvato")
            ->body($n > 1
                ? "{$n} rapportini in questa visita. Quando hai finito, \"Salva e fai firmare\" li fa firmare tutti insieme."
                : 'Il rapportino è stato salvato.');
    }

    protected function getFormActions(): array
    {
        return [
            $this->getCreateFormAction(),
            // Al cliente si fa firmare una volta sola: anche dopo un solo
            // rapportino e' il modo piu' pulito di raccogliere la firma.
            Action::make('createAndSign')
                ->label('Salva e fai firmare')
                ->icon('heroicon-o-pencil-square')
                ->color('success')
                ->action(function () {
                    $this->firmaDopo = true;
                    $this->create();
                    $this->firmaDopo = false;
                }),
            $this->getCreateAnotherFormAction()
                ->label('Salva e nuovo (stesso cliente)')
                ->icon('heroicon-o-document-duplicate'),
            $this->getCancelFormAction(),
        ];
    }

    /**
     * "Salva e nuovo" non ricarica la pagina: il form si svuota e si
     * riempie di nuovo, ma si restava in fondo, sui pulsanti. Il rapportino
     * successivo si comincia dall'alto (richiesta dell'ufficio, 22/09/2026).
     */
    public function create(bool $another = false): void
    {
        parent::create($another);

        if ($another) {
            $this->js('window.scrollTo({ top: 0, behavior: "smooth" })');
        }
    }

    protected function getRedirectUrl(): string
    {
        if ($this->firmaDopo && $this->getRecord()) {
            return FirmaRapportini::getUrl([
                'cliente' => $this->getRecord()->customer_id,
                // I rapportini di questa visita: quelli salvati con "Salva e
                // nuovo" dello stesso cliente, piu' questo.
                'rapportini' => \App\Models\ServiceReport::whereKey($this->rapportiniVisita)
                    ->where('customer_id', $this->getRecord()->customer_id)
                    ->pluck('id')
                    ->all(),
            ]);
        }

        return parent::getRedirectUrl();
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

        if ($this->stessaVisita !== []) {
            $this->form->fill(array_merge($this->form->getRawState(), $this->stessaVisita));
        }

        $this->callHook('afterFill');
    }

    /**
     * ServiceReport::syncMaintenanceSchedule() gira gia' su static::saved()
     * durante handleRecordCreation() (vedi CreateRecord::create() nel core
     * Filament), ma a quel punto il campo "Impianti e vie lavate" (Repeater,
     * non ->relationship()) non e' ancora stato applicato: Filament non lo
     * salva da solo (vedi mutateFormDataBeforeCreate() sopra). Senza questo
     * ri-lancio (dentro LavaggioFields::syncLavaggioImpianti()), il
     * primo salvataggio di una sanificazione multi-impianto genererebbe
     * ancora i lavaggi con la regola implicita vecchia (tutti i piani/quello
     * di machine_unit_id) invece che sulla selezione esplicita appena fatta
     * — corretto solo al salvataggio successivo.
     */
    protected function afterCreate(): void
    {
        $record = $this->getRecord();

        LavaggioFields::syncLavaggioImpianti($record, $this->lavaggioImpianti);

        $this->stessaVisita = array_filter([
            'customer_id' => $record->customer_id,
            'intervention_date' => $record->intervention_date?->toDateString(),
            'technician_id' => $record->technician_id,
            'intervention_type' => $record->intervention_type,
        ]);
        $this->rapportiniVisita[] = $record->id;

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
