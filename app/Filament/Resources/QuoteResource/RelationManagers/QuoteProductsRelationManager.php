<?php

namespace App\Filament\Resources\QuoteResource\RelationManagers;

use App\Filament\Actions\ConfigureMachineAction;
use App\Filament\Forms\MoneyInput;
use App\Filament\Resources\QuoteResource\Pages\ViewQuote;
use App\Models\Brand;
use App\Models\Category;
use App\Models\Product;
use App\Models\QuoteProduct;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Columns\Summarizers\Sum;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Livewire\Attributes\On;

class QuoteProductsRelationManager extends RelationManager
{
    protected static string $relationship = 'quoteProducts';

    protected static ?string $title = 'Righe preventivo';

    /**
     * Solo su Modifica: su Visualizza le righe sono gia' elencate (in sola
     * lettura, con opzioni annidate) dall'infolist di QuoteResource - due
     * tabelle uguali una sotto l'altra (una editabile con "Nuovo"/azioni su
     * una pagina di sola visualizzazione) confondevano piu' che aiutare.
     */
    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        if ($pageClass === ViewQuote::class) {
            return false;
        }

        return parent::canViewForRecord($ownerRecord, $pageClass);
    }

    public function form(Form $form): Form
    {
        return $form->schema([
            // Filtri solo per restringere l'elenco sotto (dehydrated(false):
            // non sono un campo della riga preventivo, si azzerano al
            // riaprire il form) — cercare per nome su 600+ prodotti era il
            // problema segnalato, stesso pattern di filtro a cascata gia'
            // usato in MaterialOrderResource per i materiali.
            Forms\Components\Grid::make(2)
                ->schema([
                    Forms\Components\Select::make('brand_filter')
                        ->label('Brand')
                        ->options(fn () => Brand::query()->orderBy('name')->pluck('name', 'id'))
                        ->searchable()
                        ->live()
                        ->afterStateUpdated(fn (Forms\Set $set) => $set('product_id', null))
                        ->dehydrated(false),
                    Forms\Components\Select::make('category_filter')
                        ->label('Categoria')
                        ->options(fn () => self::categoryFilterOptions())
                        ->searchable()
                        ->live()
                        ->afterStateUpdated(fn (Forms\Set $set) => $set('product_id', null))
                        ->dehydrated(false),
                ]),
            Forms\Components\Select::make('product_id')
                ->label('Prodotto')
                ->options(fn (Forms\Get $get) => Product::query()
                    ->when($get('brand_filter'), fn ($q, $brandId) => $q->where('brand_id', $brandId))
                    ->when($get('category_filter'), fn ($q, $categoryId) => $q->whereIn('category_id', self::categoryAndDescendantIds($categoryId)))
                    ->pluck('name', 'id'))
                ->searchable()
                ->live()
                // A differenza del wizard macchina (ConfigureMachineAction),
                // qui il prezzo restava vuoto e andava digitato a mano:
                // rischio concreto di prezzo sbagliato/obsoleto sulle righe
                // aggiunte manualmente (extra fuori wizard). Resta comunque
                // modificabile dopo la precompilazione.
                ->afterStateUpdated(function (?string $state, Forms\Set $set) {
                    if ($state) {
                        // formattato all'italiana: il valore grezzo del database
                        // (300.00) verrebbe letto dalla maschera come 30.000
                        $set('price', MoneyInput::format(Product::find($state)?->getCurrentPrice()?->price));
                    }
                })
                ->required(),
            Forms\Components\TextInput::make('quantity')->label('Quantità')->numeric()->default(1)->required(),
            MoneyInput::make('price')->label('Prezzo (€)')->prefix('€')->required(),
            Forms\Components\TextInput::make('discount')->label('Sconto (%)')->numeric()->default(0),
            Forms\Components\TextInput::make('tax')->label('IVA (%)')->numeric()->default(22),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('product.name')
            ->paginated(false)
            ->modifyQueryUsing(fn ($query) => $query
                // Raggruppa per riga macchina base (id della base o parent_id)
                // e mostra sempre la base prima delle sue opzioni figlie.
                ->orderByRaw('COALESCE(parent_quote_product_id, id)')
                ->orderByRaw('parent_quote_product_id IS NOT NULL')
                ->orderBy('created_at'))
            ->columns([
                Tables\Columns\TextColumn::make('product.name')
                    ->label('Prodotto')
                    ->formatStateUsing(fn (QuoteProduct $record, string $state) => $record->isOption() ? "↳ {$state}" : $state),
                Tables\Columns\TextColumn::make('quantity')->label('Quantità'),
                Tables\Columns\TextColumn::make('price')->label('Prezzo')->money('EUR'),
                Tables\Columns\TextColumn::make('discount')->label('Sconto')->suffix('%'),
                Tables\Columns\TextColumn::make('tax')->label('IVA')->suffix('%'),
                Tables\Columns\TextColumn::make('total')->label('Totale')->money('EUR')
                    ->summarize(Sum::make()->label('Totale complessivo')->money('EUR')),
            ])
            ->headerActions([
                Tables\Actions\CreateAction::make()
                    ->after(fn (RelationManager $livewire) => $livewire->getOwnerRecord()->updateTotal()),
            ])
            ->actions([
                ConfigureMachineAction::makeEdit(),
                Tables\Actions\ActionGroup::make([
                    Tables\Actions\EditAction::make()
                        ->after(fn (RelationManager $livewire) => $livewire->getOwnerRecord()->updateTotal()),
                    Tables\Actions\DeleteAction::make()
                        ->after(fn (RelationManager $livewire) => $livewire->getOwnerRecord()->updateTotal()),
                ]),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make()
                        ->after(fn (RelationManager $livewire) => $livewire->getOwnerRecord()->updateTotal()),
                ]),
            ]);
    }

    #[On('quoteProductsUpdated')]
    public function refreshAfterMachineConfigured(): void
    {
        // Handler vuoto: la presenza dell'attributo #[On] forza Livewire a
        // ri-renderizzare il componente quando ConfigureMachineAction crea le
        // righe scrivendo direttamente sul modello (fuori dal ciclo form di
        // questo RelationManager) - senza questo, le nuove righe restano
        // invisibili finche' non si interagisce di nuovo con la tabella.
    }

    /**
     * Elenco per la tendina "Categoria": le figlie compaiono raggruppate
     * sotto il nome del genitore (Filament mostra un array annidato come
     * optgroup), le categorie senza figli restano piatte. Un genitore con
     * figlie ottiene anche una voce propria "(tutte)" per poterlo scegliere
     * come filtro complessivo, non solo le sue figlie una per una.
     *
     * @return array<int|string, mixed>
     */
    private static function categoryFilterOptions(): array
    {
        $categories = Category::query()->orderBy('name')->get(['id', 'name', 'parent_id']);
        $childrenByParent = $categories->whereNotNull('parent_id')->groupBy('parent_id');

        $options = [];

        foreach ($categories->whereNull('parent_id') as $parent) {
            $children = $childrenByParent->get($parent->id, collect());

            if ($children->isEmpty()) {
                $options[$parent->id] = $parent->name;

                continue;
            }

            $options[$parent->name] = [$parent->id => "{$parent->name} (tutte)"]
                + $children->pluck('name', 'id')->all();
        }

        return $options;
    }

    /**
     * Un genitore selezionato come filtro deve includere anche i prodotti
     * delle sue categorie figlie, non solo quelli con quella categoria
     * esatta — altrimenti scegliere il genitore darebbe risultati vuoti o
     * incompleti (vedi categoryFilterOptions(), che propone il genitore
     * proprio per coprire "tutte" le figlie insieme).
     *
     * @return array<int, string>
     */
    private static function categoryAndDescendantIds(string $categoryId): array
    {
        $childIds = Category::query()->where('parent_id', $categoryId)->pluck('id')->all();

        return [$categoryId, ...$childIds];
    }
}
