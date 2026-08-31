<?php

namespace App\Filament\Resources;

use Illuminate\Database\Eloquent\Builder;
use App\Filament\Resources\MachineUnitResource\Pages;
use App\Filament\Resources\MachineUnitResource\RelationManagers\PlacementsRelationManager;
use App\Models\Customer;
use App\Models\MachineUnit;
use App\Models\Material;
use App\Models\Product;
use App\Support\DisplayName;
use App\Support\Gestionale\EurekaClient;
use Filament\Actions\MountableAction;
use Filament\Facades\Filament;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Infolists\Components\Section as InfolistSection;
use Filament\Infolists\Components\TextEntry;
use Filament\Infolists\Infolist;
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


    /**
     * Precarica le relazioni che l'elenco legge per ogni riga.
     *
     * Senza, Filament fa una query per riga per ciascuna relazione: sui
     * rapportini erano 56 query per 25 righe invece di 8, e il conto cresce
     * con la paginazione.
     *
     * currentCustomer e' una colonna dell'elenco; billingCustomer e product
     * vengono letti dalle azioni e dalla scheda.
     */
    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with(['currentCustomer', 'billingCustomer', 'product']);
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make('Identificazione')
                ->columns(2)
                ->schema([
                    Forms\Components\TextInput::make('serial_number')
                        ->label('Matricola')
                        ->required()
                        ->maxLength(255)
                        // table: MachineUnit::class (non la stringa tabella) applica
                        // lo scope tenant del modello: senza, il controllo unicita'
                        // ignorerebbe machine_units_tenant_id_serial_number_unique
                        // e l'errore arriverebbe solo come 500 dal vincolo DB.
                        ->unique(
                            table: MachineUnit::class,
                            ignorable: fn (?MachineUnit $record) => $record,
                        )
                        ->extraAttributes(['data-tour' => 'machine-units-field-serial']),
                    Forms\Components\Select::make('product_id')
                        ->label('Modello (da catalogo)')
                        ->relationship('product', 'name', modifyQueryUsing: fn ($query) => $query->where('type', Product::TYPE_MACHINE))
                        // Il modifyQueryUsing sopra limita giustamente la RICERCA ai
                        // prodotti tipo "machine" (non ha senso agganciare un
                        // macchinario a un servizio quando se ne sceglie uno nuovo),
                        // ma Filament usa la STESSA query anche per risolvere
                        // l'etichetta del valore gia' selezionato: qualche macchina
                        // importata da Eureka e' agganciata a un prodotto di tipo
                        // "service" (es. BRAVILOR, mai censito come macchina a
                        // catalogo), quindi quella query non lo trova piu' e il
                        // campo mostra l'uuid grezzo invece del nome. Override
                        // esplicito, senza il filtro sul tipo, solo per l'etichetta.
                        ->getOptionLabelUsing(fn ($value) => Product::find($value)?->name)
                        ->searchable()
                        ->preload()
                        ->extraAttributes(['data-tour' => 'machine-units-field-product']),
                    // L'articolo di gestionale: e' da qui che nasce una
                    // matricola importata da Eureka (le macchine del parco
                    // installato non sono a listino e non compaiono nel
                    // selettore sopra, che filtra i prodotti tipo "machine").
                    Forms\Components\Select::make('material_id')
                        ->label('Articolo gestionale (Eureka)')
                        ->relationship('material', 'code')
                        ->getOptionLabelFromRecordUsing(fn (Material $record) => $record->display_label.' — '.$record->code)
                        ->searchable(['code', 'type', 'variant'])
                        ->helperText('Il codice articolo con cui questa macchina e\' registrata su Eureka.'),
                    Forms\Components\TextInput::make('model_name')
                        ->label('Modello (testo libero)')
                        ->helperText('Solo se non e\' a catalogo ne\' a gestionale.')
                        ->maxLength(255),
                    Forms\Components\Select::make('type')
                        ->label('Categoria impianto')
                        ->options(static::typeLabels())
                        ->helperText('Solo per impianti bevande: colonna spina (birra/vino/selz) o impianto acqua standalone. Lascia vuoto per le altre macchine (caffe, macinadosatori, ecc.).'),
                    Forms\Components\Select::make('billing_customer_id')
                        ->label('Fatturare a')
                        ->relationship('billingCustomer', 'company_name')
                        ->getOptionLabelFromRecordUsing(fn (Customer $record) => DisplayName::titleCase($record->full_name))
                        ->searchable(['company_name', 'first_name', 'last_name'])
                        ->preload()
                        ->helperText('Lascia vuoto se paga il cliente presso cui è installata questa macchina.')
                        ->extraAttributes(['data-tour' => 'machine-units-field-billing']),
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

    /**
     * Senza questo, ViewMachineUnit (ViewRecord senza infolist() definito)
     * ripiega sul form() disabilitato: per una Select con ->relationship()
     * la label giusta arriva solo via una chiamata Livewire lato client
     * dopo il caricamento — se quella chiamata non va a buon fine (JS non
     * caricato, rete lenta, ecc.) resta visibile l'id grezzo (es. "Modello
     * macchina" mostrava lo uuid di product_id invece del nome). Un infolist
     * risolve i nomi lato server nell'HTML iniziale, senza dipendere dal JS.
     */
    public static function infolist(Infolist $infolist): Infolist
    {
        return $infolist->schema([
            InfolistSection::make('Identificazione')
                ->columns(2)
                ->schema([
                    TextEntry::make('serial_number')->label('Matricola'),
                    TextEntry::make('product.name')->label('Modello (da catalogo)')->placeholder('—'),
                    TextEntry::make('material.display_label')->label('Articolo gestionale (Eureka)')->placeholder('—'),
                    TextEntry::make('model_name')->label('Modello (testo libero)')->placeholder('—'),
                    TextEntry::make('type')
                        ->label('Categoria impianto')
                        ->formatStateUsing(fn (?string $state) => static::typeLabels()[$state] ?? '—'),
                    TextEntry::make('billingCustomer.full_name')->label('Fatturare a')->placeholder('—')
                        ->formatStateUsing(fn (?string $state) => DisplayName::titleCase($state)),
                    TextEntry::make('currentCustomer.full_name')->label('Presso')->placeholder('In magazzino')
                        ->formatStateUsing(fn (?string $state) => DisplayName::titleCase($state)),
                    TextEntry::make('status')
                        ->label('Stato')
                        ->badge()
                        ->formatStateUsing(fn (string $state) => static::statusLabels()[$state] ?? 'In magazzino')
                        ->color(fn (string $state) => static::statusColors()[$state] ?? 'gray'),
                    TextEntry::make('notes')->label('Note')->placeholder('—')->columnSpanFull(),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('serial_number')->label('Matricola')->searchable(),
                Tables\Columns\TextColumn::make('display_name')
                    ->label('Modello')
                    ->searchable(
                        query: fn ($query, string $search) => $query
                            ->where('model_name', 'like', "%{$search}%")
                            ->orWhereHas('product', fn ($q) => $q->where('name', 'like', "%{$search}%"))
                            ->orWhereHas('material', fn ($q) => $q
                                ->where('type', 'like', "%{$search}%")
                                ->orWhere('code', 'like', "%{$search}%")),
                    ),
                Tables\Columns\TextColumn::make('currentCustomer.company_name')->label('Presso')->placeholder('In magazzino')->searchable()
                    ->formatStateUsing(fn (?string $state) => DisplayName::titleCase($state)),
                Tables\Columns\TextColumn::make('type')
                    ->label('Categoria')
                    ->formatStateUsing(fn (?string $state) => static::typeLabels()[$state] ?? '—')
                    ->toggleable(),
                Tables\Columns\IconColumn::make('gestionale_code')
                    ->label('Da Eureka')
                    ->boolean()
                    ->trueIcon('heroicon-o-check-circle')
                    ->falseIcon('heroicon-o-x-circle')
                    ->tooltip('Se la macchina (matricola) o il suo modello sono collegati a Eureka. Indipendente dal collegamento del modello: una macchina puo essere importata da Eureka anche se il suo modello non e ancora agganciato a un articolo.')
                    ->getStateUsing(fn (MachineUnit $record) => filled($record->gestionale_code) || $record->source === MachineUnit::SOURCE_EUREKA || filled($record->product?->gestionale_code) || filled($record->material?->gestionale_code)),
                Tables\Columns\TextColumn::make('status')
                    ->label('Stato')
                    ->badge()
                    ->formatStateUsing(fn (string $state) => static::statusLabels()[$state] ?? 'In magazzino')
                    ->color(fn (string $state) => static::statusColors()[$state] ?? 'gray'),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->label('Stato')
                    ->options(static::statusLabels()),
                Tables\Filters\SelectFilter::make('type')
                    ->label('Categoria impianto')
                    ->options(static::typeLabels()),
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
                $record->confermaCollegamentoEureka();
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
                        fn (Customer $customer) => [$customer->id => DisplayName::titleCase($customer->full_name) ?: 'Cliente senza nome']
                    ))
                    ->searchable(),
                Forms\Components\Textarea::make('notes')->label('Note sullo spostamento'),
            ])
            ->action(function (MachineUnit $record, array $data) {
                $customer = $data['customer_id'] ? Customer::find($data['customer_id']) : null;
                $record->moveTo($customer, $data['notes'] ?? null);

                Notification::make()
                    ->title($customer ? 'Macchina spostata presso '.DisplayName::titleCase($customer->company_name) : 'Macchina rientrata in magazzino')
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

    public static function statusLabels(): array
    {
        return [
            MachineUnit::STATUS_IN_MAGAZZINO => 'In magazzino',
            MachineUnit::STATUS_INSTALLATA => 'Installata',
            MachineUnit::STATUS_RIMOSSA => 'Rimossa',
        ];
    }

    public static function statusColors(): array
    {
        return [
            MachineUnit::STATUS_IN_MAGAZZINO => 'gray',
            MachineUnit::STATUS_INSTALLATA => 'success',
            MachineUnit::STATUS_RIMOSSA => 'danger',
        ];
    }

    public static function typeLabels(): array
    {
        return [
            MachineUnit::TYPE_COLONNA_SPINA => 'Colonna spina (birra/vino/selz)',
            MachineUnit::TYPE_IMPIANTO_ACQUA => 'Impianto acqua',
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
