<?php

namespace App\Filament\Resources\ServiceReportResource\Pages;

use App\Filament\Forms\Components\SignaturePad;
use App\Filament\Resources\MaintenanceScheduleResource;
use App\Filament\Resources\ServiceReportResource;
use App\Models\Customer;
use App\Models\Lavaggio;
use App\Models\MachineUnit;
use App\Models\MaintenanceSchedule;
use App\Models\Material;
use App\Models\ServiceReport;
use App\Models\User;
use App\Support\DisplayName;
use App\Support\Rapportini\LavaggioFields;
use App\Support\TariffeIntervento;
use Filament\Forms;
use Filament\Forms\Components\Wizard\Step;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\Page;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\HtmlString;
use Illuminate\Support\Str;

/**
 * Il rapportino a passi (22/09/2026): l'unico modo di creare e modificare
 * i rapportini, perche' due moduli diversi per la stessa cosa confondevano.
 *
 * Primo passo il cliente e le macchine su cui si e' lavorato, poi un passo
 * per macchina, poi riepilogo e firma. Ogni macchina e' un rapportino a se'
 * (una scheda Eureka, un rapportino); piu' macchine fatte insieme sono una
 * visita (visita_id) e prendono la stessa firma.
 *
 * Nasce da due visite vere: Hotel Olanda (21/09, due X20 finite in un
 * rapportino solo con MANUTENZIONE X20 x2, poi sdoppiato a mano e rimasto
 * mezzo senza firma) e La Strana Coppia (18/09, spina e acqua in un
 * rapportino solo, due schede su Eureka).
 *
 * Il passo di una macchina e' il modulo del rapportino (le sue sezioni
 * Macchina, Descrizione e Ricambi, vedi passoMacchina()): stesse regole su
 * codici, pagante, impianti e vie.
 *
 * Registrata due volte in ServiceReportResource::getPages(): come "create"
 * e come "edit" ({record}), cosi' tutti i link di sempre (Clienti vicini,
 * Macchinari, piani, lavaggi, "Modifica") arrivano qui.
 */
class RapportiniAPassi extends Page
{
    /**
     * La voce "Senza una macchina precisa": un rapportino che non riguarda
     * una macchina sola, come il lavaggio di tutti gli impianti del cliente
     * (machine_unit_id vuoto apposta, vedi ServiceReport::syncMaintenanceSchedule()).
     */
    public const GENERALE = 'generale';

    protected static string $resource = ServiceReportResource::class;

    protected static string $view = 'filament.resources.service-report-resource.pages.rapportini-a-passi';

    public ?array $data = [];

    /**
     * Si sta modificando (rapportini gia' salvati), non creando.
     */
    public bool $modifica = false;

    /**
     * La visita dei rapportini in modifica, se ne hanno una.
     */
    public ?string $visita = null;

    /**
     * In modifica: i rapportini gia' salvati, id => macchina (null per
     * quelli senza una macchina precisa). Ognuno e' un passo; le macchine
     * spuntate in piu' diventano rapportini nuovi della stessa visita, con
     * la firma che c'e'.
     *
     * @var array<string, ?string>
     */
    public array $esistenti = [];

    /**
     * Il passo da cui si parte: il rapportino che si e' aperto in modifica,
     * o la macchina gia' scelta arrivando da Macchinari o da un piano.
     */
    public int $passoIniziale = 1;

    /**
     * Arrivando da "Crea rapportino" su una riga lavaggio (?lavaggio_id=):
     * la riga si collega al rapportino invece di farne una gemella. Vedi
     * collegaLavaggioDiPartenza(). Pubblica perche' deve sopravvivere fino
     * al salvataggio, che e' un'altra richiesta Livewire.
     */
    public ?string $lavaggioDiPartenza = null;

    public ?string $chiaveLavaggioDiPartenza = null;

    /**
     * Le macchine per cliente, dentro la singola richiesta: le chiedono
     * elenco, descrizioni e passi a ogni render. Non pubblica, quindi non
     * sopravvive tra una richiesta Livewire e l'altra (e non deve).
     *
     * @var array<string, Collection<int, MachineUnit>>
     */
    private array $macchineCache = [];

    public function mount(int|string|null $record = null): void
    {
        if ($record !== null) {
            $this->caricaRapportino((string) $record);

            return;
        }

        abort_unless(ServiceReportResource::canCreate(), 403);

        $this->form->fill([
            'technician_id' => auth()->id(),
            'intervention_date' => request()->query('intervention_date') ?: now()->toDateString(),
            'status' => 'bozza',
            'macchine' => [],
            'lavori' => [],
        ]);

        $this->precompila();
    }

