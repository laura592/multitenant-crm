<?php

namespace App\Filament\Resources;

use App\Filament\Forms\Components\SignaturePad;
use App\Filament\Forms\CustomerContactFields;
use App\Filament\Resources\ServiceReportResource\Pages;
use App\Mail\ServiceReportMail;
use App\Models\Product;
use App\Models\ServiceReport;
use App\Support\Gestionale\EurekaClient;
use Barryvdh\DomPDF\Facade\Pdf;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Infolists\Components\ImageEntry;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\Section as InfolistSection;
use Filament\Infolists\Components\TextEntry;
use Filament\Infolists\Infolist;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Mail;

class ServiceReportResource extends Resource
{
    protected static ?string $model = ServiceReport::class;

    protected static ?string $navigationIcon = 'heroicon-o-clipboard-document-check';

    protected static ?string $navigationGroup = 'Interventi tecnici';

    protected static ?string $navigationLabel = 'Rapportini tecnici';

    protected static ?string $modelLabel = 'Rapportino';

    protected static ?string $pluralModelLabel = 'Rapportini tecnici';

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
            InfolistSection::make('Orari')
                ->columns(2)
                ->schema([
                    TextEntry::make('arrival_at')->label('Orario arrivo')->dateTime('d/m/Y H:i')->placeholder('—'),
                    TextEntry::make('departure_at')->label('Orario uscita')->dateTime('d/m/Y H:i')->placeholder('—'),
                ]),
            InfolistSection::make('Macchina')
                ->columns(3)
                ->schema([
                    TextEntry::make('quote.number')->label('Preventivo collegato')->placeholder('—'),
                    TextEntry::make('comodatoMacchina.nome_macchina')->label('Comodato collegato')->placeholder('—'),
                    TextEntry::make('machineProduct.name')->label('Modello macchina')->placeholder('—'),
                    TextEntry::make('machine_serial_number')->label('Matricola')->placeholder('—'),
                    TextEntry::make('machineUnit.serial_number')->label('Macchina (matricola tracciata)')->placeholder('—'),
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
                        ->placeholder('Non ancora firmato'),
                ]),
            InfolistSection::make('Gestionale')
                ->columns(3)
                ->schema([
                    TextEntry::make('gestionale_sync_status')
                        ->label('Stato invio Eureka')
                        ->badge()
                        ->formatStateUsing(fn (?string $state) => match ($state) {
                            'sent' => 'Inviato',
                            'failed' => 'Fallito',
                            default => 'Non inviato',
                        })
                        ->color(fn (?string $state) => match ($state) {
                            'sent' => 'success',
                            'failed' => 'danger',
                            default => 'gray',
                        }),
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
                            ? \Illuminate\Support\Carbon::parse($record->getAttribute('intervention_date'))->format('d/m/Y')
                            : '—'),
                    Forms\Components\Placeholder::make('summary_status')
                        ->label('Stato')
                        ->content(fn (?ServiceReport $record) => new \Illuminate\Support\HtmlString(
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
                        ]),
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
                        ->required(),
                    Forms\Components\DatePicker::make('intervention_date')
                        ->label('Data intervento')
                        ->default(now())
                        ->required(),
                    Forms\Components\Select::make('status')
                        ->label('Stato')
                        ->options(fn () => self::statusLabels())
                        ->default('bozza')
                        ->required(),
                    Forms\Components\DateTimePicker::make('arrival_at')->label('Orario arrivo'),
                    Forms\Components\DateTimePicker::make('departure_at')->label('Orario uscita'),
                ]),
            Forms\Components\Section::make('Macchina')
                ->columns(3)
                ->schema([
                    Forms\Components\Select::make('quote_id')
                        ->label('Preventivo collegato')
                        ->relationship('quote', 'number')
                        ->searchable()
                        ->preload(),
                    Forms\Components\Select::make('comodato_macchina_id')
                        ->label('Comodato collegato')
                        ->relationship('comodatoMacchina', 'nome_macchina')
                        ->searchable()
                        ->preload(),
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
                        ->searchable()
                        ->helperText('I modelli con "✓ Eureka" sono gia\' collegati al gestionale: usarli garantisce che il rapportino sia sempre inviabile.'),
                    Forms\Components\TextInput::make('machine_serial_number')
                        ->label('Matricola')
                        ->maxLength(255),
                    Forms\Components\Select::make('machine_unit_id')
                        ->label('Macchina (matricola tracciata)')
                        ->relationship('machineUnit', 'serial_number')
                        ->getOptionLabelFromRecordUsing(fn ($record) => $record->display_name.' — '.$record->serial_number)
                        ->searchable()
                        ->preload()
                        ->helperText('Se indicata e ha un "Fatturare a" proprio, guida la fatturazione di questo intervento al posto del cliente.'),
                ]),
            Forms\Components\Section::make('Descrizione')
                ->schema([
                    Forms\Components\Textarea::make('problem_description')->label('Problema riscontrato')->rows(2),
                    Forms\Components\Textarea::make('work_performed')->label('Lavoro svolto')->rows(3)->required(),
                    Forms\Components\Textarea::make('notes')->label('Note')->rows(2),
                ]),
            Forms\Components\Section::make('Ricambi/materiali utilizzati')
                ->schema([
                    Forms\Components\Repeater::make('partsUsed')
                        ->relationship('partsUsed')
                        ->label('')
                        ->columns(3)
                        ->schema([
                            Forms\Components\Select::make('product_id')
                                ->label('Prodotto')
                                ->options(fn () => Product::query()->pluck('name', 'id'))
                                ->searchable()
                                ->required()
                                ->columnSpan(2),
                            Forms\Components\TextInput::make('quantity')->label('Quantità')->numeric()->default(1)->required(),
                        ])
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

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('intervention_date', 'desc')
            ->columns([
                Tables\Columns\TextColumn::make('number')->label('Numero')->searchable(),
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
                Tables\Columns\TextColumn::make('intervention_date')->label('Data')->date(),
                Tables\Columns\TextColumn::make('status')
                    ->label('Stato')
                    ->badge()
                    ->formatStateUsing(fn (string $state) => self::statusLabels()[$state] ?? ucfirst($state))
                    ->color(fn (string $state) => self::statusColors()[$state] ?? 'gray'),
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
                Tables\Filters\SelectFilter::make('customer_id')
                    ->label('Cliente')
                    ->relationship('customer', 'company_name', modifyQueryUsing: fn ($query) => $query->orderBy('company_name'))
                    ->getOptionLabelFromRecordUsing(fn ($record) => $record->full_name)
                    ->searchable()
                    ->preload(),
                Tables\Filters\SelectFilter::make('technician_id')
                    ->label('Tecnico')
                    ->relationship('technician', 'name'),
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
                                ->default(fn (ServiceReport $record) => $record->invoiceRecipient()->primaryEmail()),
                            Forms\Components\TextInput::make('cc_email')->label('CC (opzionale)')->email(),
                        ])
                        ->action(function (array $data, ServiceReport $record) {
                            $record->load(['customer', 'technician', 'machineProduct', 'machineUnit.billingCustomer', 'partsUsed.product', 'tenant']);
                            $pdf = Pdf::loadView('pdf.service-report', ['report' => $record]);

                            $email = $record->emails()->create([
                                'user_id' => auth()->id(),
                                'recipient_email' => $data['recipient_email'],
                                'cc_email' => $data['cc_email'] ?? null,
                                'subject' => "Rapportino di intervento {$record->number}",
                                'status' => 'sent',
                            ]);

                            try {
                                Mail::to($data['recipient_email'])
                                    ->cc($data['cc_email'] ?? [])
                                    ->send(new ServiceReportMail($record, $pdf->output()));

                                $record->update(['status' => 'inviato']);
                                Notification::make()->title('Rapportino inviato')->success()->send();
                            } catch (\Throwable $e) {
                                $email->update(['status' => 'failed', 'error_message' => $e->getMessage()]);
                                Notification::make()->title('Invio fallito')->body($e->getMessage())->danger()->send();
                            }
                        }),
                    Tables\Actions\Action::make('invia_gestionale')
                        ->label(fn (ServiceReport $record) => $record->gestionale_sync_status === 'sent' ? 'Aggiorna su gestionale' : 'Invia a gestionale')
                        ->icon('heroicon-o-arrow-up-tray')
                        ->visible(fn (ServiceReport $record): bool => in_array($record->status, ['firmato', 'inviato'], true) && $record->tenant->hasGestionaleEurekaCredentials())
                        ->requiresConfirmation()
                        ->modalDescription('Invia questo rapportino a Eureka come scheda lavoro. Sono dati di produzione: non esiste un ambiente di test.')
                        ->action(function (ServiceReport $record) {
                            $record->load(['customer.billingCustomer', 'machineProduct', 'machineUnit.product', 'machineUnit.billingCustomer', 'partsUsed.product', 'tenant']);

                            $errors = $record->gestionaleValidationErrors();

                            if ($errors !== []) {
                                Notification::make()
                                    ->title('Impossibile inviare a gestionale')
                                    ->body(implode("\n", $errors))
                                    ->danger()
                                    ->send();

                                return;
                            }

                            try {
                                $client = new EurekaClient($record->tenant);
                                $result = $client->inviaSchedaLavoro($record->toGestionalePayload(), "CRM-{$record->id}");

                                $record->update([
                                    'gestionale_scheda_lavoro_id' => $result['id'] ?? null,
                                    'gestionale_sync_status' => 'sent',
                                    'gestionale_sync_error' => null,
                                    'gestionale_synced_at' => now(),
                                ]);

                                Notification::make()->title('Inviato a gestionale')->success()->send();
                            } catch (\Throwable $e) {
                                $record->update([
                                    'gestionale_sync_status' => 'failed',
                                    'gestionale_sync_error' => $e->getMessage(),
                                ]);

                                Notification::make()->title('Invio a gestionale fallito')->body($e->getMessage())->danger()->send();
                            }
                        }),
                    Tables\Actions\EditAction::make(),
                    Tables\Actions\DeleteAction::make(),
                ]),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
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
}
