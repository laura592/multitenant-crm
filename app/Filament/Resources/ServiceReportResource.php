<?php

namespace App\Filament\Resources;

use App\Filament\Forms\Components\SignaturePad;
use App\Filament\Resources\ServiceReportResource\Pages;
use App\Mail\ServiceReportMail;
use App\Models\Product;
use App\Models\ServiceReport;
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
            InfolistSection::make('Intervento')
                ->columns(3)
                ->schema([
                    TextEntry::make('number')->label('Numero'),
                    TextEntry::make('customer.full_name')->label('Cliente'),
                    TextEntry::make('technician.name')->label('Tecnico'),
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
                        }),
                    TextEntry::make('intervention_date')->label('Data intervento')->date(),
                    TextEntry::make('status')->label('Stato')->badge(),
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
                    ImageEntry::make('customer_signature_path')
                        ->label('')
                        ->disk('public')
                        ->placeholder('Non ancora firmato'),
                ]),
        ]);
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
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
                            Forms\Components\TextInput::make('mobile')->label('Cellulare'),
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
                        ->options([
                            'bozza' => 'Bozza',
                            'completato' => 'Completato',
                            'firmato' => 'Firmato',
                            'inviato' => 'Inviato',
                        ])
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
                        ->options(fn () => Product::query()->where('type', Product::TYPE_MACHINE)->pluck('name', 'id'))
                        ->searchable(),
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
                Tables\Columns\TextColumn::make('status')->label('Stato')->badge(),
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
            ])
            ->actions([
                Tables\Actions\ViewAction::make(),
                Tables\Actions\ActionGroup::make([
                    Tables\Actions\Action::make('pdf')
                        ->label('PDF')
                        ->icon('heroicon-o-document-arrow-down')
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
                                ->default(fn (ServiceReport $record) => $record->customer->email),
                            Forms\Components\TextInput::make('cc_email')->label('CC (opzionale)')->email(),
                        ])
                        ->action(function (array $data, ServiceReport $record) {
                            $record->load(['customer', 'technician', 'machineProduct', 'partsUsed.product', 'tenant']);
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
                    Tables\Actions\EditAction::make(),
                    Tables\Actions\DeleteAction::make(),
                ]),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
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
}
