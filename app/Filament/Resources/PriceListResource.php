<?php

namespace App\Filament\Resources;

use App\Filament\Resources\PriceListResource\Pages;
use App\Models\PriceList;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Resources\Resource;
use Illuminate\Support\Facades\Storage;

class PriceListResource extends Resource
{
    protected static ?string $model = PriceList::class;

    // Catalogo condiviso (tenant_id nullable, come Supplier/Material): lo
    // scoping vero lo fa gia' BelongsToTenant sul modello.
    protected static bool $isScopedToTenant = false;

    protected static ?string $navigationIcon = 'heroicon-o-currency-euro';

    protected static ?string $navigationGroup = 'Magazzino';

    protected static ?string $navigationLabel = 'Listini';

    protected static ?string $modelLabel = 'Listino';

    protected static ?string $pluralModelLabel = 'Listini';

    protected static ?string $recordTitleAttribute = 'name';

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make('Listino')
                ->columns(2)
                ->schema([
                    Forms\Components\TextInput::make('name')
                        ->label('Nome')
                        ->required()
                        ->maxLength(255)
                        ->columnSpanFull(),
                    Forms\Components\Select::make('supplier_id')
                        ->label('Fornitore')
                        ->relationship('supplier', 'name')
                        ->searchable()
                        ->preload(),
                    Forms\Components\FileUpload::make('file_path')
                        ->label('File PDF')
                        ->directory('price-lists')
                        ->acceptedFileTypes(['application/pdf'])
                        ->maxSize(20480)
                        ->openable()
                        ->downloadable()
                        ->deletable(false)
                        ->helperText('Per sostituire il PDF, caricane uno nuovo: non è possibile rimuoverlo senza sostituirlo.'),
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
                Tables\Columns\TextColumn::make('name')->label('Nome')->searchable()->sortable(),
                Tables\Columns\TextColumn::make('supplier.name')->label('Fornitore')->placeholder('—')->sortable(),
                Tables\Columns\TextColumn::make('valid_from')->label('Valido dal')->date()->sortable()->placeholder('—'),
                Tables\Columns\TextColumn::make('valid_to')->label('Valido fino al')->date()->sortable()->placeholder('—'),
                Tables\Columns\TextColumn::make('status')
                    ->label('Stato')
                    ->state(fn (PriceList $record) => $record->status())
                    ->badge()
                    ->formatStateUsing(fn (string $state) => match ($state) {
                        'in_corso' => 'In corso',
                        'scaduto' => 'Scaduto',
                        'futuro' => 'Futuro',
                        default => $state,
                    })
                    ->color(fn (string $state) => match ($state) {
                        'in_corso' => 'success',
                        'scaduto' => 'danger',
                        'futuro' => 'gray',
                        default => 'gray',
                    }),
            ])
            ->filters([
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
                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make(),
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
            'index' => Pages\ManagePriceLists::route('/'),
        ];
    }
}