    /**
     * I link "Crea rapportino" di sempre: da Clienti vicini e dalla scheda
     * cliente (?customer_id), da Macchinari (?machine_unit_id), dai piani di
     * manutenzione e dai lavaggi (tipo, descrizioni, ?lavaggio_id). Con la
     * macchina gia' nota si parte direttamente dal suo passo.
     */
    private function precompila(): void
    {
        $macchina = ($id = request()->query('machine_unit_id')) ? MachineUnit::find($id) : null;
        $cliente = request()->query('customer_id') ?: $macchina?->current_customer_id;

        if (! $cliente) {
            return;
        }

        $this->scegliCliente($cliente);

        $testi = array_filter([
            'intervention_type' => request()->query('intervention_type'),
            'problem_description' => request()->query('problem_description'),
            'work_performed' => request()->query('work_performed'),
            'notes' => request()->query('notes'),
        ], fn ($valore) => filled($valore));

        $this->lavaggioDiPartenza = request()->query('lavaggio_id');

        // Senza macchina ma con qualcosa da scrivere (un lavaggio di tutti
        // gli impianti): va sulla voce "Senza una macchina precisa".
        $chiave = $macchina?->id ?? (($testi !== [] || $this->lavaggioDiPartenza) ? self::GENERALE : null);

        if (! $chiave) {
            return;
        }

        $this->data['macchine'] = [$chiave];
        $this->preparaLavoro($chiave);
        $this->data['lavori'][$chiave] = [...$this->data['lavori'][$chiave], ...$testi];
        $this->chiaveLavaggioDiPartenza = $this->lavaggioDiPartenza ? $chiave : null;
        $this->passoIniziale = 2;
    }

    /**
     * "Modifica" apre il rapportino da solo; "Modifica visita"
     * (?tutta_la_visita=1) apre tutti quelli della sua visita, partendo dal
     * suo passo. Gli stessi passi della creazione, in tutti e due i casi.
     */
    private function caricaRapportino(string $id): void
    {
        $rapportino = ServiceReport::findOrFail($id);

        // Come la vecchia pagina di modifica: un rapportino gia' su Eureka
        // non si modifica, per nessuno (il super admin scavalca la policy).
        abort_if($rapportino->isLocked(), 403);
        abort_unless(auth()->user()?->can('update', $rapportino), 403);

        $rapportini = $rapportino->visita_id && request()->boolean('tutta_la_visita')
            ? ServiceReport::query()->where('visita_id', $rapportino->visita_id)->orderBy('number')->get()
            : collect([$rapportino]);

        $this->modifica = true;
        $this->visita = $rapportino->visita_id;
        $this->esistenti = $rapportini->mapWithKeys(fn (ServiceReport $r) => [$r->id => $r->machine_unit_id])->all();

        $primo = $rapportini->first();

        $this->form->fill([
            'customer_id' => $primo->customer_id,
            'technician_id' => $primo->technician_id,
            'intervention_date' => $primo->intervention_date?->toDateString(),
            'status' => $primo->status,
            'customer_signature_name' => $primo->customer_signature_name,
            'customer_signature_path' => $primo->customer_signature_path,
            'macchine' => [
                ...array_values(array_filter($this->esistenti)),
                ...(in_array(null, $this->esistenti, true) ? [self::GENERALE] : []),
            ],
            'lavori' => [],
        ]);

        // Ogni passo si riempie come la vecchia pagina di modifica: i dati
        // del rapportino, i campi di comodo del modulo e i ricambi, che il
        // repeater ->relationship() carica da solo dal rapportino del passo.
        foreach ($rapportini as $r) {
            $this->form->getComponent("lavoro-{$r->id}")
                ?->getChildComponentContainer()
                ->fill([
                    ...$r->attributesToArray(),
                    ...LavaggioFields::statoModulo($r),
                ]);
        }

        $this->passoIniziale = 2 + (int) $rapportini->search(fn (ServiceReport $r) => $r->is($rapportino));
    }

    public function getTitle(): string|Htmlable
    {
        if (! $this->modifica) {
            return 'Nuovo rapportino';
        }

        return count($this->esistenti) > 1
            ? 'Modifica visita'
            : 'Modifica '.ServiceReport::find(array_key_first($this->esistenti))?->number;
    }

    public function getSubheading(): ?string
    {
        if (! $this->modifica) {
            return 'Scegli le macchine su cui hai lavorato: un passo per macchina, una firma sola, un rapportino per macchina.';
        }

        if (count($this->esistenti) > 1) {
            return 'Tutti i rapportini della visita, un passo ciascuno. Quelli già su Eureka si guardano e non si modificano.';
        }

        // Da solo, ma fa parte di una visita: si dice, e si dice come
        // correggerli tutti insieme.
        $altri = $this->visita
            ? ServiceReport::query()->where('visita_id', $this->visita)->whereKeyNot(array_key_first($this->esistenti))->orderBy('number')->pluck('number')
            : collect();

        return $altri->isNotEmpty()
            ? 'Modifichi solo questo. Fa parte di una visita con '.$altri->implode(', ').': per correggerli insieme usa "Modifica visita".'
            : null;
    }

