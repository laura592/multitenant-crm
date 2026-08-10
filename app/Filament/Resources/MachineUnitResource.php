<?php

namespace App\Filament\Resources;

use App\Filament\Resources\MachineUnitResource\Pages;
use App\Filament\Resources\MachineUnitResource\RelationManagers\PlacementsRelationManager;
use App\Models\Customer;
use App\Models\MachineUnit;
use App\Models\Product;
use App\Support\Gestionale\EurekaClient;
use Filament\Actions\MountableAction;
use Filament\Facades\Filament;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

/**
 * Registro dei macchinari fisici: matricola, proprieta' (puo' non coincidere
 * col tenant, es. una macchina di proprieta' "Dersut" installata presso un
 * cliente Alex) e ubicazione attuale. Lo storico degli spostamenti si vede
 * nella relation manager "Storico posizionamenti"; lo spostamento vero e
 * proprio si fa con l'azione "Sposta" (non modificando a mano il cliente
 * attuale, per non perdere lo storico).
 */
class MachineUnitResource extends Resource
{
    protected static ?string $model = MachineUnit::class;

    protected static ?string $navigationIcon = 'heroicon-o-cpu-chip';

    protected static ?string $navigationGroup = 'Interventi tecnici';

    protected static ?string $navigationLabel = 'Macchinari';

    protected static ?string $modelLabel = 'Macchinario';

