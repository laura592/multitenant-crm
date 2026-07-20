<?php

namespace App\Filament\Resources;

use App\Filament\Resources\MachineUnitResource\Pages;
use App\Filament\Resources\MachineUnitResource\RelationManagers\PlacementsRelationManager;
use App\Models\Customer;
use App\Models\MachineUnit;
use App\Models\Product;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

/**
 * Registro dei macchinari fisici: matricola, proprieta' (puo' non coincidere
 * col tenant, es. una macchina di proprieta' "Dersut" installata presso un
 * cliente Alex) e ubicazione attuale. Lo storico degli spostamenti si vede
 * nella relation manager "Storico posizionamenti"; lo spostamento vero e
 * proprio si fa con l'azione "Sposta" (non modificando a mano il cliente
 * attuale, per non perdere lo storico).
 */
class MachineUnitResource extends Resource
{
    protected static ?string $model = MachineUnit::class;

    protected static ?string $navigationIcon = 'heroicon-o-cpu-chip';

    protected static ?string $navigationGroup = 'Interventi tecnici';

    protected static ?string $navigationLabel = 'Macchinari';

    protected static ?string $modelLabel = 'Macchinario';

    protected static ?string $pluralModelLabel = 'Macchinari';

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make('Identificazione')
                ->columns(2)
                ->schema([
                    Forms\Components\TextInput::make('serial_number')->label('Matricola')->required()->maxLength(255),
                    Forms\Components\Select::make('product_id')
                        ->label('Modello (da catalogo)')
                        ->relationship('product', 'name', modifyQueryUsing: fn ($query) => $query->where('type', Product::TYPE_MACHINE))
                        ->searchable()
                        ->preload(),
                    Forms\Components\TextInput::make('model_name')
                        ->label('Modello (testo libero)')
                        ->helperText('Solo se non e\' a catalogo (es. macchina non a listino Alex).')
                        ->maxLength(255),
                    Forms\Components\TextInput::make('owner_name')
                        ->label('Proprietà')
                        ->helperText('Es. "Dersut": chi possiede legalmente la macchina, anche se diverso dal cliente presso cui si trova.')
                        ->required()
                        ->maxLength(255),
                    Forms\Components\Select::make('status')
                        ->label('Stato')
                        ->options([
                            MachineUnit::STATUS_IN_MAGAZZINO => 'In magazzino',
                            MachineUnit::STATUS_INSTALLATA => 'Installata',
                            MachineUnit::STATUS_RIMOSSA => 'Rimossa',
                        ])
                        ->disabled()
                        ->dehydrated(false)
                        ->helperText('Cambia automaticamente con l\'azione "Sposta".'),
                    Forms\Components\Textarea::make('notes')->label('Note')->columnSpanFull(),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('serial_number')->label('Matricola')->searchable(),
                Tables\Columns\TextColumn::make('display_name')->label('Modello'),
                Tables\Columns\TextColumn::make('owner_name')->label('Proprietà')->searchable(),
                Tables\Columns\TextColumn::make('currentCustomer.company_name')->label('Presso')->placeholder('In magazzino'),
                Tables\Columns\TextColumn::make('status')
                    ->label('Stato')
                    ->badge()
                    ->formatStateUsing(fn (string $state) => match ($state) {
                        MachineUnit::STATUS_INSTALLATA => 'Installata',
                        MachineUnit::STATUS_RIMOSSA => 'Rimossa',
                        default => 'In magazzino',
                    })
                    ->color(fn (string $state) => match ($state) {
                        MachineUnit::STATUS_INSTALLATA => 'success',
                        MachineUnit::STATUS_RIMOSSA => 'danger',
                        default => 'gray',
                    }),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->label('Stato')
                    ->options([
                        MachineUnit::STATUS_IN_MAGAZZINO => 'In magazzino',
                        MachineUnit::STATUS_INSTALLATA => 'Installata',
                        MachineUnit::STATUS_RIMOSSA => 'Rimossa',
                    ]),
            ])
            ->actions([
                Tables\Actions\Action::make('sposta')
                    ->label('Sposta')
                    ->icon('heroicon-o-arrow-right-circle')
                    ->color('gray')
                    ->form([
                        Forms\Components\Select::make('customer_id')
                            ->label('Nuovo cliente')
                            ->helperText('Lascia vuoto per riportare la macchina in magazzino/rimuoverla.')
                            ->options(fn () => Customer::query()->orderBy('company_name')->get()->mapWithKeys(
                                fn (Customer $customer) => [$customer->id => $customer->full_name ?: 'Cliente senza nome']
                            ))
                            ->searchable(),
                        Forms\Components\Textarea::make('notes')->label('Note sullo spostamento'),
                    ])
                    ->action(function (MachineUnit $record, array $data) {
                        $customer = $data['customer_id'] ? Customer::find($data['customer_id']) : null;
                        $record->moveTo($customer, $data['notes'] ?? null);

                        Notification::make()
                            ->title($customer ? "Macchina spostata presso {$customer->company_name}" : 'Macchina rientrata in magazzino')
                            ->success()
                            ->send();
                    }),
                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make(),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ]);
    }

    public static function getRelations(): array
    {
        return [
            PlacementsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListMachineUnits::route('/'),
            'create' => Pages\CreateMachineUnit::route('/create'),
            'edit' => Pages\EditMachineUnit::route('/{record}/edit'),
        ];
    }
}
