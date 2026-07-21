<?php

namespace App\Filament\Resources;

use App\Filament\Resources\MaterialResource\Pages;
use App\Models\Material;
use App\Models\Supplier;
use Filament\Forms;
use Filament\Forms\Form;
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
                ->maxLength(255),
            Forms\Components\Select::make('supplier_id')
                ->label('Fornitore')
                ->relationship('supplier', 'name')
                ->searchable()
                ->preload()
                ->createOptionForm([
                    Forms\Components\TextInput::make('name')->label('Ragione sociale')->required(),
                ])
                ->createOptionUsing(fn (array $data) => Supplier::create($data)->id),
            Forms\Components\Select::make('category')
                ->label('Categoria')
                ->options(fn () => Material::query()->distinct()->orderBy('category')->pluck('category', 'category'))
                ->searchable()
                ->createOptionForm([
                    Forms\Components\TextInput::make('category')->label('Nuova categoria')->required(),
                ])
                ->createOptionUsing(fn (array $data) => $data['category'])
                ->required(),
            Forms\Components\TextInput::make('type')
                ->label('Tipo')
                ->required()
                ->maxLength(255),
            Forms\Components\TextInput::make('variant')
                ->label('Variante')
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
                Tables\Columns\TextColumn::make('variant')->label('Variante')->toggleable()->placeholder('—'),
                Tables\Columns\TextColumn::make('tube_diameter')->label('Tubo Ø')->placeholder('—'),
                Tables\Columns\TextColumn::make('tube_diameter_2')->label('Tubo Ø (2)')->placeholder('—')->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('thread_size')->label('Filetto')->placeholder('—'),
                Tables\Columns\TextColumn::make('thread_type')->label('Tipo filetto')->placeholder('—')->toggleable(),
                Tables\Columns\TextColumn::make('barb_diameter')->label('Codolo Ø')->placeholder('—')->toggleable(),
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
                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make(),
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