    public function form(Form $form): Form
    {
        return $form
            ->statePath('data')
            ->schema([
                Forms\Components\Wizard::make(fn () => $this->passi())
                    ->startOnStep(fn () => $this->passoIniziale)
                    ->submitAction(new HtmlString(Blade::render(<<<'BLADE'
                        <x-filament::button type="submit" size="lg" color="success" icon="heroicon-o-check">
                            Salva
                        </x-filament::button>
                    BLADE))),
            ]);
    }

    /**
     * @return array<int, Step>
     */
    private function passi(): array
    {
        $voci = $this->passiMacchine();
        $n = count($voci);

        $passi = [$this->passoIntervento()];

        foreach ($voci as $i => $voce) {
            $passi[] = $this->passoMacchina($voce, $i + 1, $n, $voci[$i - 1] ?? null);
        }

        $passi[] = $this->passoFirma();

        return $passi;
    }

    /**
     * I passi macchina, in ordine: prima i rapportini gia' salvati (in
     * modifica), poi le macchine spuntate che un rapportino non ce l'hanno
     * ancora, poi "Senza una macchina precisa". La chiave e' l'id del
     * rapportino per i primi, quello della macchina (o GENERALE) per gli
     * altri: e' il nome del loro stato in lavori.
     *
     * @return array<int, array{chiave: string, macchina: ?MachineUnit, rapportino: ?ServiceReport}>
     */
    private function passiMacchine(): array
    {
        $voci = ServiceReport::query()
            ->with('machineUnit')
            ->whereKey(array_keys($this->esistenti))
            ->orderBy('number')
            ->get()
            ->map(fn (ServiceReport $r) => ['chiave' => $r->id, 'macchina' => $r->machineUnit, 'rapportino' => $r])
            ->all();

        foreach ($this->macchineScelte() as $macchina) {
            if (! in_array($macchina->id, $this->esistenti, true)) {
                $voci[] = ['chiave' => $macchina->id, 'macchina' => $macchina, 'rapportino' => null];
            }
        }

        if (in_array(self::GENERALE, $this->data['macchine'] ?? [], true) && ! in_array(null, $this->esistenti, true)) {
            $voci[] = ['chiave' => self::GENERALE, 'macchina' => null, 'rapportino' => null];
        }

        return $voci;
    }

