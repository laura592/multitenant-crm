<?php

namespace App\Filament\Resources;

use App\Filament\Forms\MoneyInput;
use App\Filament\Resources\ProductResource\Pages;
use App\Filament\Resources\ProductResource\RelationManagers;
use App\Models\Product;
use App\Models\Tenant;
use App\Support\Gestionale\EurekaClient;
use Filament\Actions\MountableAction;
use Filament\Facades\Filament;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class ProductResource extends Resource
{
    protected static ?string $model = Product::class;

    // Catalogo condiviso (tenant_id nullable, §4.2/§11.2): vedi nota identica
    // su CategoryResource.
    protected static bool $isScopedToTenant = false;

    protected static ?string $navigationIcon = 'heroicon-o-cube';

    protected static ?string $navigationGroup = 'Catalogo';

    protected static ?string $navigationLabel = 'Prodotti';

    protected static ?string $modelLabel = 'Prodotto';

    protected static ?string $pluralModelLabel = 'Prodotti';

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make('Dati prodotto')
                ->columns(2)
                ->schema([
                    Forms\Components\Select::make('type')
                        ->label('Tipo')
                        ->options([
                            Product::TYPE_MACHINE => 'Macchina (variante apparecchio base)',
                            Product::TYPE_AUXILIARY_UNIT => 'Unità ausiliaria',
                            Product::TYPE_OPTION => 'Opzione',
                            Product::TYPE_ACCESSORY => 'Accessorio',
                            Product::TYPE_SERVICE => 'Servizio/licenza',
                        ])
                        ->required()
                        ->live(),
                    Forms\Components\Select::make('product_family_id')
                        ->label('Famiglia macchina')
                        ->relationship('family', 'name')
                        ->visible(fn (Forms\Get $get) => $get('type') === Product::TYPE_MACHINE)
                        ->searchable()
                        ->preload(),
                    Forms\Components\TextInput::make('sku')
                        ->label('SKU')
                        ->required()
                        ->unique(ignoreRecord: true)
                        ->maxLength(255),
                    Forms\Components\TextInput::make('name')
                        ->label('Nome')
                        ->required()
                        ->maxLength(255),
                    Forms\Components\Select::make('category_id')
                        ->label('Categoria')
                        ->relationship('category', 'name')
                        ->searchable()
                        ->preload(),
                    Forms\Components\Select::make('brand_id')
                        ->label('Brand')
                        ->relationship('brand', 'name')
                        ->searchable()
                        ->preload(),
                    Forms\Components\Select::make('source')
                        ->label('Origine')
                        ->options([
                            Product::SOURCE_FRANKE => 'Franke ufficiale',
                            Product::SOURCE_THIRD_PARTY => 'Terzo',
                        ])
                        ->helperText('Per la garanzia: art. 11.3 del contratto di distribuzione'),
                    Forms\Components\TextInput::make('gestionale_code')
                        ->label('Codice Eureka (sl_articolo)')
                        ->numeric()
                        // Il codice Eureka non va mai modificato a mano: si
                        // collega solo tramite l'azione "Cerca su Eureka"
                        // nella tabella, che garantisce che punti a un
                        // articolo davvero esistente sul gestionale.
                        ->disabled()
                        ->dehydrated(false)
                        ->helperText('Usa l\'azione "Cerca su Eureka" per collegarlo. Necessario solo per inviare a Eureka i rapportini che usano questo prodotto.'),
                    Forms\Components\Textarea::make('description')
                        ->label('Descrizione')
                        ->columnSpanFull(),
                    Forms\Components\FileUpload::make('image')
                        ->label('Immagine')
                        ->image()
                        ->directory('products')
                        ->maxSize(5120)
                        ->columnSpanFull(),
                ]),
            Forms\Components\Section::make('Visibilità')
                ->visible(fn () => (bool) auth()->user()?->is_super_admin)
                ->schema([
                    Forms\Components\Select::make('tenant_id')
                        ->label('Visibilità')
                        ->helperText('Vuoto = catalogo condiviso con tutti i partner. Un tenant = riservato a quell\'azienda.')
                        ->options(fn () => Tenant::query()->orderBy('name')->pluck('name', 'id'))
                        ->searchable()
                        ->dehydrated(fn () => (bool) auth()->user()?->is_super_admin),
                ]),
            Forms\Components\Section::make('Listino prezzi')
                ->schema([
                    Forms\Components\Repeater::make('prices')
                        ->relationship('prices')
                        ->label('')
                        ->columns(3)
                        ->schema([
                            MoneyInput::make('price')
                                ->label('Prezzo (€)')
                                ->prefix('€')
                                ->required(),
                            Forms\Components\DatePicker::make('valid_from')->label('Valido da'),
                            Forms\Components\DatePicker::make('valid_to')->label('Valido a'),
                        ])
                        ->defaultItems(1)
                        ->addActionLabel('Aggiungi prezzo')
                        ->reorderable(false),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\ImageColumn::make('image')->label(''),
                Tables\Columns\TextColumn::make('sku')->label('SKU')->searchable()->sortable(),
                Tables\Columns\TextColumn::make('name')->label('Nome')->searchable()->sortable()
                    ->limit(40)->tooltip(fn (Product $record) => strlen($record->name) > 40 ? $record->name : null),
                Tables\Columns\TextColumn::make('type')
                    ->label('Tipo')
                    ->badge()
                    ->formatStateUsing(fn (string $state) => static::typeLabels()[$state] ?? $state)
                    ->color(fn (string $state) => static::typeColors()[$state] ?? 'gray'),
                Tables\Columns\TextColumn::make('family.name')->label('Famiglia')->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('tenant.name')->label('Visibilità')->placeholder('Condiviso')->badge()
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('prices.price')
                    ->label('Prezzo corrente')
                    ->state(fn (Product $record) => $record->getCurrentPrice()?->price)
                    ->money('EUR'),
            ])
            ->defaultSort('name')
            ->filters([
                Tables\Filters\SelectFilter::make('type')
                    ->label('Tipo')
                    ->options(static::typeLabels()),
                Tables\Filters\SelectFilter::make('product_family_id')
                    ->label('Famiglia')
                    ->relationship('family', 'name')
                    ->searchable()
                    ->preload(),
                Tables\Filters\Filter::make('gestionale_suggested_code')
                    ->label('Collegamento proposto')
                    ->query(fn ($query) => $query->whereNotNull('gestionale_suggested_code')),
            ])
            ->actions([
                Tables\Actions\ViewAction::make()
                    ->color('gray'),
                Tables\Actions\ActionGroup::make([
                    static::confermaCollegamentoGestionaleAction(Tables\Actions\Action::make('conferma_collegamento_gestionale')),
                    static::scartaCollegamentoGestionaleAction(Tables\Actions\Action::make('scarta_collegamento_gestionale')),
                    static::cercaEurekaAction(Tables\Actions\Action::make('cerca_eureka')),
                    Tables\Actions\EditAction::make()
                        ->color('gray'),
                    Tables\Actions\DeleteAction::make(),
                ]),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ]);
    }

    /**
     * Azioni condivise fra il menu di riga della tabella e gli header di
     * ViewProduct/EditProduct: da quando il click riga apre la view invece
     * dell'edit, chi e' gia' dentro il record singolo non passa piu' dalla
     * tabella per usarle. Vedi nota identica su MachineUnitResource.
     */
    public static function confermaCollegamentoGestionaleAction(MountableAction $action): MountableAction
    {
        return $action
            ->label(fn (Product $record) => 'Conferma collegamento: '.($record->gestionale_suggested_label ?? "#{$record->gestionale_suggested_code}"))
            ->icon('heroicon-o-link')
            ->color('warning')
            ->visible(fn (Product $record): bool => $record->gestionale_suggested_code !== null)
            ->requiresConfirmation()
            ->modalDescription('Il sync automatico ha trovato questo possibile collegamento su Eureka. Confermi?')
            ->action(function (Product $record) {
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
            ->color('gray')
            ->visible(fn (Product $record): bool => $record->gestionale_suggested_code !== null)
            ->requiresConfirmation()
            ->action(fn (Product $record) => $record->update([
                'gestionale_suggested_code' => null,
                'gestionale_suggested_label' => null,
            ]));
    }

    public static function cercaEurekaAction(MountableAction $action): MountableAction
    {
        return $action
            ->label('Cerca su Eureka')
            ->icon('heroicon-o-magnifying-glass')
            ->color('gray')
            ->visible(fn (Product $record): bool => $record->type === Product::TYPE_MACHINE && (Filament::getTenant()?->hasGestionaleEurekaCredentials() ?? false))
            ->fillForm(fn (Product $record): array => ['gestionale_code' => $record->gestionale_code])
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
                    ->helperText('Digita il nome del modello (es. "ICON", "XT") per cercare nel catalogo Eureka. Il risultato non e\' sempre 1:1: scegli quello giusto a mano.'),
            ])
            ->action(function (array $data, Product $record) {
                $record->update(['gestionale_code' => $data['gestionale_code']]);
                Notification::make()->title('Codice Eureka salvato')->success()->send();
            });
    }

    public static function getRelations(): array
    {
        return [
            RelationManagers\SlotsRelationManager::class,
        ];
    }

    public static function typeLabels(): array
    {
        return [
            Product::TYPE_MACHINE => 'Macchina',
            Product::TYPE_AUXILIARY_UNIT => 'Unità ausiliaria',
            Product::TYPE_OPTION => 'Opzione',
            Product::TYPE_ACCESSORY => 'Accessorio',
            Product::TYPE_SERVICE => 'Servizio',
        ];
    }

    public static function typeColors(): array
    {
        return [
            Product::TYPE_MACHINE => 'primary',
            Product::TYPE_AUXILIARY_UNIT => 'info',
            Product::TYPE_OPTION => 'warning',
            Product::TYPE_ACCESSORY => 'gray',
            Product::TYPE_SERVICE => 'success',
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListProducts::route('/'),
            'create' => Pages\CreateProduct::route('/create'),
            'view' => Pages\ViewProduct::route('/{record}'),
            'edit' => Pages\EditProduct::route('/{record}/edit'),
        ];
    }
}
