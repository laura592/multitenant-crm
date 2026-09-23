<?php

namespace App\Filament\Resources;

use App\Filament\Resources\PriceListResource\Pages;
use App\Models\PriceList;
use App\Support\Assistenza\ContrattoAssistenzaPdf;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Resources\Resource;
use Illuminate\Support\Facades\Storage;

/**
 * "Documenti": listini, modelli dei contratti di assistenza e altri PDF
 * (fino al 21/09/2026 era "Listini"). Resta PriceListResource perche' da
 * qui nascono i permessi price::list di tutti i ruoli: vedi PriceList.
 */
class PriceListResource extends Resource
{
    protected static ?string $model = PriceList::class;

    // Catalogo condiviso (tenant_id nullable, come Supplier/Material): lo
    // scoping vero lo fa gia' BelongsToTenant sul modello.
    protected static bool $isScopedToTenant = false;

    protected static ?string $navigationIcon = 'heroicon-o-folder';

    protected static ?string $navigationGroup = 'Magazzino';


    protected static ?int $navigationSort = 4;

    protected static ?string $navigationLabel = 'Documenti';

    protected static ?string $modelLabel = 'Documento';

    protected static ?string $pluralModelLabel = 'Documenti';

    protected static ?string $recordTitleAttribute = 'name';

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make('Documento')
                ->columns(2)
                ->schema([
                    Forms\Components\Select::make('category')
                        ->label('Categoria')
                        ->options(PriceList::CATEGORIE)
                        ->default(PriceList::LISTINO)
                        ->required()
                        ->selectablePlaceholder(false)
                        ->live()
                        // Il file appena caricato lo controlla il campo PDF;
                        // qui quello gia' salvato, quando un documento
                        // esistente diventa un contratto.
                        ->rules([
                            fn (Get $get) => function (string $attribute, $value, \Closure $fail) use ($get) {
                                if (! static::isContratto($value)) {
                                    return;
                                }

                                foreach ((array) $get('file_path') as $file) {
                                    if (is_string($file) && Storage::disk('public')->exists($file)
                                        && ! ContrattoAssistenzaPdf::modelloLeggibile(Storage::disk('public')->path($file))) {
                                        $fail('Il PDF già caricato non si riesce a usare per comporre il contratto: caricane uno nuovo.');
                                    }
                                }
                            },
                        ])
                        ->helperText(fn (Get $get) => static::isContratto($get('category'))
                            ? 'Dal giorno di "Valido dal" i contratti scaricati dai preventivi usano questo PDF, con davanti la pagina dei dati del cliente. Se ce ne sono più di uno valido, vale quello con la decorrenza più recente.'
                            : null)
                        ->columnSpanFull()
                        ->extraAttributes(['data-tour' => 'price-lists-field-category']),
                    Forms\Components\TextInput::make('name')
                        ->label('Nome')
                        ->required()
                        ->maxLength(255)
                        ->columnSpanFull()
                        ->extraAttributes(['data-tour' => 'price-lists-field-name']),
                    Forms\Components\Select::make('supplier_id')
                        ->label('Fornitore')
                        ->relationship('supplier', 'name')
                        ->searchable()
                        ->preload()
                        ->hidden(fn (Get $get) => static::isContratto($get('category')))
                        ->extraAttributes(['data-tour' => 'price-lists-field-supplier']),
                    Forms\Components\FileUpload::make('file_path')
                        ->label('File PDF')
                        ->directory(fn (Get $get) => PriceList::cartella($get('category')))
                        // Col nome del documento, non col codice a caso di
                        // Filament; senza nome, quello del file caricato.
                        ->getUploadedFileNameForStorageUsing(fn (Get $get, \Livewire\Features\SupportFileUploads\TemporaryUploadedFile $file) => PriceList::nomeFileLibero(
                            filled($get('name')) ? $get('name') : pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME),
                            PriceList::cartella($get('category')),
                        ))
                        ->acceptedFileTypes(['application/pdf'])
                        ->maxSize(20480)
                        ->openable()
                        ->downloadable()
                        ->deletable(false)
                        // Un contratto senza PDF non serve a niente, e il PDF
                        // deve essere uno che FPDI sa mettere in coda.
                        ->required(fn (Get $get) => static::isContratto($get('category')))
                        ->rules([
                            fn (Get $get) => function (string $attribute, $value, \Closure $fail) use ($get) {
                                if (! static::isContratto($get('category')) || ! $value instanceof \Livewire\Features\SupportFileUploads\TemporaryUploadedFile) {
                                    return;
                                }

                                if (! ContrattoAssistenzaPdf::modelloLeggibile($value->getRealPath())) {
                                    $fail('Questo PDF non si riesce a usare per comporre il contratto. Prova a esportarlo di nuovo (es. "Stampa → Salva come PDF") e ricaricalo.');
                                }
                            },
                        ])
                        ->helperText(fn (Get $get) => static::isContratto($get('category'))
                            ? 'Il PDF del contratto così come va firmato: non viene ricompresso né modificato.'
                            : 'Per sostituire il PDF, caricane uno nuovo: non è possibile rimuoverlo senza sostituirlo. Il file viene ottimizzato automaticamente dopo il salvataggio se troppo pesante.')
                        ->extraAttributes(['data-tour' => 'price-lists-field-file']),
                    Forms\Components\DatePicker::make('valid_from')->label('Valido dal'),
                    Forms\Components\DatePicker::make('valid_to')
                        ->label('Valido fino al')
                        ->afterOrEqual('valid_from')
                        ->helperText('Lascia vuoto se non ha una scadenza nota.'),
                    Forms\Components\Textarea::make('notes')->label('Note')->columnSpanFull(),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('valid_from', 'desc')
            ->columns([
                Tables\Columns\TextColumn::make('name')->wrap()->label('Nome')->searchable()->sortable(),
                Tables\Columns\TextColumn::make('category')
                    ->label('Categoria')
                    ->badge()
                    ->formatStateUsing(fn (?string $state) => PriceList::CATEGORIE[$state] ?? $state)
                    ->color(fn (?string $state) => match (true) {
                        $state === PriceList::LISTINO => 'info',
                        static::isContratto($state) => 'warning',
                        default => 'gray',
                    })
                    ->sortable(),
                Tables\Columns\TextColumn::make('supplier.name')->visibleFrom('md')->label('Fornitore')->placeholder('—')->sortable(),
                Tables\Columns\TextColumn::make('valid_from')->visibleFrom('md')->label('Valido dal')->date()->sortable()->placeholder('—'),
                Tables\Columns\TextColumn::make('valid_to')->visibleFrom('md')->label('Valido fino al')->date()->sortable()->placeholder('—'),
                Tables\Columns\TextColumn::make('status')
                    ->label('Stato')
                    ->state(fn (PriceList $record) => $record->status())
                    ->badge()
                    ->formatStateUsing(fn (string $state) => static::statusLabels()[$state] ?? $state)
                    ->color(fn (string $state) => static::statusColors()[$state] ?? 'gray'),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('category')
                    ->label('Categoria')
                    ->options(PriceList::CATEGORIE),
                Tables\Filters\SelectFilter::make('supplier_id')
                    ->label('Fornitore')
                    ->relationship('supplier', 'name'),
            ])
            ->actions([
                Tables\Actions\Action::make('open')
                    ->label('Apri PDF')
                    ->icon('heroicon-o-document-text')
                    ->color('primary')
                    ->url(fn (PriceList $record) => $record->file_path ? Storage::disk('public')->url($record->file_path) : null)
                    ->openUrlInNewTab()
                    ->visible(fn (PriceList $record) => filled($record->file_path)),
                Tables\Actions\ActionGroup::make([
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

    public static function isContratto(?string $categoria): bool
    {
        return in_array($categoria, PriceList::CONTRATTI, true);
    }

    public static function statusLabels(): array
    {
        return [
            'in_corso' => 'In corso',
            'in_uso' => 'In uso',
            'sostituito' => 'Sostituito',
            'scaduto' => 'Scaduto',
            'futuro' => 'Futuro',
        ];
    }

    public static function statusColors(): array
    {
        return [
            'in_corso' => 'success',
            'in_uso' => 'success',
            'sostituito' => 'gray',
            'scaduto' => 'danger',
            'futuro' => 'gray',
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ManagePriceLists::route('/'),
        ];
    }
}
