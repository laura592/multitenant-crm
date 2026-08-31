<?php

namespace App\Filament\Resources;

use App\Filament\Forms\Components\SignaturePad;
use App\Filament\Forms\CustomerContactFields;
use App\Filament\Forms\CustomerFiscalFields;
use App\Filament\Resources\CustomerResource;
use App\Filament\Resources\MaintenanceScheduleResource;
use App\Filament\Resources\ServiceReportResource\Pages;
use App\Jobs\SendServiceReportToGestionaleJob;
use App\Mail\ServiceReportMail;
use App\Models\Customer;
use App\Models\Lavaggio;
use App\Models\MachineUnit;
use App\Models\MaintenanceSchedule;
use App\Models\Material;
use App\Models\Product;
use App\Models\ServiceReport;
use App\Support\DisplayName;
use App\Support\OutsideLivewireRender;
use App\Support\TariffeIntervento;
use Barryvdh\DomPDF\Facade\Pdf;
use Filament\Facades\Filament;
use Illuminate\Support\Arr;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Infolists\Components\Actions\Action as InfolistAction;
use Filament\Infolists\Components\Actions as InfolistActions;
use Filament\Infolists\Components\ImageEntry;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\Section as InfolistSection;
use Filament\Infolists\Components\TextEntry;
use Filament\Infolists\Infolist;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Support\Exceptions\Halt;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\HtmlString;
use Illuminate\Support\Str;

class ServiceReportResource extends Resource
{
    protected static ?string $model = ServiceReport::class;

    protected static ?string $navigationIcon = 'heroicon-o-clipboard-document-check';

    protected static ?string $navigationGroup = 'Interventi tecnici';

    protected static ?string $navigationLabel = 'Rapportini tecnici';

    protected static ?string $modelLabel = 'Rapportino';

    protected static ?string $pluralModelLabel = 'Rapportini tecnici';

    /**
     * Materiale per la riga "manodopera" (toggle "Manodopera" in "Ricambi/
     * materiali utilizzati", vedi syncManodoperaMaterial()), al posto dei
     * vecchi campi orario arrivo/uscita. Aggiunta solo su scelta esplicita
     * del tecnico: prima si aggiungeva da sola cambiando "Tipo intervento",
     * ma caricava una riga (e quindi un costo) senza che nessuno l'avesse
     * chiesta esplicitamente.
     */
    private const MANODOPERA_MATERIAL_CODE = 'ORE';

    /**
     * "LAVAGGIO 2 VIE" e' la tariffa minima gia' agevolata per i tecnici,
     * dovuta anche lavando una sola via; "ULTERIORE VIA LAVATA" copre solo le
     * vie oltre la seconda. Vedi syncLavaggioViaMaterials() sotto.
     */
    private const LAVAGGIO_VIE_BASE_MATERIAL_CODE = 'LAV2';

    private const LAVAGGIO_VIE_ULTERIORE_MATERIAL_CODE = 'ULTVIA';

    /**
     * Convenzione gia' in uso (vedi ApplyEurekaBillingDestinazione) per le
     * macchine create al volo dal campo "Macchina (matricola tracciata)"
     * quando il tecnico non conosce la matricola reale.
     */
    private const MACHINE_UNIT_NO_SERIAL_PLACEHOLDER = '0000000';