    private function passoIntervento(): Step
    {
        return Step::make('Macchine')
            ->id('intervento')
            ->icon('heroicon-o-building-storefront')
            ->description('Cliente e macchine lavorate')
            ->schema([
                Forms\Components\Grid::make(['default' => 1, 'md' => 3])->schema([
                    // A volte si ha la matricola sotto gli occhi ma il cliente
                    // e' incerto (ragione sociale diversa dall'insegna, piu'
                    // locali dello stesso gestore): si parte dalla macchina.
                    Forms\Components\Select::make('cerca_matricola')
                        ->label('Cerca per matricola')
                        ->placeholder('Scrivi la matricola')
                        ->helperText('Se hai la matricola ma non sei sicuro del cliente: scegli la macchina, e cliente e macchina si compilano da soli.')
                        ->searchable()
                        ->getSearchResultsUsing(fn (string $search) => MachineUnit::query()
                            ->with(['product', 'material', 'currentCustomer'])
                            ->where('serial_number', 'like', '%'.trim($search).'%')
                            ->orderBy('serial_number')
                            ->limit(30)
                            ->get()
                            ->mapWithKeys(fn (MachineUnit $m) => [$m->id => static::nomeMacchina($m).' — '
                                .($m->currentCustomer ? DisplayName::customerOption($m->currentCustomer) : 'non presso un cliente')])
                            ->all())
                        ->live()
                        ->dehydrated(false)
                        ->afterStateUpdated(fn (?string $state) => $this->scegliMatricola($state))
                        ->hidden(fn () => $this->modifica)
                        ->columnSpan(['default' => 1, 'md' => 3]),
                    Forms\Components\Select::make('customer_id')
                        ->label('Cliente')
                        // La guida del modulo (TourRegistry) indica il cliente e la firma.
                        ->extraAttributes(['data-tour' => 'service-reports-field-customer'])
                        ->searchable()
                        ->getSearchResultsUsing(fn (string $search) => Customer::query()
                            ->where(fn ($q) => $q->where('company_name', 'like', "%{$search}%")
                                ->orWhere('first_name', 'like', "%{$search}%")
                                ->orWhere('last_name', 'like', "%{$search}%")
                                ->orWhere('city', 'like', "%{$search}%"))
                            ->orderBy('company_name')
                            ->limit(50)
                            ->get()
                            ->mapWithKeys(fn (Customer $c) => [$c->id => DisplayName::customerOption($c)])
                            ->all())
                        ->getOptionLabelUsing(fn ($value) => ($c = Customer::find($value)) ? DisplayName::customerOption($c) : null)
                        ->required()
                        ->live()
                        ->afterStateUpdated(fn (?string $state) => $this->scegliCliente($state))
                        // I rapportini salvati sono di un cliente: in modifica
                        // non si cambia qui.
                        ->disabled(fn () => $this->modifica)
                        ->columnSpan(['default' => 1, 'md' => 3]),
                    Forms\Components\Select::make('technician_id')
                        ->label('Tecnico')
                        ->options(fn () => User::query()->orderBy('name')->pluck('name', 'id'))
                        ->searchable()
                        ->required(),
                    Forms\Components\DatePicker::make('intervention_date')
                        ->label('Data intervento')
                        ->required(),
                ]),
                Forms\Components\CheckboxList::make('macchine')
                    ->label('Su quali macchine hai lavorato?')
                    ->helperText('Per ogni macchina spuntata c\'è un passo, e alla fine un rapportino.')
                    ->options(fn (Get $get) => [
                        ...$this->macchineDelCliente($get('customer_id'))
                            ->mapWithKeys(fn (MachineUnit $m) => [$m->id => static::nomeMacchina($m)])
                            ->all(),
                        self::GENERALE => 'Senza una macchina precisa',
                    ])
                    ->descriptions(fn (Get $get) => [
                        ...$this->macchineDelCliente($get('customer_id'))
                            ->mapWithKeys(fn (MachineUnit $m) => [$m->id => $this->descrizioneMacchina($m)])
                            ->all(),
                        self::GENERALE => 'Per esempio il lavaggio di tutti gli impianti del cliente.',
                    ])
                    // In modifica le macchine con un rapportino restano: per
                    // toglierne una si cancella il suo rapportino.
                    ->disableOptionWhen(fn (string $value) => in_array($value === self::GENERALE ? null : $value, $this->esistenti, true))
                    ->required(fn () => ! $this->modifica)
                    ->validationMessages(['required' => 'Spunta almeno una macchina.'])
                    ->live()
                    ->afterStateUpdated(fn (?array $state) => collect($state ?? [])->each(fn ($id) => $this->preparaLavoro($id)))
                    ->visible(fn (Get $get) => filled($get('customer_id')))
                    ->hintAction(
                        Forms\Components\Actions\Action::make('nuova_macchina')
                            ->label('Macchina non in elenco')
                            ->icon('heroicon-m-plus')
                            ->modalHeading('Aggiungi una macchina al cliente')
                            ->modalSubmitActionLabel('Aggiungi')
                            ->form([
                                Forms\Components\TextInput::make('serial_number')
                                    ->label('Matricola')
                                    ->helperText('Se non la conosci lascia vuoto: ne viene generata una segnaposto.')
                                    ->maxLength(255),
                                Forms\Components\TextInput::make('model_name')
                                    ->label('Modello')
                                    ->required()
                                    ->maxLength(255),
                            ])
                            ->action(function (array $data) {
                                $macchina = MachineUnit::create([
                                    'source' => MachineUnit::SOURCE_MANUALE,
                                    'serial_number' => ServiceReportResource::resolveUniqueMachineSerialNumber($data['serial_number'] ?? null),
                                    'model_name' => $data['model_name'],
                                ]);
                                $macchina->moveTo(Customer::find($this->data['customer_id']));
                                unset($this->macchineCache[$this->data['customer_id']]);

                                $this->data['macchine'] = [...($this->data['macchine'] ?? []), $macchina->id];
                                $this->preparaLavoro($macchina->id);
                            })
                    ),
            ]);
    }

