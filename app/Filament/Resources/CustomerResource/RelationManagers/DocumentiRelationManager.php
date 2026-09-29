<?php

namespace App\Filament\Resources\CustomerResource\RelationManagers;

use App\Models\CustomerDocument;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Storage;

/**
 * I documenti consegnati dal cliente, sulla sua scheda (29/09/2026).
 *
 * Prima arrivavano per mail e restavano nella mail: chi cercava la visura
 * doveva scavare in Outlook, e una dichiarazione di esenzione IVA che
 * giustifica come si fattura non stava da nessuna parte nel CRM.
 *
 * Disco `local` (root storage/app/private): sono documenti fiscali e d'identita', e su `public`
 * avrebbero un URL indovinabile. Si aprono passando dal pannello.
 */
class DocumentiRelationManager extends RelationManager
{
    protected static string $relationship = 'documenti';

    protected static ?string $title = 'Documenti';

    protected static ?string $modelLabel = 'documento';

    protected static ?string $pluralModelLabel = 'documenti';

    protected static ?string $icon = 'heroicon-o-paper-clip';

    public function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Select::make('tipo')
                ->label('Che documento e\'')
                ->options(CustomerDocument::TIPI)
                ->default('altro')
                ->required()
                ->live()
                // Il titolo si scrive da solo con il nome del tipo: nove
                // volte su dieci e' quello, e chi carica cinque allegati non
                // vuole battere cinque titoli.
                ->afterStateUpdated(fn (Forms\Set $set, ?string $state) => $set(
                    'titolo',
                    CustomerDocument::TIPI[$state] ?? null,
                )),
            Forms\Components\TextInput::make('titolo')
                ->label('Titolo')
                ->required()
                ->maxLength(255),
            Forms\Components\FileUpload::make('path')
                ->label('File')
                ->disk('local')
                ->directory(fn () => 'documenti-clienti/'.$this->getOwnerRecord()->getKey())
                ->acceptedFileTypes(['application/pdf', 'image/jpeg', 'image/png', 'image/heic'])
                ->maxSize(20480)
                ->required()
                ->helperText('PDF o foto, fino a 20 MB.')
                ->columnSpanFull(),
            Forms\Components\DatePicker::make('scade_il')
                ->label('Scade il')
                ->native(false)
                ->displayFormat('d/m/Y')
                ->helperText('Solo se scade davvero: una visura invecchia, un\'esenzione a volte e\' annuale.'),
            Forms\Components\Textarea::make('note')
                ->label('Note')
                ->rows(2)
                ->columnSpanFull(),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('titolo')
            ->defaultSort('created_at', 'desc')
            ->emptyStateHeading('Nessun documento')
            ->emptyStateDescription('Visura, esenzione IVA, mandato, documento d\'identita\': gli allegati che il modulo di anagrafica chiede di restituire.')
            ->columns([
                Tables\Columns\TextColumn::make('titolo')
                    ->label('Documento')
                    ->description(fn (CustomerDocument $r) => $r->tipoLeggibile())
                    ->wrap()
                    ->searchable(),
                Tables\Columns\TextColumn::make('scade_il')
                    ->label('Scade')
                    ->date('d/m/Y')
                    ->placeholder('non scade')
                    // Un documento scaduto non e' un dettaglio estetico: una
                    // visura vecchia non vale, e l'esenzione scaduta fa
                    // sbagliare la fattura.
                    ->color(fn (CustomerDocument $r) => $r->scaduto() ? 'danger' : null)
                    ->badge(fn (CustomerDocument $r) => $r->scaduto()),
                Tables\Columns\TextColumn::make('dimensione')
                    ->label('Peso')
                    ->formatStateUsing(fn (CustomerDocument $r) => $r->dimensioneLeggibile())
                    ->toggleable(),
                Tables\Columns\TextColumn::make('caricatoDa.name')
                    ->label('Caricato da')
                    ->placeholder('—')
                    ->toggleable(),
                Tables\Columns\TextColumn::make('created_at')
                    ->label('Quando')
                    ->dateTime('d/m/Y H:i')
                    ->sortable(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('tipo')
                    ->label('Tipo')
                    ->options(CustomerDocument::TIPI),
            ])
            ->headerActions([
                Tables\Actions\CreateAction::make()
                    ->label('Carica documento')
                    ->mutateFormDataUsing(function (array $data): array {
                        $data['caricato_da'] = auth()->id();

                        // Peso e tipo si leggono dal file appena salvato: il
                        // form non li porta, e senza l'elenco non sa dire se
                        // sta mostrando un PDF da 200 KB o una foto da 12 MB.
                        if (Storage::disk('local')->exists($data['path'] ?? '')) {
                            $data['dimensione'] = Storage::disk('local')->size($data['path']);
                            $data['mime'] = Storage::disk('local')->mimeType($data['path']) ?: null;
                        }

                        return $data;
                    }),
            ])
            ->actions([
                Tables\Actions\Action::make('scarica')
                    ->label('Apri')
                    ->icon('heroicon-o-arrow-down-tray')
                    ->color('gray')
                    // Passa dal pannello, che ha gia' controllato chi sei:
                    // sul disco locale non c'e' un URL pubblico.
                    ->action(fn (CustomerDocument $r) => Storage::disk('local')->download($r->path, $r->titolo.'.'.pathinfo($r->path, PATHINFO_EXTENSION))),
                Tables\Actions\EditAction::make()->label('Modifica'),
                Tables\Actions\DeleteAction::make()->label('Elimina'),
            ])
            ->bulkActions([
                Tables\Actions\DeleteBulkAction::make(),
            ]);
    }
}
