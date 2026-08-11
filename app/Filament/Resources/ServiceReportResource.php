<?php

namespace App\Filament\Resources;

use App\Filament\Forms\Components\SignaturePad;
use App\Filament\Forms\CustomerContactFields;
use App\Filament\Resources\ServiceReportResource\Pages;
use App\Jobs\SendServiceReportToGestionaleJob;
use App\Mail\ServiceReportMail;
use App\Models\Customer;
use App\Models\MachineUnit;
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
     * Materiale usato come riga "manodopera" aggiunta da sola sul form
     * quando il tipo intervento non e' manutenzione ordinaria (vedi
     * syncManodoperaMaterial()), al posto dei vecchi campi orario
     * arrivo/uscita.
     */
    private const MANODOPERA_MATERIAL_CODE = 'ORE';

    public static function infolist(Infolist $infolist): Infolist
    {
        return $infolist->schema([
            // Stesso pattern di QuoteResource: riepilogo a colpo d'occhio in
            // alto, i dettagli (incl. orari) restano nelle sezioni sotto
            // senza ripetere qui i campi gia' mostrati nell'hero.
            InfolistSection::make('Panoramica rapida')
                ->columns(12)
                ->columnSpanFull()
                ->extraAttributes([
                    'class' => 'rounded-2xl border border-slate-200 bg-gradient-to-br from-slate-50 via-white to-sky-50 shadow-sm dark:border-slate-800 dark:from-slate-900 dark:via-slate-950 dark:to-slate-900',
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
                    TextEntry::make('quote.number')->label('Preventivo collegato')->placeholder('—'),
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
                        ->columns(2)
                        ->schema([
                            TextEntry::make('material.display_label')->label('Materiale'),
                            TextEntry::make('quantity')->label('Quantità'),
                        ]),
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
                ->columns(3)
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
                    TextEntry::make('gestionale_synced_at')->label('Ultimo invio riuscito')->dateTime('d/m/Y H:i')->placeholder('—'),
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
                    'class' => 'rounded-2xl border border-slate-200 bg-gradient-to-br from-slate-50 via-white to-sky-50 shadow-sm dark:border-slate-800 dark:from-slate-900 dark:via-slate-950 dark:to-slate-900',
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
                        ->getOptionLabelFromRecordUsing(fn ($record) => $record->full_name)
                        ->searchable(['company_name', 'first_name', 'last_name'])
                        ->preload()
                        ->required()
                        ->live()
                        // Prefill arrivando dall'azione "Crea rapportino" su un
                        // macchinario (MachineUnitResource): vedi anche
                        // machine_unit_id sotto, stesso query param.
                        ->default(fn () => request()->query('customer_id'))
                        // Preventivo e macchina tracciata sono entrambi filtrati per
                        // cliente (vedi sotto): cambiando cliente le selezioni fatte
                        // in precedenza non hanno piu' senso.
                        ->afterStateUpdated(function (Forms\Set $set) {
                            $set('quote_id', null);
                            $set('machine_unit_id', null);
                        })
                        ->createOptionForm([
                            Forms\Components\TextInput::make('company_name')->label('Ragione sociale'),
                            Forms\Components\TextInput::make('first_name')->label('Nome'),
                            Forms\Components\TextInput::make('last_name')->label('Cognome'),
                            ...CustomerContactFields::schema(),
                        ])
                        ->editOptionForm([
                            Forms\Components\TextInput::make('company_name')->label('Ragione sociale'),
                            Forms\Components\TextInput::make('first_name')->label('Nome'),
                            Forms\Components\TextInput::make('last_name')->label('Cognome'),
                            ...CustomerContactFields::schema(),
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
                        ])
                        ->required()
                        ->live()
                        // Manutenzione ordinaria si addebita col codice specifico
                        // della macchina (es. F2, DC3, C2 — un ricambio Material
                        // diverso per ogni modello), da scegliere a mano qui sotto
                        // in "Ricambi/materiali utilizzati": niente da precompilare.
                        // Su tutti gli altri tipi (chiamata/riparazione/
                        // installazione/garanzia) si addebitano invece le ore di
                        // manodopera, oltre all'eventuale "Chiamata" (vedi
                        // add_chiamata_material sotto) — qui la riga si aggiunge
                        // da sola.
                        ->afterStateUpdated(fn (?string $state, Forms\Set $set, Forms\Get $get) => self::syncManodoperaMaterial($state, $set, $get)),
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
                    Forms\Components\Select::make('quote_id')
                        ->label('Preventivo collegato')
                        ->relationship(
                            'quote',
                            'number',
                            modifyQueryUsing: fn (Builder $query, Forms\Get $get) => $query
                                ->where('customer_id', $get('customer_id'))
                                ->where('status', 'accettato'),
                        )
                        ->searchable()
                        ->preload()
                        ->disabled(fn (Forms\Get $get) => blank($get('customer_id')))
                        ->createOptionAction(null)
                        ->helperText('Seleziona prima il cliente: qui compaiono solo i suoi preventivi accettati.'),
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
                        ->helperText('Scegliendo la matricola si compilano da soli cliente, modello e matricola qui sotto.'),
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
                    Forms\Components\Placeholder::make('fatturare_a')
                        ->label('Fatturare a')
                        ->content(fn (Forms\Get $get) => self::resolvePayer($get)?->full_name ?? '—'),
                    Forms\Components\Select::make('machine_product_id')
                        ->label('Modello macchina')
                        ->options(fn () => Product::query()
                            ->where('type', Product::TYPE_MACHINE)
                            ->orderByRaw('gestionale_code IS NULL') // collegati a Eureka prima
                            ->orderBy('name')
                            ->get()
                            ->mapWithKeys(fn (Product $product) => [
                                $product->id => $product->name.($product->gestionale_code ? ' — ✓ Eureka' : ''),
                            ]))
                        // Su rapportini vecchi il valore salvato puo' puntare a un
                        // Product che non e' (piu') type=machine (dato legacy):
                        // resta fuori dalla lista options() sopra, e senza questo
                        // Filament non trova un'etichetta e mostra l'uuid grezzo.
                        ->getOptionLabelUsing(fn ($value) => Product::find($value)?->name)
                        ->searchable()
                        ->helperText('I modelli con "✓ Eureka" sono gia\' collegati al gestionale: usarli garantisce che il rapportino sia sempre inviabile.'),
                    Forms\Components\TextInput::make('machine_serial_number')
                        ->label('Matricola')
                        ->maxLength(255),
                ]),
            Forms\Components\Section::make('Descrizione')
                ->schema([
                    Forms\Components\Textarea::make('problem_description')->label('Problema riscontrato')->rows(2),
                    Forms\Components\Textarea::make('work_performed')->label('Lavoro svolto')->rows(3)->required(),
                    Forms\Components\Textarea::make('notes')->label('Note')->rows(2),
                ]),
            Forms\Components\Section::make('Ricambi/materiali utilizzati')
                ->schema([
                    // Scorciatoia per il ricambio "chiamata" (tariffa base
                    // dell'intervento su Eureka): CHIVE per Venezia centro
                    // storico (raggiungibile solo via acqua), CHIORD altrove.
                    // Lido e Burano hanno gia' un codice piu' specifico
                    // (CHILI/CHIBU) da aggiungere a mano, quindi qui il match
                    // sulla citta' resta volutamente stretto ("Venezia" esatto).
                    Forms\Components\Checkbox::make('add_chiamata_material')
                        ->label('Chiamata')
                        ->live()
                        ->dehydrated(false)
                        ->disabled(fn (Forms\Get $get) => blank($get('customer_id')))
                        ->helperText('Aggiunge da sola il ricambio "CHIVE" (Venezia) o "CHIORD" (altrove) in base alla città del cliente. Togliendo la spunta si rimuove la riga aggiunta. Seleziona prima il cliente.')
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
                    Forms\Components\Hidden::make('_chiamata_material_key')->dehydrated(false),
                    Forms\Components\Hidden::make('_manodopera_material_key')->dehydrated(false),
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
                                ->options(fn () => Material::query()->get()->mapWithKeys(
                                    fn (Material $material) => [$material->id => $material->display_label],
                                ))
                                ->searchable()
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
     * sopra, ma agganciato al tipo intervento invece che a una checkbox
     * manuale: la manutenzione ordinaria si addebita col codice specifico
     * della macchina (F2/DC3/C2/...), da scegliere a mano — nessuna riga
     * automatica. Tutti gli altri tipi sono di fatto una "chiamata": la
     * manodopera (materiale ORE) si aggiunge da sola, il tecnico segna solo
     * le ore lavorate in quantita'.
     */
    private static function syncManodoperaMaterial(?string $interventionType, Forms\Set $set, Forms\Get $get): void
    {
        $materialsUsed = $get('materialsUsed') ?? [];
        $addedKey = $get('_manodopera_material_key');

        if ($interventionType === null || $interventionType === ServiceReport::TYPE_MANUTENZIONE_ORDINARIA) {
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
                            $record->load(['customer', 'technician', 'machineProduct', 'machineUnit.billingCustomer', 'partsUsed.product', 'materialsUsed.material', 'tenant']);
                            $pdf = Pdf::loadView('pdf.service-report', ['report' => $record]);

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
                        ->visible(fn (ServiceReport $record): bool => in_array($record->status, ['firmato', 'inviato'], true) && ($record->tenant?->hasGestionaleEurekaCredentials() ?? false))
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
                    Tables\Actions\EditAction::make(),
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
     * tabella, infolist e form.
     */
    public static function statusLabels(): array
    {
        return [
            'bozza' => 'Bozza',
            'completato' => 'Completato',
            'firmato' => 'Firmato',
            'inviato' => 'Inviato',
        ];
    }

    public static function statusColors(): array
    {
        return [
            'bozza' => 'gray',
            'completato' => 'info',
            'firmato' => 'warning',
            'inviato' => 'success',
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