    /**
     * Il passo di una macchina e' il modulo del rapportino, non una sua
     * copia ridotta: le stesse sezioni Macchina, Descrizione e Ricambi, con
     * le loro regole (tariffe del pagante, manutenzione per modello,
     * impianti e vie, "Fatturare a", avvisi Eureka e piani): se cambiano
     * la', cambiano anche qui.
     *
     * Quelle sezioni leggono customer_id e intervention_type dal proprio
     * livello: il passo li ripete dentro lavori.{chiave} (il cliente
     * nascosto, il tipo da scegliere). model() serve alle Select con
     * relationship() e al repeater dei materiali, che al salvataggio si
     * scrive sul rapportino di questo passo (salva()).
     *
     * @param  array{chiave: string, macchina: ?MachineUnit, rapportino: ?ServiceReport}  $voce
     * @param  array{chiave: string, macchina: ?MachineUnit, rapportino: ?ServiceReport}|null  $precedente
     */
    private function passoMacchina(array $voce, int $k, int $n, ?array $precedente): Step
    {
        ['chiave' => $chiave, 'macchina' => $macchina, 'rapportino' => $rapportino] = $voce;
        $bloccato = $rapportino?->isLocked() ?? false;

        return Step::make(static::nomePasso($voce))
            ->id('macchina-'.$chiave)
            ->icon($bloccato ? 'heroicon-o-lock-closed' : 'heroicon-o-wrench-screwdriver')
            ->description(collect([$n > 1 ? "Macchina {$k} di {$n}" : null, $rapportino?->number])->filter()->implode(' · ') ?: null)
            ->schema([
                Forms\Components\Group::make()
                    ->key("lavoro-{$chiave}")
                    ->statePath("lavori.{$chiave}")
                    ->model($rapportino ?? ServiceReport::class)
                    ->disabled($bloccato)
                    ->schema([
                        Forms\Components\Placeholder::make('su_eureka')
                            ->hiddenLabel()
                            ->visible($bloccato)
                            ->content("{$rapportino?->number} è già su Eureka: qui si guarda, non si modifica."),
                        Forms\Components\Actions::make([
                            Forms\Components\Actions\Action::make('uguale')
                                ->label('Stesso lavoro di '.($precedente ? static::nomePasso($precedente) : ''))
                                ->icon('heroicon-o-document-duplicate')
                                ->color('gray')
                                ->action(fn () => $this->copiaLavoro($precedente['chiave'], $chiave)),
                        ])->key("uguale-{$chiave}")->visible($precedente !== null && ! $bloccato),
                        Forms\Components\Placeholder::make('ultimo')
                            ->label('Ultima volta su questa macchina')
                            ->content(fn () => $macchina ? $this->ultimoIntervento($macchina, $rapportino) : null)
                            ->visible(fn () => $macchina && $this->ultimoIntervento($macchina, $rapportino) !== null),
                        Forms\Components\Hidden::make('customer_id'),
                        ServiceReportResource::campoTipoIntervento(),
                        // Senza una macchina precisa la matricola resta da
                        // scegliere, se serve, come sul modulo.
                        ServiceReportResource::sezioneMacchina(macchinaDellaVisita: $macchina !== null),
                        ServiceReportResource::sezioneDescrizione(),
                        ServiceReportResource::sezioneRicambi(),
                    ]),
            ]);
    }

    private function passoFirma(): Step
    {
        return Step::make('Firma')
            ->id('firma')
            ->icon('heroicon-o-pencil-square')
            ->description('Riepilogo e firma del cliente')
            ->schema([
                Forms\Components\Placeholder::make('riepilogo')
                    ->label(fn () => ($n = count($this->passiMacchine())) === 1
                        ? 'Il cliente firma per questo intervento'
                        : "Il cliente firma per questi {$n} interventi")
                    ->content(fn () => $this->riepilogo()),
                // Come sul modulo di prima, la firma si puo' anche dare
                // dopo: rapportino compilato dall'ufficio, o cliente non
                // presente. Poi c'e' "Fai firmare".
                Forms\Components\TextInput::make('customer_signature_name')
                    ->label('Nome e cognome (stampatello)')
                    ->required(fn (Get $get) => filled($get('customer_signature_path')))
                    ->maxLength(255),
                SignaturePad::make('customer_signature_path')
                    ->label('Firma')
                    ->extraAttributes(['data-tour' => 'service-reports-field-signature'])
                    ->helperText('Una firma per tutti: viene riportata su ciascun rapportino. Se il cliente non firma adesso, lo fai dopo con "Fai firmare".'),
                Forms\Components\Select::make('status')
                    ->label(fn () => count($this->passiMacchine()) === 1 ? 'Stato' : 'Stato dei rapportini')
                    ->options(fn () => ServiceReportResource::statusLabels())
                    ->required(),
            ]);
    }

