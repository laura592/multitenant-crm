<?php

namespace App\Filament\Resources;

use App\Filament\Resources\MaterialOrderResource\Pages;
use App\Models\Material;
use App\Models\MaterialOrder;
use Barryvdh\DomPDF\Facade\Pdf;
use Filament\Facades\Filament;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

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
                ->disabled(fn (?MaterialOrder $record) => $record !== null)
                ->dehydrated()
                ->default(fn () => MaterialOrder::nextNumberForTenant(Filament::getTenant()?->id)),
            // Ripiegata quando si riapre un ordine gia' compilato: la priorita'
            // a quel punto e' rivedere/correggere le righe esistenti qui sotto,
            // non ritrovarsi subito 4 filtri + una griglia di quantita'.
            Forms\Components\Section::make('Aggiungi materiali')
                ->icon('heroicon-o-plus-circle')
                ->collapsible()
                ->collapsed(fn (?MaterialOrder $record) => $record !== null)
                ->schema([
                    Forms\Components\Select::make('category_filter')
                        ->label('Categoria')
                        ->options(fn () => Material::query()->distinct()->orderBy('category')->pluck('category', 'category'))
                        ->live()
                        ->afterStateUpdated(function (Forms\Set $set) {
                            $set('type_filter', null);
                            $set('variant_filter', null);
                            $set('category_quantities', []);
                        })
                        ->dehydrated(false),
                    // Il resto compare solo dopo aver scelto la categoria, per non
                    // buttare in faccia tutti i filtri fin da subito.
                    Forms\Components\Grid::make(3)
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
                            Forms\Components\TextInput::make('category_search')
                                ->label('Filtro per codice')
                                ->live(debounce: 400)
                                ->dehydrated(false),
                        ])
                        ->visible(fn (Forms\Get $get) => filled($get('category_filter'))),
                    // La lista di quantita' resta nascosta finche' non si sceglie
                    // anche il tipo: una categoria intera puo' avere 100+ righe,
                    // troppe da mostrare tutte insieme.
                    Forms\Components\Grid::make(2)
                        ->schema(fn (Forms\Get $get) => static::categoryMaterialInputs($get))
                        ->visible(fn (Forms\Get $get) => filled($get('type_filter'))),
                    Forms\Components\Actions::make([
                        Forms\Components\Actions\Action::make('addFromCategory')
                            ->label('Aggiungi selezionati')
                            ->icon('heroicon-o-plus')
                            ->button()
                            ->visible(fn (Forms\Get $get) => filled($get('type_filter')))
                            ->action(function (Forms\Get $get, Forms\Set $set) {
                                $quantities = collect($get('category_quantities') ?? [])
                                    ->map(fn ($qty) => (int) $qty)
                                    ->filter(fn (int $qty) => $qty > 0);

                                if ($quantities->isEmpty()) {
                                    return;
                                }

                                // Array semplice, non Collection: l'assegnazione annidata
                                // $items[$key]['quantity'] = ... non muta una Collection
                                // (ArrayAccess restituisce per valore), serve un array vero.
                                $items = $get('items') ?? [];

                                foreach ($quantities as $materialId => $quantity) {
                                    $existingKey = collect($items)->search(fn (array $item) => ($item['material_id'] ?? null) === $materialId);

                                    if ($existingKey !== false) {
                                        $items[$existingKey]['quantity'] = (int) ($items[$existingKey]['quantity'] ?? 0) + $quantity;
                                    } else {
                                        $items[] = ['material_id' => $materialId, 'quantity' => $quantity];
                                    }
                                }

                                $set('items', array_values($items));

                                // Le quantita' restano visibili nei campi (non si
                                // azzerano): serve a vedere subito cosa e' stato
                                // appena aggiunto, invece di ritrovarsi campi vuoti
                                // e il dubbio se il click abbia funzionato o no.
                                Notification::make()
                                    ->title($quantities->count().' material'.($quantities->count() === 1 ? 'e aggiunto' : 'i aggiunti')." all'ordine")
                                    ->success()
                                    ->send();
                            }),
                    ]),
                ])
                ->columnSpanFull(),
            Forms\Components\Section::make('Materiali nell\'ordine')
                ->icon('heroicon-o-clipboard-document-list')
                ->schema([
                    // Righe compilate solo da "Aggiungi materiali" qui sopra: niente
                    // tendina "materiale" da riempire a mano, altrimenti risulterebbe
                    // sempre vuota/obbligatoria anche quando le righe arrivano gia'
                    // popolate dal blocco per categoria.
                    Forms\Components\Repeater::make('items')
                        ->hiddenLabel()
                        ->relationship()
                        // Etichetta della riga nella testata della card: e' li' che
                        // Filament mette anche il pulsante cestino, cosi' finiscono
                        // allineati sulla stessa riga invece di scontrarsi col
                        // contenuto sotto.
                        ->itemLabel(fn (array $state) => static::materialLabel($state['material_id'] ?? null) ?? 'Materiale')
                        ->schema([
                            Forms\Components\Hidden::make('material_id')->required(),
                            Forms\Components\TextInput::make('quantity')
                                ->label('Quantità')
                                ->numeric()
                                ->minValue(1)
                                ->required(),
                        ])
                        ->addable(false)
                        ->deletable()
                        ->reorderable(false)
                        ->required()
                        ->minItems(1)
                        ->columnSpanFull(),
                    Forms\Components\Textarea::make('notes')
                        ->label('Note per il fornitore')
                        ->rows(2)
                        ->columnSpanFull(),
                ])
                ->columnSpanFull(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('number')->label('Numero')->searchable()->sortable(),
                Tables\Columns\TextColumn::make('created_at')->label('Data')->dateTime('d/m/Y H:i')->sortable(),
                Tables\Columns\TextColumn::make('items_count')->label('Materiali')->counts('items'),
                Tables\Columns\TextColumn::make('notes')->label('Note')->limit(50)->placeholder('—'),
            ])
            ->defaultSort('created_at', 'desc')
            ->actions([
                Tables\Actions\Action::make('pdf')
                    ->label('PDF')
                    ->icon('heroicon-o-document-arrow-down')
                    ->action(fn (MaterialOrder $record) => static::streamPdf($record)),
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
     * Un campo quantità per ogni materiale della categoria scelta (più
     * l'eventuale filtro testuale), per compilarli tutti insieme invece di
     * ripetere "cerca codice, seleziona, aggiungi" una riga alla volta.
     *
     * @return array<Forms\Components\TextInput>
     */
    private static function categoryMaterialInputs(Forms\Get $get): array
    {
        $category = $get('category_filter');

        if (blank($category)) {
            return [];
        }

        $type = $get('type_filter');
        $variant = $get('variant_filter');
        $search = $get('category_search');

        return Material::query()
            ->where('category', $category)
            ->when(filled($type), fn ($query) => $query->where('type', $type))
            ->when(filled($variant), fn ($query) => $query->where('variant', $variant))
            ->when(filled($search), fn ($query) => $query->where(
                fn ($q) => $q->where('code', 'like', "%{$search}%")->orWhere('type', 'like', "%{$search}%")
            ))
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
    private static function materialLabel(Material|string|null $material): ?string
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
    private static function distinctVariants(?string $category, ?string $type): array
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
        $record->load(['items.material', 'tenant']);

        $rows = $record->items
            ->sortBy(fn ($item) => $item->material->category.$item->material->code)
            ->map(fn ($item) => ['material' => $item->material, 'quantity' => $item->quantity])
            ->values();

        $pdf = Pdf::loadView('pdf.ordine-materiali', [
            'rows' => $rows,
            'notes' => $record->notes,
            'tenant' => $record->tenant,
            'number' => $record->number,
            'date' => $record->created_at,
        ]);

        return response()->streamDownload(
            fn () => print ($pdf->output()),
            "{$record->number}.pdf"
        );
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListMaterialOrders::route('/'),
            'create' => Pages\CreateMaterialOrder::route('/create'),
            'edit' => Pages\EditMaterialOrder::route('/{record}/edit'),
        ];
    }
}
