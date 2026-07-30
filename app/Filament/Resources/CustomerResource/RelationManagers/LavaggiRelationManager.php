<?php

namespace App\Filament\Resources\CustomerResource\RelationManagers;

use App\Models\Lavaggio;
use App\Models\MachineUnit;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;

class LavaggiRelationManager extends RelationManager
{
    protected static string $relationship = 'lavaggi';

    protected static ?string $title = 'Storico lavaggi';

    protected static ?string $modelLabel = 'Lavaggio';

    /**
     * Senza machine_unit_id il lavaggio non e' "di macchina sconosciuta": per
     * come lavora il tecnico, una visita senza macchina specificata ha
     * lavato TUTTI gli impianti del cliente in un colpo solo.
     */
    private static function macchinaLabel(Lavaggio $record): string
    {
        if ($record->machine_unit_id) {
            return $record->machineUnit->serial_number;
        }

        $units = MachineUnit::where('current_customer_id', $record->customer_id)->pluck('model_name');

        return $units->count() > 1 ? 'Tutti ('.$units->implode(', ').')' : ($units->first() ?? '—');
    }

    /**
     * Se il cliente ha impianti con pagante diverso (es. Gigi Marchetto) e la
     * visita non specifica la macchina, invoiceRecipient() da solo darebbe un
     * pagante di default fuorviante: qui serve il dettaglio "misto".
     */
    private static function fatturareALabel(Lavaggio $record): string
    {
        if (! $record->machine_unit_id) {
            $units = MachineUnit::where('current_customer_id', $record->customer_id)->get();
            $targets = $units->map(fn (MachineUnit $u) => $u->billingCustomer?->full_name ?? 'se stesso');

            if ($targets->unique()->count() > 1) {
                return 'Misto: '.$units->map(fn (MachineUnit $u) => $u->model_name.'='.($u->billingCustomer?->full_name ?? 'se stesso'))->implode(', ');
            }
        }

        return $record->invoiceRecipient()->full_name;
    }

    public function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Select::make('machine_unit_id')
                ->label('Macchina')
                ->relationship('machineUnit', 'serial_number', fn ($query) => $query
                    ->where('current_customer_id', $this->getOwnerRecord()->id))
                ->getOptionLabelFromRecordUsing(fn ($record) => $record->display_name.' — '.$record->serial_number)
                ->searchable()
                ->preload()
                ->helperText('Lascia vuoto se la visita ha lavato tutti gli impianti (il caso normale). Seleziona la macchina solo se questa volta ne e\' stato lavato uno solo.'),
            Forms\Components\DatePicker::make('data')->label('Data')->required()->default(now()),
            Forms\Components\TextInput::make('descrizione')
                ->label('Descrizione')
                ->helperText('Es. "5 vie + apertura", "chiusura stagionale".')
                ->required()
                ->maxLength(255)
                ->default('Lavaggio impianto'),
            Forms\Components\Textarea::make('note')->label('Note')->columnSpanFull(),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('descrizione')
            ->defaultSort('data', 'desc')
            ->columns([
                Tables\Columns\TextColumn::make('data')->label('Data')->date()->sortable(),
                Tables\Columns\TextColumn::make('macchina')
                    ->label('Macchina')
                    ->state(fn (Lavaggio $record) => static::macchinaLabel($record))
                    ->wrap(),
                Tables\Columns\TextColumn::make('descrizione')->label('Descrizione')->searchable(),
                Tables\Columns\TextColumn::make('note')->label('Note')->limit(50)->placeholder('—'),
                Tables\Columns\TextColumn::make('fatturare_a')
                    ->label('Fatturare a')
                    ->state(fn (Lavaggio $record) => static::fatturareALabel($record))
                    ->wrap(),
            ])
            ->headerActions([
                Tables\Actions\CreateAction::make(),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make(),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ])
            ->emptyStateHeading('Nessun lavaggio registrato')
            ->emptyStateDescription('Aggiungi il primo lavaggio con "Nuovo".');
    }
}