    public function salva(): void
    {
        $data = $this->form->getState();
        $voci = collect($this->passiMacchine());

        if ($voci->isEmpty()) {
            Notification::make()->title('Spunta almeno una macchina')->warning()->send();

            return;
        }

        // Piu' rapportini fatti insieme sono una visita: quella che hanno
        // gia', o una nuova. Un rapportino da solo non ne ha bisogno.
        $visita = $this->visita ?? ($voci->count() > 1 ? (string) Str::uuid() : null);

        $comuni = [
            'visita_id' => $visita,
            // In modifica il cliente e' bloccato, quindi non arriva da getState().
            'customer_id' => $data['customer_id'] ?? $this->data['customer_id'],
            'technician_id' => $data['technician_id'],
            'intervention_date' => $data['intervention_date'],
            'status' => $data['status'],
            'customer_signature_name' => $data['customer_signature_name'] ?? null,
            // Lo stesso file per tutti, come nella firma in blocco: e' la
            // stessa firma, data una volta sola davanti al tecnico. Anche per
            // una macchina aggiunta dopo: si tiene quella che c'e'.
            'customer_signature_path' => $data['customer_signature_path'] ?? null,
        ];

        $rapportini = DB::transaction(fn () => $voci->map(function (array $voce) use ($data, $comuni) {
            ['chiave' => $chiave, 'macchina' => $macchina, 'rapportino' => $rapportino] = $voce;

            // Gia' su Eureka il CRM lo rispecchia, non lo riscrive; e chi
            // non puo' modificarlo lo vede soltanto.
            if ($rapportino && ($rapportino->isLocked() || ! auth()->user()?->can('update', $rapportino))) {
                return $rapportino;
            }

            $lavoro = $data['lavori'][$chiave] ?? [];

            // "Impianti e vie lavate" non e' una colonna: si applica dopo,
            // quando il rapportino ha un id.
            $impianti = $lavoro['lavaggio_impianti'] ?? [];
            unset($lavoro['lavaggio_impianti']);

            $macchinaDelPasso = ($macchina && ! $rapportino) ? [
                'machine_unit_id' => $macchina->id,
                'machine_product_id' => $macchina->product_id,
                'machine_material_id' => $macchina->material_id,
                'machine_serial_number' => $macchina->serial_number,
            ] : [];

            if ($rapportino) {
                $rapportino->update([...$lavoro, ...$comuni]);
            } else {
                $rapportino = ServiceReport::create([...$lavoro, ...$comuni, ...$macchinaDelPasso]);
            }

            // I ricambi del passo (repeater ->relationship('materialsUsed'))
            // si scrivono come sul modulo: saveRelationships() sul
            // rapportino di questo passo.
            $this->form->getComponent("lavoro-{$chiave}")
                ->model($rapportino)
                ->getChildComponentContainer()
                ->saveRelationships();

            LavaggioFields::syncLavaggioImpianti($rapportino, $impianti);

            if ($chiave === $this->chiaveLavaggioDiPartenza) {
                $this->collegaLavaggioDiPartenza($rapportino);
            }

            return $rapportino;
        }));

        $n = $rapportini->count();

        Notification::make()
            ->title($n === 1 ? "Rapportino {$rapportini->first()->number} salvato" : "{$n} rapportini salvati")
            ->body($n > 1 ? $rapportini->pluck('number')->implode(', ') : null)
            ->success()
            ->send();

        $this->redirect($n === 1
            ? ServiceReportResource::getUrl('view', ['record' => $rapportini->first()])
            : ServiceReportResource::getUrl('index'));
    }

    /**
     * Arrivando da "Crea rapportino" su una riga lavaggio
     * (LavaggiRelationManager::serviceReportCreateUrl()), il salvataggio ha
     * appena generato una riga lavaggio "gemella" per lo stesso piano
     * (ServiceReport::syncGeneratedLavaggi() cerca per service_report_id,
     * che sulla riga di partenza e' ancora vuoto). Si toglie la gemella e si
     * collega la riga di partenza, cosi' restano le note, le vie e il filtro
     * scritti a mano invece del generico "Generato da rapportino ...".
     */
    private function collegaLavaggioDiPartenza(ServiceReport $rapportino): void
    {
        $partenza = $this->lavaggioDiPartenza ? Lavaggio::find($this->lavaggioDiPartenza) : null;

        if (! $partenza || $partenza->service_report_id) {
            return;
        }

        Lavaggio::where('service_report_id', $rapportino->id)
            ->where('maintenance_schedule_id', $partenza->maintenance_schedule_id)
            ->whereKeyNot($partenza->id)
            ->get()
            ->each->delete();

        $partenza->update(['service_report_id' => $rapportino->id]);
    }

    /**
     * La tabella compatta del passo firma: macchina a sinistra, cosa e'
     * stato fatto a destra. Niente prezzi: la guarda il cliente.
     */
    private function riepilogo(): HtmlString
    {
        $tipi = ServiceReportResource::interventionTypeLabels();
        $righe = '';

        foreach ($this->passiMacchine() as $voce) {
            $lavoro = $this->data['lavori'][$voce['chiave']] ?? [];
            $materiali = Material::whereIn('id', collect($lavoro['materialsUsed'] ?? [])->pluck('material_id')->filter())->get()->keyBy('id');
            $voci = collect($lavoro['materialsUsed'] ?? [])
                ->filter(fn (array $riga) => $materiali->has($riga['material_id'] ?? null))
                ->map(fn (array $riga) => e($materiali[$riga['material_id']]->display_label)
                    .(filled($riga['quantity'] ?? null) && (float) $riga['quantity'] !== 1.0 ? ' ×'.e(str_replace('.', ',', (string) (float) $riga['quantity'])) : ''))
                ->implode(' · ');

            $righe .= '<tr class="border-t border-gray-200 first:border-t-0 dark:border-white/10">'
                .'<td class="py-2 pe-4 align-top font-medium text-gray-950 dark:text-white">'.e(static::nomePasso($voce)).'</td>'
                .'<td class="py-2 align-top text-gray-700 dark:text-gray-300">'
                .'<div class="font-medium">'.e($tipi[$lavoro['intervention_type'] ?? ''] ?? '—').'</div>'
                .'<div>'.e($lavoro['work_performed'] ?? '').'</div>'
                .($voci !== '' ? '<div class="text-sm text-gray-500 dark:text-gray-400">'.$voci.'</div>' : '')
                .'</td></tr>';
        }

        return new HtmlString('<table class="w-full text-sm">'.$righe.'</table>');
    }

