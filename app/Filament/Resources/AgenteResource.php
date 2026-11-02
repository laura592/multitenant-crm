<?php

namespace App\Filament\Resources;

use App\Filament\Resources\AgenteResource\Pages;
use App\Models\Agente;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

/**
 * Gli agenti di zona: chi segue il cliente.
 *
 * Elenco corto e gestito a mano, come i metodi di pagamento: non arriva da
 * Eureka (la loro API non espone l'agente, verificato il 02/11/2026) e non
 * ha senso dedurlo da altro.
 */
class AgenteResource extends Resource
{
    protected static ?string $model = Agente::class;

    protected static ?string $navigationIcon = 'heroicon-o-identification';

    protected static ?string $navigationGroup = 'Vendite';

    protected static ?int $navigationSort = 6;

    protected static ?string $navigationLabel = 'Agenti';

    protected static ?string $modelLabel = 'agente';

    protected static ?string $pluralModelLabel = 'agenti';

    protected static bool $hasTitleCaseModelLabel = false;

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make('Agente')
                ->columns(2)
                ->schema([
                    Forms\Components\TextInput::make('nome')
                        ->label('Nome')
                        ->required()
                        ->maxLength(255)
                        ->helperText('Il cognome con cui lo chiamate: "Modolo", "Guirotto".'),
                    Forms\Components\TextInput::make('zona')
                        ->label('Zona')
                        ->maxLength(255)
                        ->placeholder('es. Jesolo')
                        ->helperText('Separata dal nome, così si può cercare per l\'una o per l\'altro.'),
                    Forms\Components\TextInput::make('email')
                        ->label('Email')
                        ->email()
                        ->maxLength(255),
                    Forms\Components\TextInput::make('telefono')
                        ->label('Telefono')
                        ->tel()
                        ->maxLength(255),
                    Forms\Components\Toggle::make('attivo')
                        ->label('In forza')
                        ->default(true)
                        ->inline(false)
                        ->helperText('Un agente che non lavora più si disattiva: sparisce dai menu ma i clienti che seguiva restano attribuiti a lui.'),
                    Forms\Components\Textarea::make('note')
                        ->label('Note')
                        ->rows(2)
                        ->columnSpanFull(),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('nome')
            ->emptyStateHeading('Nessun agente')
            ->emptyStateDescription('Chi segue il cliente. Si assegna dalla scheda cliente, o a gruppi dall\'elenco clienti.')
            ->columns([
                Tables\Columns\TextColumn::make('nome')->label('Nome')->searchable()->sortable(),
                Tables\Columns\TextColumn::make('zona')->label('Zona')->searchable()->placeholder('—'),
                Tables\Columns\TextColumn::make('clienti_count')
                    ->label('Clienti seguiti')
                    ->counts('clienti')
                    ->sortable(),
                Tables\Columns\TextColumn::make('email')->label('Email')->placeholder('—')->toggleable(),
                Tables\Columns\TextColumn::make('telefono')->label('Telefono')->placeholder('—')->toggleable(),
                Tables\Columns\IconColumn::make('attivo')->label('In forza')->boolean(),
            ])
            ->filters([
                Tables\Filters\TernaryFilter::make('attivo')->label('In forza'),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
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
            'index' => Pages\ManageAgenti::route('/'),
        ];
    }
}