    protected static ?string $pluralModelLabel = 'Macchinari';

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make('Identificazione')
                ->columns(2)
                ->schema([
                    Forms\Components\TextInput::make('serial_number')->label('Matricola')->required()->maxLength(255),
                    Forms\Components\Select::make('product_id')
                        ->label('Modello (da catalogo)')
                        ->relationship('product', 'name', modifyQueryUsing: fn ($query) => $query->where('type', Product::TYPE_MACHINE))
                        ->searchable()
                        ->preload(),
                    Forms\Components\TextInput::make('model_name')
                        ->label('Modello (testo libero)')
                        ->helperText('Solo se non e\' a catalogo (es. macchina non a listino Alex).')
                        ->maxLength(255),
                    Forms\Components\Select::make('billing_customer_id')
                        ->label('Fatturare a')
                        ->relationship('billingCustomer', 'company_name')
                        ->getOptionLabelFromRecordUsing(fn (Customer $record) => $record->full_name)
                        ->searchable(['company_name', 'first_name', 'last_name'])
                        ->preload()
                        ->helperText('Lascia vuoto se paga il cliente presso cui è installata questa macchina.'),
                    Forms\Components\Select::make('status')
                        ->label('Stato')
                        ->options([
                            MachineUnit::STATUS_IN_MAGAZZINO => 'In magazzino',
                            MachineUnit::STATUS_INSTALLATA => 'Installata',
                            MachineUnit::STATUS_RIMOSSA => 'Rimossa',
                        ])
                        ->disabled()
                        ->dehydrated(false)
                        ->helperText('Cambia automaticamente con l\'azione "Sposta".'),
                    Forms\Components\Textarea::make('notes')->label('Note')->columnSpanFull(),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('serial_number')->label('Matricola')->searchable(),
                Tables\Columns\TextColumn::make('display_name')->label('Modello'),
                Tables\Columns\TextColumn::make('currentCustomer.company_name')->label('Presso')->placeholder('In magazzino'),
                Tables\Columns\IconColumn::make('gestionale_code')
                    ->label('Da Eureka')
                    ->boolean()
                    ->trueIcon('heroicon-o-check-circle')
                    ->falseIcon('heroicon-o-x-circle')
                    ->tooltip('Se la macchina (matricola) o il suo modello sono collegati a Eureka. Indipendente dal collegamento del modello: una macchina puo essere importata da Eureka anche se il suo modello non e ancora agganciato a un articolo.')
                    ->getStateUsing(fn (MachineUnit $record) => filled($record->gestionale_code) || $record->source === MachineUnit::SOURCE_EUREKA || filled($record->product?->gestionale_code)),
                Tables\Columns\TextColumn::make('status')
                    ->label('Stato')
                    ->badge()
                    ->formatStateUsing(fn (string $state) => match ($state) {
                        MachineUnit::STATUS_INSTALLATA => 'Installata',
                        MachineUnit::STATUS_RIMOSSA => 'Rimossa',
                        default => 'In magazzino',
                    })
                    ->color(fn (string $state) => match ($state) {
                        MachineUnit::STATUS_INSTALLATA => 'success',
                        MachineUnit::STATUS_RIMOSSA => 'danger',
                        default => 'gray',
                    }),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->label('Stato')
                    ->options([
                        MachineUnit::STATUS_IN_MAGAZZINO => 'In magazzino',
                        MachineUnit::STATUS_INSTALLATA => 'Installata',
                        MachineUnit::STATUS_RIMOSSA => 'Rimossa',
                    ]),
                Tables\Filters\Filter::make('gestionale_suggested_code')
                    ->label('Collegamento proposto')
                    ->query(fn ($query) => $query->whereNotNull('gestionale_suggested_code')),
                Tables\Filters\TrashedFilter::make(),
            ])
            ->actions([
                Tables\Actions\ViewAction::make()
                    ->color('gray'),
                Tables\Actions\ActionGroup::make([
                    static::confermaCollegamentoGestionaleAction(Tables\Actions\Action::make('conferma_collegamento_gestionale')),
                    static::scartaCollegamentoGestionaleAction(Tables\Actions\Action::make('scarta_collegamento_gestionale')),
                    static::cercaEurekaAction(Tables\Actions\Action::make('cerca_eureka')),
                    static::createServiceReportAction(Tables\Actions\Action::make('create_service_report')),
                    static::spostaAction(Tables\Actions\Action::make('sposta')),
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
            ]);
    }

    /**
     * Azioni condivise fra il menu di riga della tabella e gli header di
     * ViewMachineUnit/EditMachineUnit: da quando il click riga apre la view
     * invece dell'edit, chi e' gia' dentro il record singolo non passa piu'
     * dalla tabella per usarle. Tables\Actions\Action e Filament\Actions\Action
     * estendono entrambe MountableAction, quindi la stessa configurazione
     * vale in entrambi i contesti (le pagine record legano $record
     * automaticamente, vedi InteractsWithRecord::configureAction()).
     */
    public static function confermaCollegamentoGestionaleAction(MountableAction $action): MountableAction
    {
        return $action
            ->label(fn (MachineUnit $record) => 'Conferma matricola Eureka: '.($record->gestionale_suggested_label ?? "#{$record->gestionale_suggested_code}"))
            ->icon('heroicon-o-link')
            ->color('warning')
            ->visible(fn (MachineUnit $record): bool => $record->gestionale_suggested_code !== null)
            ->requiresConfirmation()
            ->modalDescription('Il sync automatico ha trovato questa matricola su Eureka. Confermi?')
            ->action(function (MachineUnit $record) {
                $record->update([
                    'gestionale_code' => $record->gestionale_suggested_code,
                    'gestionale_suggested_code' => null,
                    'gestionale_suggested_label' => null,
                ]);
                Notification::make()->title('Collegamento confermato')->success()->send();
            });
    }

    public static function scartaCollegamentoGestionaleAction(MountableAction $action): MountableAction
    {
        return $action
            ->label('Scarta proposta')
            ->icon('heroicon-o-x-mark')
            ->visible(fn (MachineUnit $record): bool => $record->gestionale_suggested_code !== null)
            ->requiresConfirmation()
            ->action(fn (MachineUnit $record) => $record->update([
                'gestionale_suggested_code' => null,
                'gestionale_suggested_label' => null,
            ]));
    }

    public static function cercaEurekaAction(MountableAction $action): MountableAction
    {
        return $action
            ->label('Cerca su Eureka')
            ->icon('heroicon-o-magnifying-glass')
            ->visible(fn (MachineUnit $record): bool => $record->product !== null && (Filament::getTenant()?->hasGestionaleEurekaCredentials() ?? false))
            ->fillForm(fn (MachineUnit $record): array => ['gestionale_code' => $record->product?->gestionale_code])
            ->form([
                Forms\Components\Select::make('gestionale_code')
                    ->label('Articolo Eureka')
                    ->searchable()
                    ->getSearchResultsUsing(function (string $search): array {
                        $client = new EurekaClient(Filament::getTenant());

                        return collect($client->cercaArticoli($search))
                            ->mapWithKeys(fn (array $item) => [$item['id_eureka'] => "{$item['codice']} — {$item['descr1']}"])
                            ->all();
                    })
                    ->getOptionLabelUsing(fn ($value) => "Codice Eureka: {$value}")
                    ->required()
                    ->helperText(fn (MachineUnit $record) => 'Digita il nome del modello (es. "ICON", "XT") per cercare nel catalogo Eureka. Il codice viene salvato sul prodotto collegato ("'.($record->product?->name ?? 'modello').'"), quindi vale per tutte le macchine di questo stesso modello, non solo per questa matricola.'),
            ])
            ->action(function (array $data, MachineUnit $record) {
                $record->product?->update(['gestionale_code' => $data['gestionale_code']]);
                Notification::make()->title('Codice Eureka salvato sul modello')->success()->send();
            });
    }

    public static function createServiceReportAction(MountableAction $action): MountableAction
    {
        return $action
            ->label('Crea rapportino')
            ->icon('heroicon-o-document-plus')
            ->color('success')
            ->visible(fn (MachineUnit $record): bool => $record->current_customer_id !== null)
            ->url(fn (MachineUnit $record) => ServiceReportResource::getUrl('create', ['machine_unit_id' => $record->id, 'customer_id' => $record->current_customer_id]));
    }

    public static function spostaAction(MountableAction $action): MountableAction
    {
        return $action
            ->label('Sposta')
            ->icon('heroicon-o-arrow-right-circle')
            ->form([
                Forms\Components\Select::make('customer_id')
                    ->label('Nuovo cliente')
                    ->helperText('Lascia vuoto per riportare la macchina in magazzino/rimuoverla.')
                    ->options(fn () => Customer::query()->orderBy('company_name')->get()->mapWithKeys(
                        fn (Customer $customer) => [$customer->id => $customer->full_name ?: 'Cliente senza nome']
                    ))
                    ->searchable(),
                Forms\Components\Textarea::make('notes')->label('Note sullo spostamento'),
            ])
            ->action(function (MachineUnit $record, array $data) {
                $customer = $data['customer_id'] ? Customer::find($data['customer_id']) : null;
                $record->moveTo($customer, $data['notes'] ?? null);

                Notification::make()
                    ->title($customer ? "Macchina spostata presso {$customer->company_name}" : 'Macchina rientrata in magazzino')
                    ->success()
                    ->send();
            });
    }

    public static function getRelations(): array
    {
        return [
            PlacementsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListMachineUnits::route('/'),
            'create' => Pages\CreateMachineUnit::route('/create'),
            'view' => Pages\ViewMachineUnit::route('/{record}'),
            'edit' => Pages\EditMachineUnit::route('/{record}/edit'),
        ];
    }
}