    /**
     * Dalla matricola: il cliente e' quello presso cui la macchina sta, e la
     * macchina si spunta da sola. Una macchina che non e' presso nessuno
     * (in magazzino, rimossa) non basta a dire il cliente: si dice.
     */
    private function scegliMatricola(?string $macchinaId): void
    {
        $macchina = $macchinaId ? MachineUnit::find($macchinaId) : null;

        if (! $macchina) {
            return;
        }

        if (! $macchina->current_customer_id) {
            Notification::make()
                ->warning()
                ->title("La matricola {$macchina->serial_number} non risulta presso nessun cliente")
                ->body('Scegli il cliente qui sotto; per metterla presso di lui usa "Sposta" su Macchinari.')
                ->send();

            return;
        }

        if (($this->data['customer_id'] ?? null) !== $macchina->current_customer_id) {
            $this->scegliCliente($macchina->current_customer_id);
        }

        if (! in_array($macchina->id, $this->data['macchine'] ?? [], true)) {
            $this->data['macchine'] = [...($this->data['macchine'] ?? []), $macchina->id];
            $this->preparaLavoro($macchina->id);
        }

        $this->data['cerca_matricola'] = null;
    }

    private function scegliCliente(?string $clienteId): void
    {
        $this->data['customer_id'] = $clienteId;
        $this->data['macchine'] = [];
        $this->data['lavori'] = [];

        // Chi ha firmato l'ultima volta da questo cliente: di solito e'
        // la stessa persona, e sul telefono si scrive una volta in meno.
        $this->data['customer_signature_name'] = $clienteId
            ? ServiceReport::query()
                ->where('customer_id', $clienteId)
                ->whereNotNull('customer_signature_name')
                ->latest('signed_at')
                ->latest('intervention_date')
                ->value('customer_signature_name')
            : null;
    }

    /**
     * Lo stato di partenza del passo di una macchina appena spuntata: i
     * valori iniziali del modulo del rapportino (interruttori, vie, ...),
     * piu' cliente e macchina gia' scritti come quando si sceglie la
     * matricola sul modulo.
     */
    private function preparaLavoro(string $chiave): void
    {
        if (isset($this->data['lavori'][$chiave])) {
            return;
        }

        $macchina = $chiave === self::GENERALE ? null : MachineUnit::find($chiave);

        if (! $macchina && $chiave !== self::GENERALE) {
            return;
        }

        $this->data['lavori'][$chiave] = [];
        $this->form->getComponent("lavoro-{$chiave}")?->getChildComponentContainer()->fill();

        $this->data['lavori'][$chiave] = [
            ...$this->data['lavori'][$chiave],
            'customer_id' => $this->data['customer_id'],
            ...($macchina ? [
                'machine_unit_id' => $macchina->id,
                'machine_product_id' => $macchina->product_id,
                'machine_material_id' => $macchina->material_id,
                'machine_serial_number' => $macchina->serial_number,
            ] : []),
        ];
    }

