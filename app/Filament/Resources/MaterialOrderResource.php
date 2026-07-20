<?php

namespace App\Filament\Resources;

use App\Exports\MaterialOrderExport;
use App\Filament\Resources\MaterialOrderResource\Pages;
use App\Filament\Resources\MaterialOrderResource\RelationManagers;
use App\Models\Material;
use App\Models\MaterialOrder;
use App\Models\Supplier;
use Barryvdh\DomPDF\Facade\Pdf;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Maatwebsite\Excel\Facades\Excel;

class MaterialOrderResource extends Resource
{
    protected static ?string $model = MaterialOrder::class;

    protected static ?string $navigationIcon = 'heroicon-o-clipboard-document-list';

    // Sezione propria in sidebar, non annegata fra le altre risorse di
    // "Interventi tecnici".
    protected static ?string $navigationGroup = 'Magazzino';

    protected static ?string $navigationLabel = 'Ordini materiali';

    protected static ?string $modelLabel = 'Ordine materiali';

    protected static ?string $pluralModelLabel = 'Ordini materiali';

    protected static ?string $recordTitleAttribute = 'number';

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\TextInput::make('number')
                ->label('Numero')
                ->required()
                ->disabled()
                ->dehydrated(),
            Forms\Components\Select::make('supplier_id')
                ->label('Fornitore')
                ->relationship('supplier', 'name')
                ->searchable()
                ->preload()
                ->createOptionForm([
                    Forms\Components\TextInput::make('name')->label('Ragione sociale')->required(),
                ])
                ->createOptionUsing(fn (array $data) => Supplier::create($data)->id)
                ->helperText('Compare nel PDF come destinatario dell\'ordine.'),
            Forms\Components\Textarea::make('notes')
                ->label('Note per il fornitore')
                ->rows(2)
                ->columnSpanFull(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('number')->label('Numero')->searchable()->sortable(),
                Tables\Columns\TextColumn::make('supplier.name')->label('Fornitore')->placeholder('—'),
                Tables\Columns\TextColumn::make('created_at')->label('Data')->dateTime('d/m/Y H:i')->sortable(),
                Tables\Columns\TextColumn::make('items_count')->label('Materiali')->counts('items'),
                Tables\Columns\TextColumn::make('notes')
                    ->label('Note')
                    ->limit(50)
                    ->placeholder('—')
                    ->tooltip(function (Tables\Columns\TextColumn $column) {
                        $state = $column->getState();

                        return is_string($state) && strlen($state) > 50 ? $state : null;
                    }),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                Tables\Filters\SelectFilter::make('supplier_id')
                    ->label('Fornitore')
                    ->relationship('supplier', 'name'),
            ])
            ->actions([
                Tables\Actions\Action::make('pdf')
                    ->label('PDF')
                    ->icon('heroicon-o-document-arrow-down')
                    ->action(fn (MaterialOrder $record) => static::streamPdf($record)),
                Tables\Actions\Action::make('excel')
                    ->label('Excel')
                    ->icon('heroicon-o-table-cells')
                    ->action(fn (MaterialOrder $record) => static::streamExcel($record)),
                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make(),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ]);
    }

    /**
     * Form del picker "Aggiungi materiali", usato dall'azione header su
     * EditMaterialOrder dentro una modale: categoria -> tipo -> variante ->
     * griglia di quantita'. Statico e senza effetti collaterali: chi lo usa
     * decide cosa fare del risultato (vedi addSelectedMaterialsToOrder).
     *
     * Se l'ordine ha gia' un fornitore assegnato, i materiali dello stesso
     * fornitore vengono proposti per primi (non filtrati via: un ordine puo'
     * comunque contenere materiali di fornitori diversi).
     *
     * @return array<Forms\Components\Component>
     */
    public static function addMaterialsFormSchema(?MaterialOrder $order = null): array
    {
        $preferredSupplierId = $order?->supplier_id;

        return [
            Forms\Components\Grid::make(2)
                ->schema([
                    // Ricerca diretta per codice John Guest, indipendente dai
                    // filtri sotto: a volte si parte gia' sapendo il codice
                    // (bolla fornitore, catalogo cartaceo) e non serve passare
                    // da categoria/tipo.
                    Forms\Components\TextInput::make('code_search')
                        ->label('Cerca per codice')
                        ->placeholder('Es. PI0108S')
                        ->live(debounce: 400)
                        ->afterStateUpdated(fn (Forms\Set $set) => $set('category_quantities', []))
                        ->dehydrated(false),
                    Forms\Components\Select::make('category_filter')
                        ->label('...oppure sfoglia per categoria')
                        ->options(fn () => Material::query()->distinct()->orderBy('category')->pluck('category', 'category'))
                        ->live()
                        ->afterStateUpdated(function (Forms\Set $set) {
                            $set('type_filter', null);
                            $set('variant_filter', null);
                            $set('category_quantities', []);
                        })
                        ->dehydrated(false),
                ]),
            // Tipo/variante compaiono solo sfogliando per categoria (non ha
            // senso restringerli mentre si cerca gia' per codice).
            Forms\Components\Grid::make(2)
                ->schema([
                    Forms\Components\Select::make('type_filter')
                        ->label('Tipo')
                        ->options(fn (Forms\Get $get) => Material::query()->where('category', $get('category_filter'))->distinct()->orderBy('type')->pluck('type', 'type'))
                        ->live()
                        ->afterStateUpdated(fn (Forms\Set $set) => $set('variant_filter', null))
                        ->dehydrated(false),
                    // Es. "Terminale diritto" nella grigia in pollici ha ~13 varianti
                    // (Filettatura conica, cilindrica, NPTF, Whitworth...): senza
                    // questo filtro resterebbero tutte mischiate nell'elenco sotto.
                    Forms\Components\Select::make('variant_filter')
                        ->label('Variante')
                        ->options(fn (Forms\Get $get) => static::distinctVariants($get('category_filter'), $get('type_filter')))
                        ->live()
                        ->visible(fn (Forms\Get $get) => filled($get('type_filter')) && static::distinctVariants($get('category_filter'), $get('type_filter')) !== [])
                        ->dehydrated(false),
                ])
                ->visible(fn (Forms\Get $get) => blank($get('code_search')) && filled($get('category_filter'))),
            // La lista di quantita' resta nascosta finche' non si cerca un
            // codice o si sceglie anche il tipo: una categoria intera puo'
            // avere 100+ righe, troppe da mostrare tutte insieme.
            Forms\Components\Grid::make(2)
                ->schema(fn (Forms\Get $get) => static::materialQuantityInputs($get, $preferredSupplierId))
                ->visible(fn (Forms\Get $get) => filled($get('code_search')) || filled($get('type_filter'))),
        ];
    }

    /**
     * Somma le quantita' scelte nel picker sulle righe gia' presenti
     * nell'ordine, scrivendo subito su DB (non su stato di form): un
     * refresh della pagina non deve mai far perdere niente.
     *
     * @return int numero di materiali aggiunti/aggiornati
     */
    public static function addSelectedMaterialsToOrder(MaterialOrder $order, array $categoryQuantities): int
    {
        $quantities = collect($categoryQuantities)
            ->map(fn ($qty) => (int) $qty)
            ->filter(fn (int $qty) => $qty > 0);

        foreach ($quantities as $materialId => $quantity) {
            $item = $order->items()->firstOrNew(['material_id' => $materialId]);
            $item->quantity = ($item->exists ? $item->quantity : 0) + $quantity;
            $item->save();
        }

        return $quantities->count();
    }

    /**
     * Un campo quantità per ogni materiale trovato, per compilarli tutti
     * insieme invece di ripetere "cerca codice, seleziona, aggiungi" una
     * riga alla volta. Due modalita': ricerca diretta per codice su tutto
     * il catalogo (code_search valorizzato, ignora categoria/tipo/variante),
     * oppure sfoglia per categoria/tipo/variante scelti.
     *
     * @return array<Forms\Components\TextInput>
     */
    private static function materialQuantityInputs(Forms\Get $get, ?string $preferredSupplierId = null): array
    {
        $codeSearch = $get('code_search');

        $query = Material::query();

        if (filled($codeSearch)) {
            $query->where('code', 'like', "%{$codeSearch}%")->limit(30);
        } else {
            $category = $get('category_filter');

            if (blank($category)) {
                return [];
            }

            $type = $get('type_filter');
            $variant = $get('variant_filter');

            $query->where('category', $category)
                ->when(filled($type), fn ($q) => $q->where('type', $type))
                ->when(filled($variant), fn ($q) => $q->where('variant', $variant));
        }

        return $query
            ->when($preferredSupplierId, fn ($q) => $q->orderByRaw('CASE WHEN supplier_id = ? THEN 0 ELSE 1 END', [$preferredSupplierId]))
            ->orderBy('category')
            ->orderBy('type')
            ->orderBy('variant')
            ->orderBy('code')
            ->get()
            ->map(fn (Material $material) => Forms\Components\TextInput::make("category_quantities.{$material->id}")
                ->label(static::materialLabel($material))
                ->numeric()
                ->minValue(0)
                ->default(0)
                ->dehydrated(false))
            ->all();
    }

    /**
     * Sotto lo stesso "tipo" (es. Terminale diritto) possono convivere
     * decine di righe che differiscono solo per filetto/codolo, non per
     * diametro tubo: l'etichetta deve riportare anche quelli o le righe
     * risultano indistinguibili nell'elenco per categoria.
     */
    public static function materialLabel(Material|string|null $material): ?string
    {
        if (is_string($material)) {
            $material = Material::find($material);
        }

        if (! $material) {
            return null;
        }

        $details = [];

        if ($material->tube_diameter) {
            $details[] = 'Ø '.$material->tube_diameter.($material->tube_diameter_2 ? " x {$material->tube_diameter_2}" : '');
        }

        if ($material->thread_size || $material->thread_type) {
            $details[] = trim('filetto '.$material->thread_size.' '.$material->thread_type);
        }

        if ($material->barb_diameter) {
            $details[] = "codolo {$material->barb_diameter}";
        }

        $label = $material->code.' — '.($material->variant ?: $material->type);

        if ($details !== []) {
            $label .= ' ('.implode(' · ', $details).')';
        }

        return $label;
    }

    /**
     * @return array<string, string>
     */
    public static function distinctVariants(?string $category, ?string $type): array
    {
        if (blank($category) || blank($type)) {
            return [];
        }

        return Material::query()
            ->where('category', $category)
            ->where('type', $type)
            ->whereNotNull('variant')
            ->distinct()
            ->orderBy('variant')
            ->pluck('variant', 'variant')
            ->all();
    }

    public static function streamPdf(MaterialOrder $record)
    {
        $record->load(['items.material', 'tenant', 'supplier']);

        $rows = $record->items
            ->sortBy(fn ($item) => $item->material->category.$item->material->code)
            ->map(fn ($item) => ['material' => $item->material, 'quantity' => $item->quantity])
            ->values();

        $pdf = Pdf::loadView('pdf.ordine-materiali', [
            'rows' => $rows,
            'notes' => $record->notes,
            'tenant' => $record->tenant,
            'supplier' => $record->supplier,
            'number' => $record->number,
            'date' => $record->created_at,
        ]);

        return response()->streamDownload(
            fn () => print ($pdf->output()),
            "{$record->number}.pdf"
        );
    }

    public static function streamExcel(MaterialOrder $record)
    {
        $record->load('items.material');

        return Excel::download(new MaterialOrderExport($record), "{$record->number}.xlsx");
    }

    public static function getRelations(): array
    {
        return [
            RelationManagers\ItemsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListMaterialOrders::route('/'),
            'edit' => Pages\EditMaterialOrder::route('/{record}/edit'),
        ];
    }
}
