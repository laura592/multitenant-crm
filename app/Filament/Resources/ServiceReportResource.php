<?php

namespace App\Filament\Resources;

use App\Filament\Forms\Components\SignaturePad;
use App\Filament\Forms\CustomerContactFields;
use App\Filament\Forms\CustomerFiscalFields;
use App\Filament\Resources\CustomerResource;
use App\Filament\Resources\ServiceReportResource\Pages;
use App\Jobs\SendServiceReportToGestionaleJob;
use App\Mail\ServiceReportMail;
use App\Models\Customer;
use App\Models\MachineUnit;
use App\Models\MaintenanceSchedule;
use App\Models\Material;
use App\Models\Product;
use App\Models\ServiceReport;
use Barryvdh\DomPDF\Facade\Pdf;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Infolists\Components\Actions\Action as InfolistAction;
use Filament\Infolists\Components\Actions as InfolistActions;
use Filament\Infolists\Components\ImageEntry;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\Section as InfolistSection;
use Filament\Infolists\Components\TextEntry;
use Filament\Infolists\Infolist;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
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

    public static function infolist(Infolist $infolist): Infolist
    {
        return $infolist->schema([
            // Il pulsante "Modifica" scompare da solo quando isLockedFromGestionale()
            // e' vero (ServiceReportPolicy::update()) — questo banner spiega perche',
            // altrimenti sembra un bottone mancante per errore.
            TextEntry::make('_gestionale_lock_notice')
                ->hiddenLabel()
                ->columnSpanFull()
                ->visible(fn (ServiceReport $record) => $record->isLockedFromGestionale())
                ->state(fn (ServiceReport $record) => $record->source === ServiceReport::SOURCE_EUREKA
                    ? 'Rapportino arrivato da Eureka: non è modificabile da qui.'
                    : 'Rapportino già inviato a Eureka: non è più modificabile.')
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
                    TextEntry::make('customer.full_name')->label('Cliente')->columnSpan(3),
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
                    TextEntry::make('machineProduct.name')->label('Modello macchina')->placeholder('—'),
                    TextEntry::make('machine_serial_number')->label('Matricola')->placeholder('—'),
                    TextEntry::make('machine_unit_display_name')->label('Macchina (matricola tracciata)')->placeholder('—'),
                    TextEntry::make('machineUnit.billingCustomer.full_name')->label('Fatturare a')->placeholder('—'),
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
                        ->content(fn (?ServiceReport $record) => $record?->customer?->full_name ?? '—'),
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
                        ->relationship('customer', 'company_name', modifyQueryUsing: fn ($query) => $query->orderBy('company_name'))
                        ->getOptionLabelFromRecordUsing(fn ($record) => $record->city
                            ? "{$record->full_name} ({$record->city})"
                            : $record->full_name)
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
                        ->options(fn () => self::statusLabels())
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
                                ->required()
                                ->maxLength(255),
                            Forms\Components\Select::make('product_id')
                                ->label('Modello (da catalogo)')
                                ->relationship('product', 'name', modifyQueryUsing: fn ($query) => $query->where('type', Product::TYPE_MACHINE))
                                ->searchable()
                                ->preload(),
                            Forms\Components\TextInput::make('model_name')
                                ->label('Modello (testo libero)')
                                ->helperText('Solo se non e\' a catalogo (es. macchina non a listino Alex).')
                                ->maxLength(255),
                        ])
                        ->createOptionUsing(function (array $data, Forms\Get $get) {
                            $machineUnit = MachineUnit::create([
                                'source' => MachineUnit::SOURCE_MANUALE,
                                'serial_number' => $data['serial_number'],
                                'product_id' => $data['product_id'] ?? null,
                                'model_name' => $data['model_name'] ?? null,
                            ]);

                            $machineUnit->moveTo($get('customer_id') ? Customer::find($get('customer_id')) : null);

                            return $machineUnit->id;
                        }),
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
                    Forms\Components\Placeholder::make('fatturare_a')
                        ->label('Fatturare a')
                        ->content(fn (Forms\Get $get) => self::resolvePayer($get)?->full_name ?? '—'),
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
                            // dato storico pre-esistente): mostra comunque quanto
                            // gia' salvato, invece di sparire.
                            return $get('machine_product_id')
                                ? (Product::find($get('machine_product_id'))?->name ?? '—')
                                : '— (seleziona la macchina/matricola)';
                        }),
                    Forms\Components\Hidden::make('machine_product_id'),
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
                    Forms\Components\Textarea::make('work_performed')->label('Lavoro svolto')->rows(3)->required(),
                    Forms\Components\Textarea::make('notes')->label('Note')->rows(2),
                ]),
            Forms\Components\Section::make('Ricambi/materiali utilizzati')
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
                    Forms\Components\Grid::make(3)
                        ->schema([
                            Forms\Components\Toggle::make('add_chiamata_material')
                                ->label('Chiamata')
                                ->live()
                                ->dehydrated(false)
                                ->default(fn (?ServiceReport $record) => self::resolveLavaggioShortcutDefaults($record)['chiamata_key'] !== null)
                                ->disabled(fn (Forms\Get $get) => blank($get('customer_id')))
                                ->helperText('Aggiunge da sola CHIVE (Venezia) o CHIORD (altrove). Seleziona prima il cliente.')
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
                                    $code = $customer?->city === 'Venezia' ? 'CHIVE' : 'CHIORD';
                                    $material = Material::where('code', $code)->first();

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
                                ->helperText('Aggiunge da sola la riga ore lavorate (ORE): segna poi la quantita\' come ore.')
                                ->afterStateUpdated(fn (bool $state, Forms\Set $set, Forms\Get $get) => self::syncManodoperaMaterial($state, $set, $get)),
                            Forms\Components\Toggle::make('_lavaggio_vie_eseguito')
                                ->label('Lavaggio eseguito')
                                ->live()
                                ->dehydrated(false)
                                ->default(fn (?ServiceReport $record) => self::resolveLavaggioShortcutDefaults($record)['lavaggio_base_key'] !== null)
                                ->helperText('Aggiunge da sola LAVAGGIO 2 VIE (sempre) + ULTERIORE VIA LAVATA per le vie oltre la seconda.')
                                ->afterStateUpdated(fn (Forms\Set $set, Forms\Get $get) => self::syncLavaggioViaMaterials($set, $get)),
                            Forms\Components\TextInput::make('_lavaggio_vie_count')
                                ->label('Numero vie lavate')
                                ->numeric()
                                ->minValue(1)
                                ->live()
                                ->dehydrated(false)
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
     * Stesso meccanismo (per key, dehydrated(false)) di add_chiamata_material
     * sopra: il toggle "Manodopera" e' una scelta esplicita del tecnico, non
     * piu' agganciata al tipo intervento (in precedenza si aggiungeva da
     * sola per qualsiasi tipo diverso da manutenzione ordinaria, caricando un
     * costo senza che nessuno l'avesse chiesto).
     */
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

        $manodoperaId = Material::query()->where('code', self::MANODOPERA_MATERIAL_CODE)->value('id');

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
     * Stato "reale" (da DB, non dal form) delle righe CHIVE/CHIORD e
     * LAV2/ULTVIA gia' presenti su un rapportino esistente: serve a far
     * partire i toggle/hidden di cui sopra gia' allineati a quello che c'e'
     * davvero in "Ricambi/materiali utilizzati" (es. un rapportino importato
     * da Eureka con CHIORD+LAV2 gia' in elenco), invece che sempre spenti.
     * Le key restituite sono gli id delle righe ServiceReportMaterial, cosi'
     * da poter essere riusate cosi' come sono come chiavi del repeater
     * ->relationship() (che su un edit e' keyed per id di riga, non per uuid
     * generato al volo).
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

        $chiamataRow = $rows->first(fn ($row) => in_array($row->material?->code, ['CHIVE', 'CHIORD'], true));
        $manodoperaRow = $rows->first(fn ($row) => $row->material?->code === self::MANODOPERA_MATERIAL_CODE);
        $baseRow = $rows->first(fn ($row) => $row->material?->code === self::LAVAGGIO_VIE_BASE_MATERIAL_CODE);
        $ultRow = $rows->first(fn ($row) => $row->material?->code === self::LAVAGGIO_VIE_ULTERIORE_MATERIAL_CODE);

        return [
            'chiamata_key' => $chiamataRow?->id,
            'manodopera_key' => $manodoperaRow?->id,
            'lavaggio_base_key' => $baseRow?->id,
            'lavaggio_ult_key' => $ultRow?->id,
            // Inverso esatto di syncLavaggioViaMaterials() (ULTVIA qty =
            // vieCount - 2): LAV2 da solo, senza ULTVIA, non permette di
            // distinguere "1 via lavata con la tariffa minima agevolata" da
            // "2 vie lavate" (stessa riga in entrambi i casi) — si assume 2,
            // il valore nominale della tariffa stessa, non 1.
            'vie_count' => $baseRow ? 2 + (int) ($ultRow?->quantity ?? 0) : null,
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
        $vieCount = (int) $get('_lavaggio_vie_count');

        if (! $eseguito || $vieCount < 1) {
            $set('materialsUsed', $materialsUsed);
            $set('_lavaggio_base_material_key', null);
            $set('_lavaggio_ult_material_key', null);

            return;
        }

        $baseMaterial = Material::where('code', self::LAVAGGIO_VIE_BASE_MATERIAL_CODE)->first();
        $ultMaterial = Material::where('code', self::LAVAGGIO_VIE_ULTERIORE_MATERIAL_CODE)->first();

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
                Tables\Columns\TextColumn::make('customer.company_name')->label('Cliente')->searchable(),
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
                    ->getOptionLabelFromRecordUsing(fn ($record) => $record->full_name)
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
                        ->form([
                            Forms\Components\TextInput::make('recipient_email')
                                ->label('Email destinatario')
                                ->email()
                                ->required()
                                ->default(fn (ServiceReport $record) => $record->customer?->primaryEmail()),
                            Forms\Components\TextInput::make('cc_email')->label('CC (opzionale)')->email(),
                        ])
                        ->action(function (array $data, ServiceReport $record) {
                            $record->load(['customer', 'technician', 'machineProduct', 'machineUnit.product', 'machineUnit.billingCustomer', 'partsUsed.product', 'materialsUsed.material', 'tenant']);
                            $pdf = Pdf::loadView('pdf.service-report', ['report' => $record, 'showPrices' => false]);

                            $email = $record->emails()->create([
                                'user_id' => auth()->id(),
                                'recipient_email' => $data['recipient_email'],
                                'cc_email' => $data['cc_email'] ?? null,
                                'subject' => "Rapportino di intervento {$record->number}",
                                'status' => 'sent',
                            ]);

                            $ccRecipients = array_values(array_unique(array_filter(array_merge(
                                $data['cc_email'] ? [$data['cc_email']] : [],
                                $record->tenant?->notificationRecipients('service_report') ?? [],
                            ))));

                            try {
                                Mail::to($data['recipient_email'])
                                    ->cc($ccRecipients)
                                    ->send(new ServiceReportMail($record, $pdf->output()));

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
                            $record->load(['customer.billingCustomer', 'machineProduct', 'machineUnit.product', 'machineUnit.billingCustomer', 'materialsUsed.material', 'tenant']);

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
                        ->visible(fn (ServiceReport $record) => ! $record->isLockedFromGestionale()),
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
            'rifiutato' => 'Rifiutato',
        ];
    }

    public static function statusColors(): array
    {
        return [
            'bozza' => 'gray',
            'inviato' => 'success',
            'completato' => 'info',
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
