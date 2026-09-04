<?php

namespace App\Filament\Resources;

use App\Filament\Resources\MaterialResource\Pages;
use App\Models\Material;
use App\Models\Supplier;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Infolists\Components\Section as InfolistSection;
use Filament\Infolists\Components\TextEntry;
use Filament\Infolists\Infolist;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

class MaterialResource extends Resource
{
    protected static ?string $model = Material::class;

    // Catalogo condiviso (tenant_id nullable): stessa nota di CategoryResource.
    protected static bool $isScopedToTenant = false;

    protected static ?string $navigationIcon = 'heroicon-o-wrench-screwdriver';

    protected static ?string $navigationGroup = 'Magazzino';

    protected static ?string $navigationLabel = 'Materiali';

    protected static ?string $modelLabel = 'Materiale';

    protected static ?string $pluralModelLabel = 'Materiali';

    protected static ?string $recordTitleAttribute = 'code';

    public static function infolist(Infolist $infolist): Infolist
    {
        return $infolist->schema([
            InfolistSection::make('Materiale')
                ->columns(2)
                ->schema([
                    TextEntry::make('code')->label('Codice'),
                    TextEntry::make('supplier.name')->label('Fornitore')->placeholder('—'),
                    TextEntry::make('list_price')->label('Prezzo di listino')->money('EUR')->placeholder('—'),
                    TextEntry::make('category')->label('Categoria')->badge(),
                    TextEntry::make('type')->label('Tipo'),
                    TextEntry::make('variant')->label('Variante')->placeholder('—'),
                    TextEntry::make('tube_diameter')->label('Tubo Ø')->placeholder('—'),
                    TextEntry::make('tube_diameter_2')->label('Tubo Ø (2)')->placeholder('—'),
                    TextEntry::make('thread_size')->label('Filetto')->placeholder('—'),
                    TextEntry::make('thread_type')->label('Tipo filetto')->placeholder('—'),
                    TextEntry::make('barb_diameter')->label('Codolo Ø')->placeholder('—'),
                    TextEntry::make('notes')->label('Note')->placeholder('—')->columnSpanFull(),
                ]),
        ]);
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make('Materiale')
                ->columns(2)
                ->schema(static::formFields()),
        ]);
    }

    /**
     * Campi condivisi fra il form completo della resource e il mini form di
     * creazione rapida dentro "Aggiungi materiali" su un ordine (vedi
     * MaterialOrderResource::EditMaterialOrder), cosi' non restano due copie
     * a rischio di disallinearsi.
     *
     * @return array<Forms\Components\Component>
     */
    public static function formFields(): array
    {
        return [
            Forms\Components\TextInput::make('code')
                ->label('Codice')
                ->required()
                // Tabella e record-da-ignorare espliciti: questo campo e'
                // condiviso anche col mini form di creazione rapida dentro
                // l'ordine (EditMaterialOrder). Senza 'table' Filament dedurrebbe
                // la tabella dal modello della pagina ospitante (MaterialOrder);
                // senza l'ignorable esplicito farebbe lo stesso anche per il
                // record da escludere dal controllo di unicita' (li' non c'e'
                // comunque nessun Material da ignorare, e' sempre una creazione).
                ->unique(
                    table: Material::class,
                    ignorable: fn (?Model $record) => $record instanceof Material ? $record : null,
                )
                ->maxLength(255)
                ->extraAttributes(['data-tour' => 'materials-field-code']),
            Forms\Components\Select::make('supplier_id')
                ->label('Fornitore')
                ->relationship('supplier', 'name')
                ->searchable()
                ->preload()
                ->createOptionForm([
                    Forms\Components\TextInput::make('name')->label('Ragione sociale')->required(),
                ])
                ->createOptionUsing(fn (array $data) => Supplier::create($data)->id),
            Forms\Components\TextInput::make('list_price')
                ->label('Prezzo di listino')
                ->numeric()
                ->prefix('€')
                ->helperText('Solo per consultazione qui in Materiali: non viene mai usato per calcolare automaticamente i prezzi sui rapportini.'),
            Forms\Components\Select::make('category')
                ->label('Categoria')
                ->options(fn () => Material::query()->distinct()->orderBy('category')->pluck('category', 'category'))
                ->searchable()
                ->createOptionForm([
                    Forms\Components\TextInput::make('category')->label('Nuova categoria')->required(),
                ])
                ->createOptionUsing(fn (array $data) => $data['category'])
                ->required()
                ->extraAttributes(['data-tour' => 'materials-field-category']),
            Forms\Components\TextInput::make('type')
                ->label('Tipo')
                ->required()
                ->maxLength(255)
                ->extraAttributes(['data-tour' => 'materials-field-type']),
            Forms\Components\TextInput::make('variant')
                ->label('Variante')
                ->maxLength(255),
            // Si compila solo sui MODELLI di macchina, non sui ricambi: e' il
            // codice che la scorciatoia "Manutenzione ordinaria" del
            // rapportino mette in riga quando su quel modello si interviene.
            // Il pagante con listino proprio prende comunque la sua variante
            // (F3 -> F3GOPPION), vedi config/tariffe.php.
            Forms\Components\TextInput::make('maintenance_code')
                ->label('Codice manutenzione ordinaria')
                ->placeholder('es. F3, C2, DC2, MANA300')
                ->helperText('Solo per i modelli di macchina: il codice della manutenzione dovuta su questo modello.')
                ->maxLength(255),
            Forms\Components\TextInput::make('tube_diameter')
                ->label('Tubo Ø'),
            Forms\Components\TextInput::make('tube_diameter_2')
                ->label('Tubo Ø (2)'),
            Forms\Components\TextInput::make('thread_size')
                ->label('Filetto'),
            Forms\Components\TextInput::make('thread_type')
                ->label('Tipo filetto')
                ->helperText('Es. BSP, BSPT, NPTF, UNS, BSW, MFL, FFL'),
            Forms\Components\TextInput::make('barb_diameter')
                ->label('Codolo Ø'),
            Forms\Components\Textarea::make('notes')
                ->label('Note')
                ->columnSpanFull(),
        ];
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('code')->label('Codice')->searchable()->sortable(),
                Tables\Columns\TextColumn::make('supplier.name')->label('Fornitore')->placeholder('—')->toggleable(),
                Tables\Columns\TextColumn::make('category')->label('Categoria')->badge()->toggleable(),
                Tables\Columns\TextColumn::make('type')->label('Tipo')->searchable()->wrap(),
                Tables\Columns\TextColumn::make('list_price')->label('Prezzo di listino')->money('EUR')->placeholder('—')->sortable(),
            ])
            ->defaultSort('category')
            ->filters([
                Tables\Filters\SelectFilter::make('category')
                    ->label('Categoria')
                    ->options(fn () => Material::query()->distinct()->orderBy('category')->pluck('category', 'category')),
                Tables\Filters\SelectFilter::make('supplier_id')
                    ->label('Fornitore')
                    ->relationship('supplier', 'name'),
            ])
            ->actions([
                Tables\Actions\ViewAction::make()
                    ->color('gray'),
                Tables\Actions\ActionGroup::make([
                    Tables\Actions\EditAction::make(),
                    Tables\Actions\DeleteAction::make(),
                ]),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\BulkAction::make('renameCategory')
                        ->label('Rinomina categoria')
                        ->icon('heroicon-o-pencil')
                        ->form([
                            Forms\Components\TextInput::make('category')
                                ->label('Nuovo nome categoria')
                                ->required(),
                        ])
                        ->requiresConfirmation()
                        ->modalDescription('La nuova categoria sostituira\' quella attuale su tutti i materiali selezionati.')
                        ->action(fn (\Illuminate\Support\Collection $records, array $data) => $records
                            ->each(fn (Material $material) => $material->update(['category' => $data['category']])))
                        ->deselectRecordsAfterCompletion(),
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ManageMaterials::route('/'),
        ];
    }
}
