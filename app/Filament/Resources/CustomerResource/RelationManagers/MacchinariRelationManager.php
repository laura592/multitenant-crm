<?php

namespace App\Filament\Resources\CustomerResource\RelationManagers;

use App\Filament\Resources\MachineUnitResource;
use App\Models\Customer;
use App\Models\MachineUnit;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

/**
 * Vista di sola-lettura sui macchinari installati presso questo cliente, con
 * a chi fatturare ciascuno — non un "Fatturare a" unico sparso in piu' punti
 * della scheda cliente (vecchio comportamento confuso, vedi
 * Customer::billing_customer_id), ma un elenco per macchina: la fatturazione
 * e' legata al singolo impianto (es. impianti birra/vino in comodato a un
 * fornitore, macchina caffe' del bar stesso), non al cliente nel suo insieme.
 * La registrazione/modifica vera e propria resta in MachineUnitResource.
 */
class MacchinariRelationManager extends RelationManager
{
    protected static string $relationship = 'installedMachineUnits';

    protected static ?string $title = 'Macchinari installati';

    protected static ?string $modelLabel = 'Macchinario';

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return $ownerRecord->installedMachineUnits()->exists();
    }

    public function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Select::make('billing_customer_id')
                ->label('Fatturare a')
                ->relationship('billingCustomer', 'company_name')
                ->getOptionLabelFromRecordUsing(fn (Customer $record) => $record->full_name)
                ->searchable(['company_name', 'first_name', 'last_name'])
                ->preload()
                ->helperText('Lascia vuoto se paga il cliente presso cui è installata questa macchina.'),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('serial_number')
            ->columns([
                Tables\Columns\TextColumn::make('serial_number')->label('Matricola')->searchable(),
                Tables\Columns\TextColumn::make('display_name')->label('Modello'),
                Tables\Columns\TextColumn::make('status')
                    ->label('Stato')
                    ->badge()
                    ->formatStateUsing(fn (string $state) => match ($state) {
                        MachineUnit::STATUS_IN_MAGAZZINO => 'In magazzino',
                        MachineUnit::STATUS_INSTALLATA => 'Installata',
                        MachineUnit::STATUS_RIMOSSA => 'Rimossa',
                        default => $state,
                    }),
                Tables\Columns\TextColumn::make('billingCustomer.full_name')
                    ->label('Fatturare a')
                    ->placeholder('Se stesso')
                    ->wrap(),
            ])
            ->actions([
                Tables\Actions\EditAction::make()->label('Cambia fatturazione'),
                Tables\Actions\Action::make('apri')
                    ->label('Apri scheda macchina')
                    ->icon('heroicon-o-arrow-top-right-on-square')
                    ->url(fn (MachineUnit $record) => MachineUnitResource::getUrl('edit', ['record' => $record]))
                    ->openUrlInNewTab(),
            ])
            ->emptyStateHeading('Nessun macchinario installato presso questo cliente')
            ->emptyStateDescription('I macchinari si registrano da "Macchinari" nel menu, oppure vengono importati automaticamente dal sync Eureka.');
    }
}