    /**
     * "Stesso lavoro di ...": copia il passo della macchina precedente solo
     * quando il tecnico lo chiede, e solo quello che vale anche per questa:
     * tipo, testi e ricambi scritti a mano.
     *
     * Restano fuori le voci che dipendono da altro. Chiamata e manodopera
     * sono della visita: copiate, si pagherebbero due volte. Lavaggio e
     * sanificazione vengono dagli impianti, che sono di ciascuna macchina.
     * La manutenzione ordinaria passa solo se il codice e' lo stesso (due
     * X20 si', una X20 e una Cimbali no: il codice e' del modello).
     */
    public function copiaLavoro(string $da, string $a): void
    {
        $sorgente = $this->data['lavori'][$da] ?? null;
        $destinazione = $this->data['lavori'][$a] ?? null;

        if (! $sorgente || $destinazione === null) {
            return;
        }

        $chiaviVoci = ['_chiamata_material_key', '_manodopera_material_key', '_lavaggio_base_material_key', '_lavaggio_ult_material_key', '_sanificazione_material_key', '_manutenzione_material_key'];
        $escluse = collect($chiaviVoci)->map(fn ($k) => $sorgente[$k] ?? null)->filter()->all();

        $stessaManutenzione = filled($sorgente['_manutenzione_material_key'] ?? null)
            && TariffeIntervento::manutenzione(MachineUnit::find($sorgente['machine_unit_id'] ?? null), $this->cliente())
                === TariffeIntervento::manutenzione(MachineUnit::find($destinazione['machine_unit_id'] ?? null), $this->cliente());

        $righe = collect($sorgente['materialsUsed'] ?? [])
            ->reject(fn ($riga, $chiave) => in_array($chiave, $escluse, true))
            ->all();

        if ($stessaManutenzione) {
            $righe[$sorgente['_manutenzione_material_key']] = $sorgente['materialsUsed'][$sorgente['_manutenzione_material_key']];
        }

        $this->data['lavori'][$a] = [
            ...$destinazione,
            'intervention_type' => $sorgente['intervention_type'] ?? null,
            'problem_description' => $sorgente['problem_description'] ?? null,
            'work_performed' => $sorgente['work_performed'] ?? null,
            'notes' => $sorgente['notes'] ?? null,
            'materialsUsed' => $righe,
            'add_manutenzione_material' => $stessaManutenzione,
            '_manutenzione_material_key' => $stessaManutenzione ? $sorgente['_manutenzione_material_key'] : null,
        ];
    }

    private function cliente(): ?Customer
    {
        return filled($this->data['customer_id'] ?? null) ? Customer::find($this->data['customer_id']) : null;
    }

    /**
     * @return Collection<int, MachineUnit>
     */
    private function macchineDelCliente(?string $clienteId): Collection
    {
        if (! $clienteId) {
            return collect();
        }

        return $this->macchineCache[$clienteId] ??= MachineUnit::query()
            ->with(['product', 'material'])
            ->where('current_customer_id', $clienteId)
            ->orderBy('serial_number')
            ->get();
    }

    /**
     * Le macchine spuntate, nell'ordine dell'elenco del cliente: e' l'ordine
     * dei passi.
     *
     * @return Collection<int, MachineUnit>
     */
    private function macchineScelte(): Collection
    {
        $scelte = $this->data['macchine'] ?? [];

        return $this->macchineDelCliente($this->data['customer_id'] ?? null)
            ->filter(fn (MachineUnit $m) => in_array($m->id, $scelte, true))
            ->values();
    }

    /**
     * @return Collection<int, MaintenanceSchedule>
     */
    private function pianiLavaggio(MachineUnit $macchina): Collection
    {
        return MaintenanceSchedule::query()
            ->where('customer_id', $macchina->current_customer_id)
            ->where('machine_unit_id', $macchina->id)
            ->where('type', MaintenanceSchedule::TYPE_LAVAGGIO)
            ->where('status', MaintenanceSchedule::STATUS_ATTIVO)
            ->orderBy('beverage_type')
            ->get();
    }

    private function descrizioneMacchina(MachineUnit $macchina): string
    {
        $piani = $this->pianiLavaggio($macchina)->map(fn (MaintenanceSchedule $p) => MaintenanceScheduleResource::impiantoHero($p));

        return $piani->isNotEmpty()
            ? $piani->implode(' · ')
            : ($this->ultimoIntervento($macchina) ?? 'Nessun intervento registrato');
    }

    private function ultimoIntervento(MachineUnit $macchina, ?ServiceReport $escluso = null): ?string
    {
        $ultimo = ServiceReport::query()
            ->where('machine_unit_id', $macchina->id)
            ->when($escluso, fn ($q) => $q->whereKeyNot($escluso->id)->where('intervention_date', '<=', $escluso->intervention_date))
            ->latest('intervention_date')
            ->first();

        if (! $ultimo) {
            return null;
        }

        return collect([
            $ultimo->intervention_date?->format('d/m/Y'),
            ServiceReportResource::interventionTypeLabels()[$ultimo->intervention_type] ?? null,
            filled($ultimo->work_performed) ? mb_strimwidth($ultimo->work_performed, 0, 80, '…') : null,
        ])->filter()->implode(' · ');
    }

    /**
     * @param  array{chiave: string, macchina: ?MachineUnit, rapportino: ?ServiceReport}  $voce
     */
    private static function nomePasso(array $voce): string
    {
        if ($voce['macchina']) {
            return static::nomeMacchina($voce['macchina']);
        }

        return $voce['rapportino']?->number ?? 'Senza una macchina precisa';
    }

    public static function nomeMacchina(MachineUnit $macchina): string
    {
        return trim($macchina->display_name.' · '.$macchina->serial_number, ' ·');
    }
}