    /**
     * Precarica le relazioni che l'elenco legge per ogni riga.
     *
     * Senza, Filament fa una query per riga per ciascuna relazione: sui
     * rapportini erano 56 query per 25 righe invece di 8, e il conto cresce
     * con la paginazione.
     *
     * customer e technician sono colonne dell'elenco. Le altre tre servono a
     * invoiceRecipient(), che risale al pagante congelato sul documento, poi a
     * quello della macchina, poi a quello del cliente: senza precaricarle,
     * la colonna "Fatturare a" da sola fa tre query per riga.
     */
    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with(['customer.billingCustomer', 'technician', 'machineUnit.billingCustomer', 'billingCustomer']);
    }

    public static function infolist(Infolist $infolist): Infolist
    {
        return $infolist->schema([
            // Il pulsante "Modifica" scompare da solo quando isLocked() e'
            // vero (ServiceReportPolicy::update()) — questo banner spiega
            // perche', altrimenti sembra un bottone mancante per errore.
            TextEntry::make('_gestionale_lock_notice')
                ->hiddenLabel()
                ->columnSpanFull()
                ->visible(fn (ServiceReport $record) => $record->isLocked())
                ->state(fn (ServiceReport $record) => $record->source === ServiceReport::SOURCE_EUREKA
                    ? 'Rapportino arrivato da Eureka: non è modificabile da qui.'
                    : 'Rapportino passato in gestionale: da qui in poi si corregge in Eureka.')
                ->extraAttributes([
                    'class' => 'rounded-lg border border-amber-300 bg-amber-50 px-3 py-2 text-sm text-amber-800 dark:border-amber-800 dark:bg-amber-950 dark:text-amber-200',
                ]),
            // Stesso pattern di QuoteResource: riepilogo a colpo d'occhio in
            // alto, i dettagli (incl. orari) restano nelle sezioni sotto
            // senza ripetere qui i campi gia' mostrati nell'hero.
            InfolistSection::make('Panoramica rapida')
                ->columns(12)
                ->columnSpanFull()
                ->extraAttributes([
                    'class' => 'fi-quick-overview rounded-2xl border border-slate-200 bg-gradient-to-br from-slate-50 via-white to-sky-50 shadow-sm',
                ])
                ->schema([
                    TextEntry::make('number')->label('Numero')->columnSpan(2),
                    TextEntry::make('customer.full_name')->label('Cliente')->columnSpan(3)
                        ->formatStateUsing(fn (?string $state) => DisplayName::titleCase($state)),
                    TextEntry::make('technician.name')->label('Tecnico')->columnSpan(3),
                    TextEntry::make('intervention_type')
                        ->label('Tipo intervento')
                        ->badge()
                        ->formatStateUsing(fn (string $state) => match ($state) {
                            ServiceReport::TYPE_INSTALLAZIONE => 'Installazione',
                            ServiceReport::TYPE_MANUTENZIONE_ORDINARIA => 'Manutenzione ordinaria',
                            ServiceReport::TYPE_MANUTENZIONE_STRAORDINARIA => 'Manutenzione straordinaria',
                            ServiceReport::TYPE_RIPARAZIONE => 'Riparazione',
                            ServiceReport::TYPE_GARANZIA => 'Garanzia',
                            ServiceReport::TYPE_SANIFICAZIONE => 'Sanificazione',
                            default => $state,
                        })
                        ->columnSpan(2),
                    TextEntry::make('intervention_date')->label('Data')->date()->columnSpan(1),
                    TextEntry::make('status')
                        ->label('Stato')
                        ->badge()
                        ->formatStateUsing(fn (string $state) => self::statusLabels()[$state] ?? ucfirst($state))
                        ->color(fn (string $state) => self::statusColors()[$state] ?? 'gray')
                        ->columnSpan(1),
                ]),
            InfolistSection::make('Macchina')
                ->columns(3)
                ->schema([
                    TextEntry::make('machine_model_name')->label('Modello macchina')->placeholder('—'),
                    TextEntry::make('machine_serial_number')->label('Matricola')->placeholder('—'),
                    // Sui lavaggi la matricola da sola non basta: quante vie sono
                    // state lavate e' il dato che il cliente verifica in fattura.
                    TextEntry::make('vie_lavate')
                        ->label('Vie lavate')
                        ->state(fn (ServiceReport $record) => $record->totalLinesWashed())
                        ->visible(fn (ServiceReport $record) => $record->countsAsLavaggio()
                            && $record->totalLinesWashed() !== null)
                        ->placeholder('—'),
                    TextEntry::make('machine_unit_display_name')->label('Macchina (matricola tracciata)')->placeholder('—'),
                    TextEntry::make('invoice_recipient')
                        ->label('Fatturare a')
                        ->state(fn (ServiceReport $record) => DisplayName::titleCase($record->invoiceRecipient()->full_name))
                        ->placeholder('—'),
                ]),
            InfolistSection::make('Descrizione')
                ->schema([
                    TextEntry::make('problem_description')->label('Problema riscontrato')->placeholder('—'),
                    TextEntry::make('work_performed')->label('Lavoro svolto'),
                    TextEntry::make('notes')->label('Note')->placeholder('—'),
                ]),
            InfolistSection::make('Ricambi/materiali utilizzati')
                ->schema([
                    RepeatableEntry::make('materialsUsed')
                        ->label('')
                        ->placeholder('Nessun ricambio utilizzato')
                        ->columns(4)
                        ->schema([
                            TextEntry::make('material.display_label')->label('Materiale'),
                            TextEntry::make('quantity')->label('Quantità'),
                            TextEntry::make('unit_cost_snapshot')->label('Prezzo unit.')->money('EUR')->placeholder('—'),
                            TextEntry::make('line_total_snapshot')->label('Importo')->money('EUR')->placeholder('—'),
                        ]),
                    // Somma degli importi riga: solo dove valorizzato (da
                    // Eureka), i rapportini compilati a mano oggi non hanno
                    // ancora un importo per materiale.
                    TextEntry::make('materials_total')
                        ->label('Totale')
                        ->state(fn (ServiceReport $record) => $record->materialsUsed->sum('line_total_snapshot'))
                        ->money('EUR')
                        ->weight('bold')
                        ->visible(fn (ServiceReport $record) => $record->materialsUsed->sum('line_total_snapshot') > 0),
                ]),
            // Rapportini compilati prima del passaggio a Materiali avevano i
            // ricambi salvati come Product (partsUsed) — sezione visibile
            // solo per lo storico, non tocchiamo quei dati.
            InfolistSection::make('Ricambi/materiali utilizzati (storico)')
                ->visible(fn (ServiceReport $record) => $record->partsUsed->isNotEmpty())
                ->schema([
                    RepeatableEntry::make('partsUsed')
                        ->label('')
                        ->columns(2)
                        ->schema([
                            TextEntry::make('product.name')->label('Prodotto'),
                            TextEntry::make('quantity')->label('Quantità'),
                        ]),
                ]),
            InfolistSection::make('Firma cliente')
                ->schema([
                    TextEntry::make('customer_signature_name')
                        ->label('Nome e cognome')
                        ->placeholder('—'),
                    ImageEntry::make('customer_signature_path')
                        ->label('')
                        ->disk('public')
                        // Il path puo' restare in DB anche se il file non c'e' piu'
                        // su disco (es. storage non persistito): senza questo
                        // controllo l'ImageEntry prova comunque a caricare
                        // un'immagine rotta invece di mostrare il placeholder.
                        ->getStateUsing(fn (ServiceReport $record) => ($record->customer_signature_path && Storage::disk('public')->exists($record->customer_signature_path))
                            ? $record->customer_signature_path
                            : null)
                        ->placeholder('Non ancora firmato'),
                ]),
            InfolistSection::make('Storico invii email')
                ->visible(fn (ServiceReport $record) => $record->emails->isNotEmpty())
                ->schema([
                    RepeatableEntry::make('emails')
                        ->hiddenLabel()
                        ->contained(false)
                        ->schema([
                            TextEntry::make('recipient_email')->label('Destinatario'),
                            TextEntry::make('created_at')->label('Inviato il')->dateTime('d/m/Y H:i'),
                            TextEntry::make('status')
                                ->label('Esito')
                                ->badge()
                                ->formatStateUsing(fn (string $state) => $state === 'sent' ? 'Inviato' : 'Fallito')
                                ->color(fn (string $state) => $state === 'sent' ? 'success' : 'danger'),
                            TextEntry::make('error_message')
                                ->label('Errore')
                                ->placeholder('—')
                                ->columnSpanFull()
                                ->visible(fn ($record) => filled($record->error_message)),
                            InfolistActions::make([
                                InfolistAction::make('preview')
                                    ->label('Anteprima')
                                    ->icon('heroicon-o-eye')
                                    ->color('gray')
                                    ->modalHeading(fn ($record) => "Anteprima email — {$record->recipient_email}")
                                    ->modalContent(fn ($record) => new HtmlString(
                                        '<iframe srcdoc="'.e((new ServiceReportMail($record->serviceReport, ''))->render()).'" style="width:100%;height:70vh;border:0;border-radius:0.5rem;background:#fff;"></iframe>'
                                    ))
                                    ->modalSubmitAction(false)
                                    ->modalCancelActionLabel('Chiudi')
                                    ->modalWidth('4xl'),
                            ]),
                        ])
                        ->columns(3),
                ]),
            InfolistSection::make('Gestionale')
                ->columns(4)
                ->schema([
                    TextEntry::make('gestionale_sync_status')
                        ->label('Stato invio Eureka')
                        ->badge()
                        // Vedi la stessa nota sulla colonna Eureka nella tabella:
                        // un rapportino ripescato da un import (eureka_service_report_id
                        // valorizzato) e' gia' su Eureka anche se non e' mai
                        // passato da un invio CRM->Eureka.
                        ->state(fn (ServiceReport $record) => self::gestionaleDisplayState($record))
                        ->formatStateUsing(fn (?string $state) => self::gestionaleSyncStatusLabels()[$state] ?? self::gestionaleSyncStatusLabels()['none'])
                        ->color(fn (?string $state) => self::gestionaleSyncStatusColors()[$state] ?? self::gestionaleSyncStatusColors()['none']),
                    TextEntry::make('gestionale_number')->label('Numero gestionale')->placeholder('—'),
                    // Data del documento Eureka, distinta da "Data" (in alto,
                    // intervention_date): sulla scheda lavoro Eureka le due
                    // possono differire di giorni, il rapportino viene
                    // spesso archiviato in ufficio dopo l'intervento vero.
                    TextEntry::make('gestionale_document_date')->label('Data documento Eureka')->date()->placeholder('—'),
                    TextEntry::make('gestionale_synced_at')->label('Ultimo invio riuscito')->dateTime('d/m/Y H:i')->placeholder('—'),
                    // "destinazione" della scheda lavoro Eureka: chi paga
                    // davvero per questo intervento, se diverso
                    // dall'intestatario (doc API §6.1) - letto da
                    // ImportEurekaServiceReports solo con --with-detail.
                    // Mostrato qui cosi' come lo dice Eureka, anche quando
                    // non esiste ancora un Customer locale corrispondente
                    // (link solo se risolto).
                    TextEntry::make('eureka_destinazione_label')
                        ->label('Pagante secondo Eureka')
                        ->placeholder('— (paga il cliente stesso)')
                        ->color(fn (ServiceReport $record) => filled($record->eureka_destinazione_label) ? 'primary' : 'gray')
                        ->url(fn (ServiceReport $record) => $record->eurekaDestinazionePayer()
                            ? CustomerResource::getUrl('view', ['record' => $record->eurekaDestinazionePayer()], tenant: $record->tenant)
                            : null)
                        ->columnSpan(2),
                    TextEntry::make('gestionale_sync_error')->label('Errore')->placeholder('—')->columnSpanFull(),
                ]),
        ]);
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            // Stesso pattern di QuoteResource: riepilogo di sola lettura,
            // visibile solo in modifica (in creazione non c'e' ancora nulla
            // da riepilogare).
            Forms\Components\Section::make('Panoramica rapida')
                ->columns(6)
                ->columnSpanFull()
                ->visible(fn (?ServiceReport $record) => $record !== null)
                ->extraAttributes([
                    'class' => 'fi-quick-overview rounded-2xl border border-slate-200 bg-gradient-to-br from-slate-50 via-white to-sky-50 shadow-sm',
                ])
                ->schema([
                    Forms\Components\Placeholder::make('summary_number')
                        ->label('Numero')
                        ->content(fn (?ServiceReport $record) => $record?->number ?? '—'),
                    Forms\Components\Placeholder::make('summary_customer')
                        ->label('Cliente')
                        ->content(fn (?ServiceReport $record) => DisplayName::titleCase($record?->customer?->full_name) ?? '—'),
                    Forms\Components\Placeholder::make('summary_technician')
                        ->label('Tecnico')
                        ->content(fn (?ServiceReport $record) => $record?->technician?->name ?? '—'),
                    Forms\Components\Placeholder::make('summary_type')
                        ->label('Tipo intervento')
                        ->content(fn (?ServiceReport $record) => match ($record?->intervention_type) {
                            ServiceReport::TYPE_INSTALLAZIONE => 'Installazione',
                            ServiceReport::TYPE_MANUTENZIONE_ORDINARIA => 'Manutenzione ordinaria',
                            ServiceReport::TYPE_MANUTENZIONE_STRAORDINARIA => 'Manutenzione straordinaria',
                            ServiceReport::TYPE_RIPARAZIONE => 'Riparazione',
                            ServiceReport::TYPE_GARANZIA => 'Garanzia',
                            ServiceReport::TYPE_SANIFICAZIONE => 'Sanificazione',
                            default => '—',
                        }),
                    Forms\Components\Placeholder::make('summary_date')
                        ->label('Data')
                        ->content(fn (?ServiceReport $record) => $record
                            ? Carbon::parse($record->getAttribute('intervention_date'))->format('d/m/Y')
                            : '—'),
                    Forms\Components\Placeholder::make('summary_status')
                        ->label('Stato')
                        ->content(fn (?ServiceReport $record) => new HtmlString(
                            '<span class="inline-flex items-center rounded-full px-2.5 py-1 text-xs font-medium bg-slate-100 text-slate-700 dark:bg-slate-800 dark:text-slate-300">'
                                .($record ? (self::statusLabels()[$record->status] ?? ucfirst($record->status)) : '—')
                                .'</span>'
                        )),
                ]),
            Forms\Components\Section::make('Intervento')
                ->columns(3)
                ->schema([
                    Forms\Components\TextInput::make('number')
                        ->label('Numero')
                        ->disabled()
                        ->dehydrated(false)
                        ->visibleOn('edit'),
                    Forms\Components\Select::make('customer_id')
                        ->label('Cliente')
                        ->extraAttributes(['data-tour' => 'service-reports-field-customer'])
                        ->relationship('customer', 'company_name', modifyQueryUsing: fn ($query) => $query->orderBy('company_name'))
                        ->getOptionLabelFromRecordUsing(fn ($record) => DisplayName::customerOption($record))
                        ->searchable(['company_name', 'first_name', 'last_name', 'city'])
                        ->preload()
                        ->required()
                        ->live()
                        // Prefill arrivando dall'azione "Crea rapportino" su un
                        // macchinario (MachineUnitResource): vedi anche
                        // machine_unit_id sotto, stesso query param.
                        ->default(fn () => request()->query('customer_id'))
                        // La macchina tracciata sotto e' filtrata per cliente:
                        // cambiando cliente la selezione fatta in precedenza non
                        // ha piu' senso.
                        ->afterStateUpdated(function (Forms\Set $set) {
                            $set('machine_unit_id', null);
                        })
                        ->createOptionForm([
                            Forms\Components\TextInput::make('company_name')->label('Ragione sociale'),
                            Forms\Components\TextInput::make('first_name')->label('Nome'),
                            Forms\Components\TextInput::make('last_name')->label('Cognome'),
                            ...CustomerContactFields::schema(),
                            ...CustomerFiscalFields::schema(),
                        ])
                        ->editOptionForm([
                            Forms\Components\TextInput::make('company_name')->label('Ragione sociale'),
                            Forms\Components\TextInput::make('first_name')->label('Nome'),
                            Forms\Components\TextInput::make('last_name')->label('Cognome'),
                            ...CustomerContactFields::schema(),
                            ...CustomerFiscalFields::schema(),
                        ])
                        // L'editOptionForm sopra salva il Customer vero passando dal
                        // meccanismo generico di Filament sul Select, non dalla pagina
                        // CustomerResource\Pages\EditCustomer — senza questo hook la
                        // segnalazione "da aggiornare su Eureka" (vedi
                        // Customer::notifyGestionaleReviewIfLinked()) non scatterebbe mai
                        // per le modifiche fatte da qui.
                        ->editOptionAction(fn (Forms\Components\Actions\Action $action) => $action->after(
                            fn (Forms\Components\Select $component) => $component->getSelectedRecord()
                                ?->notifyGestionaleReviewIfLinked(array_keys($component->getSelectedRecord()->getChanges()))
                        )),
                    Forms\Components\Select::make('technician_id')
                        ->label('Tecnico')
                        ->relationship('technician', 'name')
                        ->default(fn () => auth()->id())
                        ->searchable()
                        ->preload()
                        ->required(),
                    Forms\Components\Select::make('intervention_type')
                        ->label('Tipo intervento')
                        ->extraAttributes(['data-tour' => 'service-reports-field-type'])
                        ->options([
                            ServiceReport::TYPE_INSTALLAZIONE => 'Installazione',
                            ServiceReport::TYPE_MANUTENZIONE_ORDINARIA => 'Manutenzione ordinaria',
                            ServiceReport::TYPE_MANUTENZIONE_STRAORDINARIA => 'Manutenzione straordinaria',
                            ServiceReport::TYPE_RIPARAZIONE => 'Riparazione',
                            ServiceReport::TYPE_GARANZIA => 'Garanzia',
                            ServiceReport::TYPE_SANIFICAZIONE => 'Sanificazione',
                        ])
                        ->required()
                        // La riga "manodopera" (ore lavorate) non si aggiunge piu'
                        // da sola cambiando questo campo: vedi il toggle
                        // "Manodopera" in "Ricambi/materiali utilizzati" piu' sotto,
                        // scelta esplicita del tecnico invece che automatica.
                        ->live(),
                    Forms\Components\DatePicker::make('intervention_date')
                        ->label('Data intervento')
                        ->default(now())
                        ->required(),
                    Forms\Components\Select::make('status')
                        ->label('Stato')
                        // "In gestionale" non e' scegliibile a mano: lo scrive
                        // il job di invio quando Eureka accetta il documento.
                        ->options(fn () => Arr::except(self::statusLabels(), ['in_gestionale']))
                        ->default('bozza')
                        ->required(),
                ]),
            Forms\Components\Section::make('Macchina')
                ->columns(3)
                ->schema([
                    Forms\Components\Select::make('machine_unit_id')
                        ->label('Macchina (matricola tracciata)')
                        ->relationship(
                            'machineUnit',
                            'serial_number',
                            // Se il cliente e' gia' selezionato filtriamo per lui, ma la
                            // matricola si puo' anche scegliere per prima (vedi
                            // afterStateUpdated sotto, che poi compila il cliente).
                            modifyQueryUsing: fn (Builder $query, Forms\Get $get) => $get('customer_id')
                                ? $query->where('current_customer_id', $get('customer_id'))
                                : $query,
                        )
                        ->getOptionLabelFromRecordUsing(fn ($record) => $record->display_name.' — '.$record->serial_number
                            .(self::isMachineUnitLinkedToEureka($record) ? ' — ✓ Eureka' : ''))
                        ->searchable()
                        ->preload()
                        ->live()
                        // Prefill arrivando dall'azione "Crea rapportino" su un
                        // macchinario (MachineUnitResource).
                        ->default(fn () => request()->query('machine_unit_id'))
                        // Scegliere qui la matricola tracciata compila da sola modello
                        // e matricola sotto: prima erano tre campi indipendenti da
                        // riempire a mano (facile sbagliare/dimenticarne uno). Compila
                        // anche il cliente, cosi' si puo' partire dalla matricola senza
                        // doverlo selezionare prima a mano.
                        ->afterStateUpdated(function (Forms\Set $set, ?string $state) {
                            if ($state === null) {
                                return;
                            }

                            $machineUnit = MachineUnit::find($state);
                            $set('machine_product_id', $machineUnit?->product_id);
                            // L'articolo di gestionale della matricola: e' quello
                            // che finisce in sl_articolo all'invio a Eureka
                            // quando la macchina non e' a listino.
                            $set('machine_material_id', $machineUnit?->material_id);
                            $set('machine_serial_number', $machineUnit?->serial_number);

                            if ($machineUnit?->current_customer_id) {
                                $set('customer_id', $machineUnit->current_customer_id);
                            }
                        })
                        ->helperText('Scegliendo la matricola si compilano da soli cliente, modello e matricola qui sotto.')
                        // Se la matricola non e' ancora tracciata in CRM, prima
                        // bisognava uscire da qui e crearla da Macchinari — stesso
                        // "+" gia' presente sul cliente. moveTo() (non un
                        // update diretto di current_customer_id) per rispettare lo
                        // stesso invariante di MachineUnitResource: tiene lo storico
                        // posizionamenti coerente anche per una macchina creata al volo.
                        ->createOptionForm([
                            Forms\Components\TextInput::make('serial_number')
                                ->label('Matricola')
                                ->helperText('Se non la conosci lascia vuoto o scrivi "'.self::MACHINE_UNIT_NO_SERIAL_PLACEHOLDER.'": ne viene generata una segnaposto univoca in automatico.')
                                ->maxLength(255),
                            Forms\Components\Select::make('product_id')
                                ->label('Modello (da catalogo)')
                                ->relationship('product', 'name', modifyQueryUsing: fn ($query) => $query->where('type', Product::TYPE_MACHINE))
                                ->searchable()
                                ->preload(),
                            Forms\Components\Select::make('material_id')
                                ->label('Articolo gestionale (Eureka)')
                                ->relationship('material', 'code')
                                ->getOptionLabelFromRecordUsing(fn (Material $record) => $record->display_label.' — '.$record->code)
                                ->searchable(['code', 'type', 'variant'])
                                ->helperText('Per le macchine non a listino: e\' il codice con cui Eureka la conosce.'),
                            Forms\Components\TextInput::make('model_name')
                                ->label('Modello (testo libero)')
                                ->helperText('Solo se non e\' a catalogo ne\' a gestionale.')
                                ->maxLength(255),
                        ])
                        ->createOptionUsing(function (array $data, Forms\Get $get) {
                            $machineUnit = MachineUnit::create([
                                'source' => MachineUnit::SOURCE_MANUALE,
                                'serial_number' => self::resolveUniqueMachineSerialNumber($data['serial_number'] ?? null),
                                'product_id' => $data['product_id'] ?? null,
                                'material_id' => $data['material_id'] ?? null,
                                'model_name' => $data['model_name'] ?? null,
                            ]);

                            $machineUnit->moveTo($get('customer_id') ? Customer::find($get('customer_id')) : null);

                            return $machineUnit->id;
                        }),
                    // Una sanificazione spesso copre piu' impianti dello stesso
                    // cliente in una sola visita, ognuno con le sue vie lavate
                    // (es. Birra 2 vie, Vino 5 vie): machine_unit_id sopra resta
                    // per singola macchina/matricola. Una riga qui = un piano
                    // esplicitamente coperto da questa visita, con le vie
                    // lavate quella volta — vince sulla regola implicita di
                    // ServiceReport::syncMaintenanceSchedule() ("tutti i piani
                    // attivi del cliente"/quello di machine_unit_id); nessuna
                    // riga = comportamento di sempre. Non ->relationship():
                    // Filament non porta dati extra (lines_washed) con un
                    // binding automatico su una BelongsToMany, il collegamento
                    // piani + scrittura vie va fatto a mano (vedi
                    // CreateServiceReport/EditServiceReport, entrambi passano
                    // da ServiceReportResource::syncLavaggioImpianti()).
                    Forms\Components\Repeater::make('lavaggio_impianti')
                        ->label('Impianti e vie lavate')
                        ->schema([
                            Forms\Components\Select::make('maintenance_schedule_id')
                                ->label('Impianto')
                                ->options(function (Forms\Get $get) {
                                    $customerId = $get('../../customer_id');

                                    if (! $customerId) {
                                        return [];
                                    }

                                    return MaintenanceSchedule::query()
                                        ->where('customer_id', $customerId)
                                        ->where('type', MaintenanceSchedule::TYPE_LAVAGGIO)
                                        ->where('status', MaintenanceSchedule::STATUS_ATTIVO)
                                        ->get()
                                        ->mapWithKeys(fn (MaintenanceSchedule $record) => [$record->id => MaintenanceScheduleResource::impiantoHero($record)]);
                                })
                                ->required()
                                ->searchable()
                                ->live()
                                ->afterStateUpdated(function (Forms\Set $set, ?string $state) {
                                    if (! $state) {
                                        return;
                                    }

                                    $schedule = MaintenanceSchedule::find($state);
                                    $set('lines_washed', $schedule?->lines_count);
                                }),
                            Forms\Components\TextInput::make('lines_washed')
                                ->label('Vie lavate')
                                ->numeric()
                                ->minValue(0),
                        ])
                        ->columns(2)
                        ->visible(fn (Forms\Get $get) => $get('intervention_type') === ServiceReport::TYPE_SANIFICAZIONE)
                        ->helperText('Lascia vuoto (nessuna riga) per applicarla a tutti i piani lavaggio attivi del cliente, senza vie specifiche per impianto (comportamento di sempre). Aggiungi una riga per ogni impianto coperto da questa visita.')
                        ->addActionLabel('Aggiungi impianto')
                        ->defaultItems(0)
                        ->columnSpanFull(),
                    // Il collegamento Eureka manca spesso solo per il rapportino
                    // (vedi ServiceReport::gestionaleValidationErrors()), ma finora
                    // si scopriva solo al momento dell'invio, a rapportino gia'
                    // compilato/firmato. Non blocca la compilazione (il tecnico deve
                    // poter comunque lavorare sul posto): segnala solo, cosi' il
                    // problema si vede subito invece che a fine giornata.
                    Forms\Components\Placeholder::make('machine_unit_eureka_warning')
                        ->label('')
                        ->columnSpanFull()
                        ->hidden(fn (Forms\Get $get) => blank($get('machine_unit_id'))
                            || self::isMachineUnitLinkedToEureka(MachineUnit::find($get('machine_unit_id'))))
                        ->content(new HtmlString(
                            '<div class="rounded-lg border border-amber-300 bg-amber-50 px-3 py-2 text-sm text-amber-800 dark:border-amber-800 dark:bg-amber-950 dark:text-amber-200">'
                                .'⚠️ Questo modello non è ancora collegato a Eureka: il rapportino non potrà essere inviato al gestionale finché il back-office non lo collega da Macchinari.'
                                .'</div>'
                        )),
                    // Il collegamento rapportino->piano di manutenzione e'
                    // inferito da customer_id+machine_unit_id (nessuna FK
                    // esplicita, vedi ServiceReport::syncMaintenanceSchedule()):
                    // scegliendo la macchina sbagliata su un cliente con piu'
                    // impianti, l'intervento riallinea in silenzio il piano
                    // sbagliato (o nessuno). Avvisa solo per manutenzione
                    // ordinaria: e' l'unico tipo che aggiorna un piano.
                    Forms\Components\Placeholder::make('machine_unit_schedule_warning')
                        ->label('')
                        ->columnSpanFull()
                        ->hidden(fn (Forms\Get $get) => blank($get('machine_unit_id'))
                            || blank($get('customer_id'))
                            || $get('intervention_type') !== ServiceReport::TYPE_MANUTENZIONE_ORDINARIA
                            || self::activeMaintenanceScheduleCount($get) === 1)
                        ->content(fn (Forms\Get $get) => new HtmlString(
                            '<div class="rounded-lg border border-amber-300 bg-amber-50 px-3 py-2 text-sm text-amber-800 dark:border-amber-800 dark:bg-amber-950 dark:text-amber-200">'
                                .(self::activeMaintenanceScheduleCount($get) === 0
                                    ? '⚠️ Nessun piano di manutenzione attivo per questa macchina: chiudendo questo rapportino nessuna scadenza verrà aggiornata automaticamente.'
                                    : '⚠️ Questa macchina ha più piani di manutenzione attivi collegati: verranno aggiornati tutti. Controlla che non sia un doppione.')
                                .'</div>'
                        )),
                    /*
                     | Chi paga QUESTO intervento.
                     |
                     | Di default e' il pagante abituale — quello della
                     | macchina, o quello del cliente — e nella stragrande
                     | maggioranza dei casi non si tocca.
                     |
                     | Ma serve poterlo cambiare sul singolo rapportino: se il
                     | guasto e' colpa del cliente, quell'intervento si fattura
                     | a lui e non al torrefattore che paga il resto. Prima
                     | questo campo era un Placeholder in sola lettura e
                     | l'unica via era cambiare il pagante del cliente, cioe'
                     | spostare anche tutto il resto.
                     |
                     | Quello che si sceglie qui resta scritto sul documento e
                     | non cambia piu' (ServiceReport::freezeInvoiceRecipient()).
                     */
                    Forms\Components\Select::make('billing_customer_id')
                        ->label('Fatturare a')
                        ->helperText(fn (Forms\Get $get) => filled($get('billing_customer_id'))
                            ? 'Scelta per questo rapportino. Svuota il campo per tornare al pagante abituale.'
                            : 'Vuoto = pagante abituale: '.(DisplayName::titleCase(self::resolvePayer($get)?->full_name) ?? '—'))
                        ->placeholder(fn (Forms\Get $get) => DisplayName::titleCase(self::resolvePayer($get)?->full_name) ?? '—')
                        ->relationship('billingCustomer', 'company_name', modifyQueryUsing: fn ($query) => $query->orderBy('company_name'))
                        ->getOptionLabelFromRecordUsing(fn ($record) => DisplayName::customerOption($record))
                        ->searchable(['company_name', 'first_name', 'last_name', 'city'])
                        ->preload()
                        ->live()
                        // Scorciatoia per il caso che ha fatto nascere il
                        // campo: il guasto e' colpa del cliente, si fattura a
                        // lui. Come hintAction accanto all'etichetta, non come
                        // icona dentro il campo: si vede, e si capisce che fa.
                        ->hintAction(
                            Forms\Components\Actions\Action::make('fattura_al_cliente')
                                ->label('Fattura al cliente')
                                ->icon('heroicon-m-user')
                                ->visible(fn (Forms\Get $get) => filled($get('customer_id'))
                                    && $get('billing_customer_id') !== $get('customer_id'))
                                ->action(fn (Forms\Set $set, Forms\Get $get) => $set('billing_customer_id', $get('customer_id')))
                        ),
                    // Non piu' una scelta manuale: il modello si ricava dalla
                    // macchina/matricola selezionata sopra (afterStateUpdated
                    // su machine_unit_id valorizza gia' l'Hidden sotto), cosi'
                    // non puo' piu' disallinearsi da quella. Resta comunque
                    // sempre visibile qui — non e' solo un dettaglio interno.
                    Forms\Components\Placeholder::make('machine_product_display')
                        ->label('Modello macchina')
                        ->content(function (Forms\Get $get) {
                            $machineUnit = $get('machine_unit_id') ? MachineUnit::find($get('machine_unit_id')) : null;

                            if ($machineUnit) {
                                return $machineUnit->display_name;
                            }

                            // Rapportino senza matricola tracciata collegata (es.
                            // dato storico pre-esistente, o importato da Eureka
                            // con il solo articolo): mostra comunque quanto gia'
                            // salvato, invece di sparire.
                            $article = $get('machine_product_id')
                                ? Product::find($get('machine_product_id'))?->name
                                : ($get('machine_material_id')
                                    ? Material::find($get('machine_material_id'))?->display_label
                                    : null);

                            return $article ?: '— (seleziona la macchina/matricola)';
                        }),
                    Forms\Components\Hidden::make('machine_product_id'),
                    Forms\Components\Hidden::make('machine_material_id'),
                    // Stesso motivo del modello sopra: la matricola si ricava
                    // dalla macchina tracciata, non si digita piu' a mano.
                    Forms\Components\Placeholder::make('machine_serial_display')
                        ->label('Matricola')
                        ->content(fn (Forms\Get $get) => $get('machine_serial_number') ?: '— (seleziona la macchina/matricola)'),
                    Forms\Components\Hidden::make('machine_serial_number'),
                ]),
            Forms\Components\Section::make('Descrizione')
                ->schema([
                    Forms\Components\Textarea::make('problem_description')->label('Problema riscontrato')->rows(2),
                    Forms\Components\Textarea::make('work_performed')->label('Lavoro svolto')->rows(3)->required()
                        ->extraAttributes(['data-tour' => 'service-reports-field-work']),
                    Forms\Components\Textarea::make('notes')->label('Note')->rows(2),
                ]),
            Forms\Components\Section::make('Ricambi/materiali utilizzati')
                ->extraAttributes(['data-tour' => 'service-reports-field-materials'])
                ->schema([
                    // Le 3 Hidden e i 2 Toggle sotto sono tutti "di comodo"
                    // (dehydrated(false), nessuna colonna reale): su un rapportino
                    // gia' salvato devono pero' rispecchiare le righe materiale
                    // gia' presenti (es. arrivando da Eureka, o da un salvataggio
                    // precedente di questi stessi widget), altrimenti riaprendo un
                    // rapportino con CHIORD/LAV2 gia' in elenco i toggle
                    // risulterebbero spenti/vuoti pur essendo la riga li' —
                    // vedi resolveLavaggioShortcutDefaults().
                    Forms\Components\Hidden::make('_chiamata_material_key')
                        ->dehydrated(false)
                        ->default(fn (?ServiceReport $record) => self::resolveLavaggioShortcutDefaults($record)['chiamata_key']),
                    Forms\Components\Hidden::make('_manodopera_material_key')
                        ->dehydrated(false)
                        ->default(fn (?ServiceReport $record) => self::resolveLavaggioShortcutDefaults($record)['manodopera_key']),
                    Forms\Components\Hidden::make('_lavaggio_base_material_key')
                        ->dehydrated(false)
                        ->default(fn (?ServiceReport $record) => self::resolveLavaggioShortcutDefaults($record)['lavaggio_base_key']),
                    Forms\Components\Hidden::make('_lavaggio_ult_material_key')
                        ->dehydrated(false)
                        ->default(fn (?ServiceReport $record) => self::resolveLavaggioShortcutDefaults($record)['lavaggio_ult_key']),
                    // Scorciatoie che aggiungono/rimuovono righe materiale da sole
                    // (stesso meccanismo per key, dehydrated(false), per entrambe):
                    // "Chiamata" per il ricambio CHIVE/CHIORD (tariffa base
                    // dell'intervento su Eureka: CHIVE per Venezia centro storico
                    // — raggiungibile solo via acqua —, CHIORD altrove; Lido e
                    // Burano hanno gia' un codice piu' specifico CHILI/CHIBU da
                    // aggiungere a mano, quindi qui il match resta volutamente
                    // stretto su "Venezia" esatto), "Lavaggio eseguito" per
                    // LAVAGGIO 2 VIE (tariffa minima agevolata, dovuta anche
                    // lavando una sola via) + ULTERIORE VIA LAVATA per le vie
                    // oltre la seconda.
                    // Quattro colonne: i quattro interruttori stanno su una riga
                    // sola, il campo "Numero vie" compare sotto solo quando serve.
                    Forms\Components\Grid::make(4)
                        ->schema([
                            // Il festivo lo dichiara chi compila: non si deduce dalla
                            // data, perche' un intervento fatto di sabato puo' essere
                            // fatturato feriale e viceversa.
                            Forms\Components\Toggle::make('_intervento_festivo')
                                ->label('Intervento festivo')
                                ->live()
                                ->dehydrated(false)
                                ->helperText('Cambia chiamata e manodopera nelle voci festive, prima di aggiungerle.'),
                            Forms\Components\Toggle::make('add_chiamata_material')
                                ->label('Chiamata')
                                ->live()
                                ->dehydrated(false)
                                ->default(fn (?ServiceReport $record) => self::resolveLavaggioShortcutDefaults($record)['chiamata_key'] !== null)
                                ->disabled(fn (Forms\Get $get) => blank($get('customer_id')))
                                ->helperText(fn (Forms\Get $get) => self::descrizioneTariffa($get, 'chiamata'))
                                ->afterStateUpdated(function (bool $state, Forms\Set $set, Forms\Get $get) {
                                    $materialsUsed = $get('materialsUsed') ?? [];
                                    $addedKey = $get('_chiamata_material_key');

                                    if (! $state) {
                                        // Rimuove solo la riga aggiunta da questo flag (per key),
                                        // non un'eventuale riga uguale inserita a mano.
                                        if ($addedKey && array_key_exists($addedKey, $materialsUsed)) {
                                            unset($materialsUsed[$addedKey]);
                                            $set('materialsUsed', $materialsUsed);
                                        }

                                        $set('_chiamata_material_key', null);

                                        return;
                                    }

                                    $customer = Customer::find($get('customer_id'));
                                    // Il codice dipende dal pagante (Martellozzo, Goppion,
                                    // Spigola… hanno il loro listino, vedi config/tariffe.php)
                                    // e solo in mancanza di listino dalla citta'.
                                    $code = TariffeIntervento::per($customer, (bool) $get('_intervento_festivo'))['chiamata'];
                                    $material = $code ? Material::where('code', $code)->first() : null;

                                    if (! $material) {
                                        return;
                                    }

                                    $alreadyAdded = collect($materialsUsed)->contains(
                                        fn (array $item) => ($item['material_id'] ?? null) === $material->id
                                    );

                                    if ($alreadyAdded) {
                                        return;
                                    }

                                    $newKey = (string) Str::uuid();
                                    $materialsUsed[$newKey] = [
                                        'material_id' => $material->id,
                                        'quantity' => 1,
                                    ];

                                    $set('materialsUsed', $materialsUsed);
                                    $set('_chiamata_material_key', $newKey);
                                }),
                            Forms\Components\Toggle::make('add_manodopera_material')
                                ->label('Manodopera')
                                ->live()
                                ->dehydrated(false)
                                ->default(fn (?ServiceReport $record) => self::resolveLavaggioShortcutDefaults($record)['manodopera_key'] !== null)
                                ->helperText(fn (Forms\Get $get) => self::descrizioneTariffa($get, 'manodopera'))
                                ->afterStateUpdated(fn (bool $state, Forms\Set $set, Forms\Get $get) => self::syncManodoperaMaterial($state, $set, $get)),
                            Forms\Components\Toggle::make('_lavaggio_vie_eseguito')
                                ->label('Lavaggio eseguito')
                                ->live()
                                ->dehydrated(false)
                                ->default(fn (?ServiceReport $record) => self::resolveLavaggioShortcutDefaults($record)['lavaggio_base_key'] !== null)
                                ->helperText('Aggiunge da sola LAVAGGIO 2 VIE (sempre) + ULTERIORE VIA LAVATA per le vie oltre la seconda.')
                                ->afterStateUpdated(fn (Forms\Set $set, Forms\Get $get) => self::syncLavaggioViaMaterials($set, $get)),
                            // Unico campo della sezione che e' una colonna
                            // vera (service_reports.lavaggio_vie_count), non
                            // di comodo come i toggle qui sopra: le righe
                            // materiali da sole non bastano a ricordarlo,
                            // perche' 1 via e 2 vie generano lo stesso identico
                            // LAV2 e la lettura all'indietro tornava sempre 2
                            // (vedi resolveLavaggioShortcutDefaults()).
                            // ->dehydratedWhenHidden() perche' quando
                            // "Lavaggio eseguito" e' spento il campo sparisce,
                            // e un campo nascosto per default non viene
                            // salvato: senza, in DB resterebbe il conteggio
                            // del lavaggio appena tolto.
                            Forms\Components\TextInput::make('lavaggio_vie_count')
                                ->label('Numero vie lavate')
                                ->numeric()
                                ->minValue(1)
                                ->live()
                                ->dehydratedWhenHidden()
                                ->default(fn (?ServiceReport $record) => self::resolveLavaggioShortcutDefaults($record)['vie_count'])
                                ->visible(fn (Forms\Get $get) => (bool) $get('_lavaggio_vie_eseguito'))
                                ->afterStateUpdated(fn (Forms\Set $set, Forms\Get $get) => self::syncLavaggioViaMaterials($set, $get)),
                        ]),
                    // Materiali (App\Models\Material), non Product: quest'ultimo e'
                    // lo stesso elenco usato per i preventivi, senza filtro —
                    // macchine/ricambi trovati su Eureka finirebbero anche li'.
                    Forms\Components\Repeater::make('materialsUsed')
                        ->relationship('materialsUsed')
                        ->label('')
                        ->columns(3)
                        ->schema([
                            Forms\Components\Select::make('material_id')
                                ->label('Materiale')
                                ->searchable()
                                ->getSearchResultsUsing(fn (string $search): array => Material::query()
                                    ->where(function (Builder $query) use ($search) {
                                        foreach (explode(' ', trim($search)) as $word) {
                                            if ($word === '') {
                                                continue;
                                            }

                                            $query->where(function (Builder $query) use ($word) {
                                                $query->where('code', 'like', "%{$word}%")
                                                    ->orWhere('type', 'like', "%{$word}%")
                                                    ->orWhere('variant', 'like', "%{$word}%")
                                                    ->orWhere('category', 'like', "%{$word}%");
                                            });
                                        }
                                    })
                                    ->limit(50)
                                    ->get()
                                    ->mapWithKeys(fn (Material $material) => [$material->id => "{$material->display_label} ({$material->code})"])
                                    ->toArray())
                                ->getOptionLabelUsing(fn ($value): ?string => Material::find($value)?->display_label)
                                ->required()
                                ->columnSpan(2),
                            Forms\Components\TextInput::make('quantity')->label('Quantità / ore')->numeric()->default(1)->required(),
                        ])
                        // La riga manodopera (materiale ORE) non e' un default
                        // statico: dipende dal tipo intervento, scelto subito
                        // sopra — vedi syncManodoperaMaterial(), agganciato
                        // all'afterStateUpdated di intervention_type. Sostituisce
                        // gli ex campi "Orario arrivo"/"Orario uscita" (rimossi
                        // da questo form): invece di orari, si segnano le ore
                        // lavorate come quantita' su quella riga.
                        ->defaultItems(0)
                        ->addActionLabel('Aggiungi ricambio')
                        ->reorderable(false),
                ]),
            Forms\Components\Section::make('Firma cliente')
                ->extraAttributes(['data-tour' => 'service-reports-field-signature'])
                ->schema([
                    Forms\Components\TextInput::make('customer_signature_name')
                        ->label('Nome e cognome (stampatello)')
                        ->maxLength(255),
                    SignaturePad::make('customer_signature_path')->label(''),
                ]),
        ]);
    }

    /**
     * Stessa logica dell'icona "Da Eureka" in MachineUnitResource::table():
     * l'invio a gestionale usa il gestionale_code del Product (vedi
     * ServiceReport::toGestionalePayload(), sl_articolo), non quello della
     * matricola — ma una matricola con source=eureka o gestionale_code
     * proprio e' comunque segno che il collegamento c'e' gia'.
     */
    private static function isMachineUnitLinkedToEureka(?MachineUnit $machineUnit): bool
    {
        if (! $machineUnit) {
            return true;
        }

        return filled($machineUnit->gestionale_code)
            || $machineUnit->source === MachineUnit::SOURCE_EUREKA
            || filled($machineUnit->product?->gestionale_code);
    }

    /**
     * Piani di manutenzione attivi per la stessa combinazione cliente+macchina
     * scelta sul form (stesso identico criterio di
     * ServiceReport::syncMaintenanceSchedule()): il caso normale e' 1. Usata
     * dal Placeholder "machine_unit_schedule_warning" sopra, prima ancora di
     * salvare, per segnalare 0 (nessuna scadenza verra' aggiornata) o piu' di
     * 1 (probabile doppione) piani.
     */
    private static function activeMaintenanceScheduleCount(Forms\Get $get): int
    {
        return MaintenanceSchedule::query()
            ->where('customer_id', $get('customer_id'))
            ->where('machine_unit_id', $get('machine_unit_id'))
            ->where('type', MaintenanceSchedule::TYPE_MANUTENZIONE)
            ->where('status', MaintenanceSchedule::STATUS_ATTIVO)
            ->count();
    }

    /**
     * Stessa risoluzione di ServiceReport::invoiceRecipient(), ma sullo stato
     * del form prima ancora di salvare (usata dal Placeholder "Fatturare a" e
     * dal bottone "Crea preventivo" in Macchina): la macchina tracciata, se
     * ha un "Fatturare a" proprio, vince sul cliente dell'intervento.
     */
    private static function resolvePayer(Forms\Get $get): ?Customer
    {
        if ($machineUnitId = $get('machine_unit_id')) {
            $billingCustomer = MachineUnit::find($machineUnitId)?->billingCustomer;

            if ($billingCustomer) {
                return $billingCustomer;
            }
        }

        if ($customerId = $get('customer_id')) {
            return Customer::find($customerId)?->invoiceRecipient();
        }

        return null;
    }

    /**
     * "0000000" (o vuoto) e' il segnaposto gia' in uso quando il tecnico non
     * conosce la matricola reale (vedi ApplyEurekaBillingDestinazione), ma
     * l'indice unico (tenant_id, serial_number) faceva fallire con un errore
     * non gestito il secondo inserimento identico per lo stesso tenant. Qui
     * lo si rende univoco da solo (0000000-2, 0000000-3, ...); una matricola
     * reale che collide invece con una gia' tracciata resta un errore
     * esplicito, perche' li' e' piu' probabile un doppione della stessa
     * macchina che una matricola davvero mancante.
     */
    private static function resolveUniqueMachineSerialNumber(?string $serialNumber): string
    {
        $trimmed = trim((string) $serialNumber);
        $tenantId = Filament::getTenant()?->id;

        if ($trimmed !== '' && $trimmed !== self::MACHINE_UNIT_NO_SERIAL_PLACEHOLDER) {
            if (MachineUnit::withTrashed()->where('tenant_id', $tenantId)->where('serial_number', $trimmed)->exists()) {
                Notification::make()
                    ->danger()
                    ->title('Matricola già tracciata')
                    ->body('Esiste già una macchina con questa matricola per questo cliente. Se è la stessa, selezionala dall\'elenco invece di crearne una nuova.')
                    ->send();

                throw new Halt;
            }

            return $trimmed;
        }

        $candidate = self::MACHINE_UNIT_NO_SERIAL_PLACEHOLDER;
        $suffix = 2;

        while (MachineUnit::withTrashed()->where('tenant_id', $tenantId)->where('serial_number', $candidate)->exists()) {
            $candidate = self::MACHINE_UNIT_NO_SERIAL_PLACEHOLDER."-{$suffix}";
            $suffix++;
        }

        return $candidate;
    }

    /**
     * Stesso meccanismo (per key, dehydrated(false)) di add_chiamata_material
     * sopra: il toggle "Manodopera" e' una scelta esplicita del tecnico, non
     * piu' agganciata al tipo intervento (in precedenza si aggiungeva da
     * sola per qualsiasi tipo diverso da manutenzione ordinaria, caricando un
     * costo senza che nessuno l'avesse chiesto).
     */
    /**
     * Testo sotto le scorciatoie: mostra il codice che verra' inserito davvero,
     * cosi' chi compila vede subito se sta applicando il listino del pagante o
     * quello standard.
     */
    /**
     * Tutti i codici che valgono come una certa voce: quello standard, la sua
     * variante festiva e le varianti dei paganti con listino proprio.
     *
     * @return array<int, string>
     */
    private static function codiciTariffa(string $voce): array
    {
        $standard = config('tariffe.standard');
        $codici = array_filter([
            $standard[$voce] ?? null,
            $standard[$voce.'_festiva'] ?? null,
            $voce === 'chiamata' ? ($standard['chiamata_venezia'] ?? null) : null,
        ]);

        foreach (config('tariffe.paganti') as $listino) {
            $codici[] = $listino[$voce] ?? null;
            $codici[] = $listino[$voce.'_festiva'] ?? null;
        }

        return array_values(array_unique(array_filter($codici)));
    }

    private static function descrizioneTariffa(Forms\Get $get, string $voce): string
    {
        if (blank($get('customer_id'))) {
            return 'Seleziona prima il cliente.';
        }

        $tariffe = TariffeIntervento::per(
            Customer::find($get('customer_id')),
            (bool) $get('_intervento_festivo')
        );

        $codice = $tariffe[$voce] ?? null;

        if (! $codice) {
            return 'Nessun codice configurato.';
        }

        return $tariffe['pagante']
            ? "Aggiunge {$codice} (listino {$tariffe['pagante']})."
            : "Aggiunge {$codice}.";
    }

    private static function syncManodoperaMaterial(bool $enabled, Forms\Set $set, Forms\Get $get): void
    {
        $materialsUsed = $get('materialsUsed') ?? [];
        $addedKey = $get('_manodopera_material_key');

        if (! $enabled) {
            if ($addedKey && array_key_exists($addedKey, $materialsUsed)) {
                unset($materialsUsed[$addedKey]);
                $set('materialsUsed', $materialsUsed);
            }

            $set('_manodopera_material_key', null);

            return;
        }

        if ($addedKey && array_key_exists($addedKey, $materialsUsed)) {
            return;
        }

        $codice = TariffeIntervento::per(
            Customer::find($get('customer_id')),
            (bool) $get('_intervento_festivo')
        )['manodopera'] ?? self::MANODOPERA_MATERIAL_CODE;

        $manodoperaId = Material::query()->where('code', $codice)->value('id');

        if (! $manodoperaId) {
            return;
        }

        $alreadyAdded = collect($materialsUsed)->contains(
            fn (array $item) => ($item['material_id'] ?? null) === $manodoperaId
        );

        if ($alreadyAdded) {
            return;
        }

        $newKey = (string) Str::uuid();
        $materialsUsed[$newKey] = ['material_id' => $manodoperaId, 'quantity' => null];

        $set('materialsUsed', $materialsUsed);
        $set('_manodopera_material_key', $newKey);
    }

    /**
     * Stato "reale" (da DB) del campo "Impianti e vie lavate" per un
     * rapportino esistente: niente pivot dedicata, le vie lavate si leggono
     * direttamente dalle righe Lavaggio gia' generate per questo rapportino
     * (stesso criterio univoco service_report_id+maintenance_schedule_id di
     * Lavaggio::firstOrNew() dentro ServiceReport::syncGeneratedLavaggi()).
     * Iniettato da EditServiceReport::mutateFormDataBeforeFill(), stesso
     * motivo di resolveLavaggioShortcutDefaults() qui sotto.
     */
    public static function resolveLavaggioImpiantiDefaults(?ServiceReport $record): array
    {
        if (! $record) {
            return [];
        }

        return $record->maintenanceSchedules()->get()
            ->map(fn (MaintenanceSchedule $schedule) => [
                'maintenance_schedule_id' => $schedule->id,
                'lines_washed' => Lavaggio::where('service_report_id', $record->id)
                    ->where('maintenance_schedule_id', $schedule->id)
                    ->value('lines_washed'),
            ])
            ->all();
    }

    /**
     * Applica le righe del Repeater "Impianti e vie lavate": prima la
     * selezione esplicita dei piani coinvolti (attach nudo, senza dati extra
     * sulla pivot — vince sulla regola implicita di
     * ServiceReport::syncMaintenanceSchedule(), vedi il commento sul campo
     * nel form), poi la generazione/aggiornamento delle righe Lavaggio, poi
     * le vie lavate scritte direttamente su quelle righe (niente colonna
     * pivot dedicata: piu' semplice riscrivere lines_washed a colpo sicuro
     * sulla riga Lavaggio che il sync ha appena creato/toccato). Chiamata da
     * CreateServiceReport::afterCreate() ed EditServiceReport::afterSave().
     */
    public static function syncLavaggioImpianti(ServiceReport $record, array $rows): void
    {
        $scheduleIds = collect($rows)
            ->pluck('maintenance_schedule_id')
            ->filter()
            ->values();

        $record->maintenanceSchedules()->sync($scheduleIds);
        $record->syncMaintenanceSchedule();

        foreach ($rows as $row) {
            if (blank($row['maintenance_schedule_id'] ?? null) || blank($row['lines_washed'] ?? null)) {
                continue;
            }

            Lavaggio::where('service_report_id', $record->id)
                ->where('maintenance_schedule_id', $row['maintenance_schedule_id'])
                ->update(['lines_washed' => $row['lines_washed']]);
        }
    }

    /**
     * Stato "reale" (da DB, non dal form) delle righe CHIVE/CHIORD e
     * LAV2/ULTVIA gia' presenti su un rapportino esistente: serve a far
     * partire i toggle/hidden di cui sopra gia' allineati a quello che c'e'
     * davvero in "Ricambi/materiali utilizzati" (es. un rapportino importato
     * da Eureka con CHIORD+LAV2 gia' in elenco), invece che sempre spenti.
     * Le key restituite sono gli id delle righe ServiceReportMaterial con
     * prefisso "record-", cosi' da poter essere riusate cosi' come sono come
     * chiavi del repeater ->relationship() (che su un edit e' keyed per
     * "record-{id}", non per id nudo ne' per uuid generato al volo).
     *
     * Pubblico perche' su EditServiceReport i ->default() qui sotto NON
     * bastano: Filament valuta getDefaultState() solo quando fill() e'
     * chiamato senza dati (create), non quando gli si passa l'array del
     * record da modificare (edit) — in quel caso i campi senza chiave in
     * quell'array vengono azzerati da fillStateWithNull(), ->default()
     * incluso. Serve quindi iniettare questi valori PRIMA, in
     * EditServiceReport::mutateFormDataBeforeFill().
     */
    public static function resolveLavaggioShortcutDefaults(?ServiceReport $record): array
    {
        $empty = [
            'chiamata_key' => null,
            'manodopera_key' => null,
            'lavaggio_base_key' => null,
            'lavaggio_ult_key' => null,
            'vie_count' => null,
        ];

        if (! $record) {
            return $empty;
        }

        $rows = $record->materialsUsed()->with('material')->get();

        // Riconosce sia i codici standard sia quelli dei paganti con listino
        // proprio: un rapportino con CHIMART deve accendere il toggle
        // "Chiamata" come uno con CHIORD. Solo lettura: le righe gia' salvate
        // non vengono mai riscritte.
        $chiamataRow = $rows->first(fn ($row) => in_array($row->material?->code, self::codiciTariffa('chiamata'), true));
        $manodoperaRow = $rows->first(fn ($row) => in_array($row->material?->code, self::codiciTariffa('manodopera'), true));
        $baseRow = $rows->first(fn ($row) => in_array($row->material?->code, self::codiciTariffa('lavaggio'), true));
        $ultRow = $rows->first(fn ($row) => in_array($row->material?->code, self::codiciTariffa('lavaggio_ulteriore_via'), true));

        return [
            // Prefisso "record-": il repeater ->relationship() tiene le righe
            // gia' persistite nello stato con chiave "record-{id}", non
            // l'id nudo (vedi Repeater::getCachedExistingRecords()) — senza
            // prefisso i toggle "spengono" solo in apparenza, l'unset() sulla
            // key sbagliata non trova mai la riga e questa resta in elenco.
            'chiamata_key' => $chiamataRow ? "record-{$chiamataRow->id}" : null,
            'manodopera_key' => $manodoperaRow ? "record-{$manodoperaRow->id}" : null,
            'lavaggio_base_key' => $baseRow ? "record-{$baseRow->id}" : null,
            'lavaggio_ult_key' => $ultRow ? "record-{$ultRow->id}" : null,
            // Vince sempre il numero digitato dal tecnico, ora che c'e' una
            // colonna che lo conserva: le righe materiali non bastano a
            // ricostruirlo, perche' 1 via e 2 vie generano entrambe il solo
            // LAV2 e il calcolo qui sotto rileggerebbe 2 anche dopo aver
            // scritto 1. Il calcolo resta come ripiego per i rapportini in
            // cui la colonna e' vuota (quelli salvati prima di questa
            // colonna e i 200+ importati da Eureka): li' l'unico dato
            // disponibile sono le righe, ULTVIA qty = vie - 2 al contrario,
            // e 2 e' quello che il codice materiale dichiara letteralmente.
            'vie_count' => $record->lavaggio_vie_count
                ?? ($baseRow ? 2 + (int) ($ultRow?->quantity ?? 0) : null),
        ];
    }

    /**
     * Stesso meccanismo per key di add_chiamata_material/syncManodoperaMaterial
     * sopra: ricalcola da zero le righe generate da questo widget a ogni
     * cambio di toggle/numero vie, senza toccare righe uguali aggiunte a
     * mano (dedupe via $alreadyAdded, come altrove in questo file).
     */
    private static function syncLavaggioViaMaterials(Forms\Set $set, Forms\Get $get): void
    {
        $materialsUsed = $get('materialsUsed') ?? [];

        foreach (['_lavaggio_base_material_key', '_lavaggio_ult_material_key'] as $keyField) {
            $key = $get($keyField);

            if ($key && array_key_exists($key, $materialsUsed)) {
                unset($materialsUsed[$key]);
            }
        }

        $eseguito = (bool) $get('_lavaggio_vie_eseguito');
        $vieCount = (int) $get('lavaggio_vie_count');

        if (! $eseguito || $vieCount < 1) {
            $set('materialsUsed', $materialsUsed);
            $set('_lavaggio_base_material_key', null);
            $set('_lavaggio_ult_material_key', null);
            // Spegnere il toggle azzera anche il conteggio: il campo e' una
            // colonna vera, senza questo resterebbe in DB il numero di vie
            // del lavaggio appena tolto dal rapportino.
            $set('lavaggio_vie_count', null);

            return;
        }

        $tariffe = TariffeIntervento::per(Customer::find($get('customer_id')));
        $baseMaterial = Material::where('code', $tariffe['lavaggio'] ?? self::LAVAGGIO_VIE_BASE_MATERIAL_CODE)->first();
        $ultMaterial = Material::where('code', $tariffe['lavaggio_ulteriore_via'] ?? self::LAVAGGIO_VIE_ULTERIORE_MATERIAL_CODE)->first();

        $newBaseKey = null;
        $newUltKey = null;

        if ($baseMaterial) {
            $baseAlreadyPresent = collect($materialsUsed)->contains(
                fn (array $item) => ($item['material_id'] ?? null) === $baseMaterial->id
            );

            if (! $baseAlreadyPresent) {
                $newBaseKey = (string) Str::uuid();
                $materialsUsed[$newBaseKey] = ['material_id' => $baseMaterial->id, 'quantity' => 1];
            }
        }

        if ($ultMaterial && $vieCount > 2) {
            $ultAlreadyPresent = collect($materialsUsed)->contains(
                fn (array $item) => ($item['material_id'] ?? null) === $ultMaterial->id
            );

            if (! $ultAlreadyPresent) {
                $newUltKey = (string) Str::uuid();
                $materialsUsed[$newUltKey] = ['material_id' => $ultMaterial->id, 'quantity' => $vieCount - 2];
            }
        }

        $set('materialsUsed', $materialsUsed);
        $set('_lavaggio_base_material_key', $newBaseKey);
        $set('_lavaggio_ult_material_key', $newUltKey);
    }

    public static function table(Table $table): Table
    {
        return $table
            // Tiebreak su created_at: senza, righe con la stessa
            // intervention_date (frequente, piu' rapportini nello stesso
            // giorno) non hanno un ordine stabile tra un caricamento e
            // l'altro della pagina.
            ->defaultSort(fn ($query) => $query->orderByDesc('intervention_date')->orderByDesc('created_at'))
            ->columns([
                Tables\Columns\TextColumn::make('number')->label('Numero')->searchable(),
                Tables\Columns\TextColumn::make('gestionale_number')
                    ->label('Numero gestionale')
                    ->placeholder('—')
                    ->searchable()
                    // Colonna separata (non in coda al numero CRM: erano
                    // "tutto misto" nella stessa cella) e nascosta di
                    // default per non riportare via lo spazio recuperato
                    // sulla colonna Eureka — visibile via toggle colonne.
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('customer.company_name')->label('Cliente')->searchable()
                    ->formatStateUsing(fn (?string $state) => DisplayName::titleCase($state)),
                Tables\Columns\TextColumn::make('technician.name')->label('Tecnico'),
                Tables\Columns\TextColumn::make('intervention_type')
                    ->label('Tipo')
                    ->badge()
                    ->formatStateUsing(fn (string $state) => match ($state) {
                        ServiceReport::TYPE_INSTALLAZIONE => 'Installazione',
                        ServiceReport::TYPE_MANUTENZIONE_ORDINARIA => 'Manutenzione ord.',
                        ServiceReport::TYPE_MANUTENZIONE_STRAORDINARIA => 'Manutenzione straord.',
                        ServiceReport::TYPE_RIPARAZIONE => 'Riparazione',
                        ServiceReport::TYPE_GARANZIA => 'Garanzia',
                        default => $state,
                    }),
                Tables\Columns\TextColumn::make('intervention_date')->label('Data')->date()->sortable(),
                Tables\Columns\TextColumn::make('status')
                    ->label('Stato')
                    ->badge()
                    ->formatStateUsing(fn (string $state) => self::statusLabels()[$state] ?? ucfirst($state))
                    ->color(fn (string $state) => self::statusColors()[$state] ?? 'gray'),
                Tables\Columns\IconColumn::make('gestionale_sync_status')
                    ->label('Eureka')
                    // Icona invece del badge testuale per risparmiare
                    // spazio in tabella (stesso pattern di
                    // MachineUnitResource::table() colonna "Da Eureka").
                    // "Non inviato" era fuorviante per uno storico ripescato
                    // da un import (eureka_service_report_id valorizzato,
                    // gestionale_sync_status pero' mai toccato perche' quel
                    // campo segue solo gli invii CRM->Eureka): sembrava un
                    // rapportino da inviare quando in realta' e' gia' su
                    // Eureka, solo arrivato nel verso opposto.
                    ->state(fn (ServiceReport $record) => self::gestionaleDisplayState($record))
                    ->icon(fn (?string $state) => match ($state) {
                        'sent', 'imported' => 'heroicon-o-check-circle',
                        'queued' => 'heroicon-o-clock',
                        'failed' => 'heroicon-o-x-circle',
                        default => 'heroicon-o-minus-circle',
                    })
                    ->color(fn (?string $state) => self::gestionaleSyncStatusColors()[$state] ?? self::gestionaleSyncStatusColors()['none'])
                    ->tooltip(fn (ServiceReport $record) => self::gestionaleSyncStatusLabels()[self::gestionaleDisplayState($record)] ?? self::gestionaleSyncStatusLabels()['none']),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('intervention_type')
                    ->label('Tipo')
                    ->options([
                        ServiceReport::TYPE_INSTALLAZIONE => 'Installazione',
                        ServiceReport::TYPE_MANUTENZIONE_ORDINARIA => 'Manutenzione ordinaria',
                        ServiceReport::TYPE_MANUTENZIONE_STRAORDINARIA => 'Manutenzione straordinaria',
                        ServiceReport::TYPE_RIPARAZIONE => 'Riparazione',
                        ServiceReport::TYPE_GARANZIA => 'Garanzia',
                        ServiceReport::TYPE_SANIFICAZIONE => 'Sanificazione',
                    ]),
                Tables\Filters\SelectFilter::make('status')
                    ->label('Stato')
                    ->options(fn () => self::statusLabels()),
                Tables\Filters\SelectFilter::make('gestionale_sync_status')
                    ->label('Eureka')
                    ->options(fn () => self::gestionaleSyncStatusLabels())
                    // 'none'/'imported' non sono valori reali in colonna —
                    // sono lo stato derivato calcolato da
                    // gestionaleDisplayState() a partire da
                    // gestionale_sync_status + eureka_service_report_id,
                    // vedi la stessa distinzione li'.
                    ->query(function ($query, array $data) {
                        return match ($data['value'] ?? null) {
                            null, '' => $query,
                            'none' => $query->whereNull('gestionale_sync_status')->whereNull('eureka_service_report_id'),
                            'imported' => $query->whereNull('gestionale_sync_status')->whereNotNull('eureka_service_report_id'),
                            default => $query->where('gestionale_sync_status', $data['value']),
                        };
                    }),
                Tables\Filters\SelectFilter::make('customer_id')
                    ->label('Cliente')
                    ->relationship('customer', 'company_name', modifyQueryUsing: fn ($query) => $query->orderBy('company_name'))
                    ->getOptionLabelFromRecordUsing(fn ($record) => DisplayName::titleCase($record->full_name))
                    ->searchable()
                    ->preload(),
                Tables\Filters\SelectFilter::make('technician_id')
                    ->label('Tecnico')
                    ->relationship('technician', 'name'),
                Tables\Filters\TrashedFilter::make(),
            ])
            ->actions([
                Tables\Actions\ViewAction::make(),
                Tables\Actions\ActionGroup::make([
                    Tables\Actions\Action::make('pdf')
                        ->label('PDF')
                        ->icon('heroicon-o-document-arrow-down')
                        ->visible(fn (ServiceReport $record): bool => auth()->user()?->can('view', $record) ?? false)
                        ->url(fn (ServiceReport $record) => route('service-reports.pdf', $record))
                        ->openUrlInNewTab(),
                    Tables\Actions\Action::make('send')
                        ->label('Invia')
                        ->icon('heroicon-o-paper-airplane')
                        ->form(fn (ServiceReport $record) => static::sendEmailFormSchema($record))
                        ->action(function (array $data, ServiceReport $record) {
                            $record->load(['customer', 'technician', 'machineProduct', 'machineMaterial', 'machineUnit.product', 'machineUnit.billingCustomer', 'partsUsed.product', 'materialsUsed.material', 'tenant']);

                            // Vedi App\Support\OutsideLivewireRender: senza,
                            // il PDF e la parte testo dell'email mostrano
                            // letteralmente i commenti di tracciamento
                            // <!--[if BLOCK]><![endif]--> che Livewire
                            // inietta attorno a ogni @if quando il rendering
                            // parte da dentro un'azione Livewire come questa.
                            $pdf = OutsideLivewireRender::run(fn () => Pdf::loadView('pdf.service-report', ['report' => $record, 'showPrices' => false]));

                            $recipientEmails = array_values(array_filter($data['recipient_emails'] ?? []));
                            $ccEmails = array_values(array_filter($data['cc_emails'] ?? []));

                            $email = $record->emails()->create([
                                'user_id' => auth()->id(),
                                'recipient_email' => implode(', ', $recipientEmails),
                                'cc_email' => $ccEmails ? implode(', ', $ccEmails) : null,
                                'subject' => "Rapportino di intervento {$record->number}",
                                'status' => 'sent',
                            ]);

                            $ccRecipients = array_values(array_unique(array_filter(array_merge(
                                $ccEmails,
                                $record->tenant?->notificationRecipients('service_report') ?? [],
                            ))));

                            try {
                                OutsideLivewireRender::run(fn () => Mail::to($recipientEmails)
                                    ->cc($ccRecipients)
                                    ->send(new ServiceReportMail($record, $pdf->output(), $data['custom_message'] ?? null)));

                                $record->update(['status' => 'inviato']);
                                Notification::make()->title('Rapportino inviato')->success()->send();
                            } catch (\Throwable $e) {
                                $email->update(['status' => 'failed', 'error_message' => $e->getMessage()]);
                                Notification::make()->title('Invio fallito')->body($e->getMessage())->danger()->send();
                            }
                        }),
                    Tables\Actions\Action::make('invia_gestionale')
                        ->label(fn (ServiceReport $record) => match ($record->gestionale_sync_status) {
                            'sent' => 'Aggiorna su gestionale',
                            'queued' => 'In coda…',
                            default => 'Invia a gestionale',
                        })
                        ->icon('heroicon-o-arrow-up-tray')
                        ->disabled(fn (ServiceReport $record): bool => $record->gestionale_sync_status === 'queued')
                        ->visible(fn (ServiceReport $record): bool => in_array($record->status, ServiceReport::CLOSED_STATUSES, true) && ($record->tenant?->hasGestionaleEurekaCredentials() ?? false))
                        ->requiresConfirmation()
                        ->modalDescription('Invia questo rapportino a Eureka come scheda lavoro. Non è possibile cancellare un documento una volta creato, nemmeno in ambiente di test.')
                        ->action(function (ServiceReport $record) {
                            $record->load(['customer.billingCustomer', 'machineProduct', 'machineMaterial', 'machineUnit.product', 'machineUnit.billingCustomer', 'materialsUsed.material', 'tenant']);

                            // Validato subito per un errore istantaneo (dati
                            // mancanti non richiedono di aspettare la coda);
                            // il job lo ri-valida comunque prima di inviare,
                            // nel caso qualcosa cambi nel frattempo.
                            $errors = $record->gestionaleValidationErrors();

                            if ($errors !== []) {
                                Notification::make()
                                    ->title('Impossibile inviare a gestionale')
                                    ->body(implode("\n", $errors))
                                    ->danger()
                                    ->send();

                                return;
                            }

                            // L'invio vero e proprio (chiamata HTTP a Eureka,
                            // fino a 15s contro un'API vecchia senza ambiente
                            // di test) va in coda invece di bloccare questa
                            // richiesta: l'esito arriva come notifica appena
                            // il worker l'ha processato.
                            $record->update(['gestionale_sync_status' => 'queued']);
                            SendServiceReportToGestionaleJob::dispatch($record, auth()->user());

                            Notification::make()
                                ->title('Invio a gestionale in coda')
                                ->body('Ti avviseremo qui non appena Eureka avra\' risposto.')
                                ->success()
                                ->send();
                        }),
                    // ->visible() esplicito, indipendente dal Gate: vedi lo
                    // stesso commento su ViewServiceReport::getHeaderActions().
                    Tables\Actions\EditAction::make()
                        ->visible(fn (ServiceReport $record) => ! $record->isLocked()),
                    Tables\Actions\DeleteAction::make(),
                    Tables\Actions\RestoreAction::make(),
                    Tables\Actions\ForceDeleteAction::make(),
                ]),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                    Tables\Actions\RestoreBulkAction::make(),
                    Tables\Actions\ForceDeleteBulkAction::make(),
                ]),
            ])
            ->emptyStateHeading('Nessun rapportino ancora')
            ->emptyStateDescription('Crea il primo rapportino con "Nuovo".')
            ->emptyStateIcon('heroicon-o-clipboard-document-check');
    }

    /**
     * Form dell'azione "Invia": stesso principio di
     * QuoteResource::sendEmailFormSchema() (testo modificabile con
     * anteprima), con in piu' un placeholder reattivo che mostra il
     * rendering reale della mail (stesso iframe gia' usato in "Storico
     * invii email") mentre si scrive, invece di scoprirlo solo dopo
     * l'invio. Il PDF allegato non cambia (resta sempre showPrices=false),
     * qui si modifica solo il testo del corpo email.
     *
     * @return array<Forms\Components\Component>
     */
    protected static function sendEmailFormSchema(ServiceReport $record): array
    {
        return [
            Forms\Components\TagsInput::make('recipient_emails')
                ->label('Email destinatario')
                ->helperText('Il cliente ha piu\' indirizzi salvati: scegli tra i suggerimenti o digitane uno nuovo. Puoi selezionarne piu\' di uno.')
                ->suggestions(fn () => $record->customer?->emails ?? [])
                ->splitKeys([',', ' ', 'Tab', 'Enter'])
                ->required()
                ->rules([fn () => static::emailListValidationRule()])
                ->default(fn () => array_filter([$record->customer?->primaryEmail()])),
            Forms\Components\TagsInput::make('cc_emails')
                ->label('CC (opzionale)')
                ->helperText('Anche qui puoi aggiungere piu\' indirizzi, ad es. le altre email del cliente.')
                ->suggestions(fn () => $record->customer?->emails ?? [])
                ->splitKeys([',', ' ', 'Tab', 'Enter'])
                ->rules([fn () => static::emailListValidationRule()]),
            Forms\Components\RichEditor::make('custom_message')
                ->label('Testo email (modificabile)')
                ->toolbarButtons(['bold', 'italic', 'bulletList', 'orderedList', 'link', 'undo', 'redo'])
                ->helperText('Questo testo viene inviato realmente nella mail. Il rapportino allegato non mostra mai i prezzi.')
                ->live(debounce: 500)
                ->default(fn () => static::defaultServiceReportEmailBody($record)),
            Forms\Components\Placeholder::make('email_preview')
                ->label('Anteprima email')
                ->content(fn (Get $get): HtmlString => new HtmlString(
                    // Vedi App\Support\OutsideLivewireRender: questo
                    // ->content() e' un Placeholder reattivo (->live() su
                    // custom_message sopra), quindi il ->render() qui sotto
                    // parte SEMPRE mentre Livewire sta ridisegnando il
                    // pannello — senza il fix, i commenti di tracciamento
                    // <!--[if BLOCK]><![endif]--> di Livewire finiscono
                    // ogni volta nell'anteprima (bug segnalato 2026-08-20).
                    '<iframe srcdoc="'.e(OutsideLivewireRender::run(fn () => (new ServiceReportMail($record, '', is_string($get('custom_message')) ? $get('custom_message') : null))->render())).'" style="width:100%;height:60vh;border:0;border-radius:0.5rem;background:#fff;"></iframe>'
                )),
        ];
    }

    protected static function emailListValidationRule(): \Closure
    {
        return function (string $attribute, $value, \Closure $fail) {
            foreach ((array) $value as $item) {
                if (! filter_var($item, FILTER_VALIDATE_EMAIL)) {
                    $fail("\"{$item}\" non e' un indirizzo email valido.");
                }
            }
        };
    }

    protected static function defaultServiceReportEmailBody(ServiceReport $record): string
    {
        $customerName = DisplayName::titleCase($record->customer?->company_name) ?: (DisplayName::titleCase($record->customer?->full_name) ?? 'Cliente');
        $interventionDate = $record->intervention_date?->format('d/m/Y');

        return implode('', [
            '<p>Gentile '.e($customerName).',</p>',
            '<p>in allegato il rapportino relativo all\'intervento del '.e($interventionDate).'.</p>',
            '<p><strong>Lavoro svolto:</strong> '.e($record->work_performed).'</p>',
        ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListServiceReports::route('/'),
            'create' => Pages\CreateServiceReport::route('/create'),
            'view' => Pages\ViewServiceReport::route('/{record}'),
            'edit' => Pages\EditServiceReport::route('/{record}/edit'),
        ];
    }

    /**
     * Il modello non ha costanti per lo stato (campo stringa libero storico):
     * le etichette/colori restano centralizzati qui per non duplicarli tra
     * tabella, infolist e form. "firmato" resta rimosso: nessun flusso lo
     * assegna (la firma cliente in "Firma cliente" e' un concetto
     * indipendente dallo stato, catturata anche su rapportini gia' "Inviato"
     * — vedi il campo customer_signature_path).
     *
     * "completato" era stato tolto perche' l'unico rapportino che lo aveva
     * era un'anomalia (corretta a mano in "inviato", nessun flusso lo
     * assegnava). Reintrodotto il 2026-08-17 su richiesta esplicita, stavolta
     * come stato valido a tutti gli effetti — vedi ServiceReport::CLOSED_STATUSES
     * (conta come "chiuso" esattamente come "inviato") — per marcare in blocco
     * lo storico gia' passato in amministrazione.
     */
    public static function statusLabels(): array
    {
        return [
            'bozza' => 'Bozza',
            'inviato' => 'Inviato',
            'completato' => 'Completato',
            // Impostato dal job di invio, non a mano: segna che il documento
            // e' passato in Eureka ed e' li' che va corretto d'ora in poi.
            'in_gestionale' => 'In gestionale',
            'rifiutato' => 'Rifiutato',
        ];
    }

    public static function statusColors(): array
    {
        return [
            'bozza' => 'gray',
            'inviato' => 'success',
            'completato' => 'info',
            'in_gestionale' => 'warning',
            'rifiutato' => 'danger',
        ];
    }

    public static function gestionaleSyncStatusLabels(): array
    {
        return [
            'none' => 'Non inviato',
            'queued' => 'In coda',
            'sent' => 'Inviato',
            'failed' => 'Fallito',
            'imported' => 'Importato da Eureka',
        ];
    }

    public static function gestionaleSyncStatusColors(): array
    {
        return [
            'none' => 'gray',
            'queued' => 'warning',
            'sent' => 'success',
            'failed' => 'danger',
            'imported' => 'info',
        ];
    }

    /**
     * gestionale_sync_status segue solo gli invii CRM->Eureka: un
     * rapportino ripescato da eureka:import-service-reports ha
     * eureka_service_report_id valorizzato ma quel campo a NULL, e senza
     * questa distinzione sembrava "da inviare" pur essendo gia' su Eureka
     * (arrivato nel verso opposto).
     */
    private static function gestionaleDisplayState(ServiceReport $record): string
    {
        if ($record->gestionale_sync_status) {
            return $record->gestionale_sync_status;
        }

        return $record->eureka_service_report_id ? 'imported' : 'none';
    }
}
