<?php

namespace App\Filament\Resources\MaintenanceScheduleResource\RelationManagers;

use App\Models\MaintenanceSchedule;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

class LavaggiRelationManager extends RelationManager
{
    protected static string $relationship = 'lavaggi';

    protected static ?string $title = 'Lavaggi eseguiti';

    protected static ?string $modelLabel = 'Lavaggio';

    // Ha senso solo per i piani di tipo lavaggio: per le manutenzioni gli
    // interventi si registrano tramite ServiceReport, non qui.
    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return $ownerRecord->type === MaintenanceSchedule::TYPE_LAVAGGIO;
    }

    public function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Select::make('machine_unit_id')
                ->label('Macchina')
                ->relationship('machineUnit', 'serial_number')
                ->getOptionLabelFromRecordUsing(fn ($record) => $record->display_name.' — '.$record->serial_number)
                ->searchable()
                ->preload(),
            Forms\Components\DatePicker::make('data')->label('Data')->required()->default(now()),
            Forms\Components\TextInput::make('descrizione')
                ->label('Descrizione')
                ->helperText('Es. "5 vie + apertura", "chiusura stagionale".')
                ->required()
                ->maxLength(255),
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
                Tables\Columns\TextColumn::make('machineUnit.serial_number')->label('Macchina')->placeholder('—'),
                Tables\Columns\TextColumn::make('descrizione')->label('Descrizione')->searchable(),
                Tables\Columns\TextColumn::make('note')->label('Note')->limit(50)->placeholder('—'),
            ])
            ->headerActions([
                Tables\Actions\CreateAction::make()
                    ->mutateFormDataUsing(function (array $data): array {
                        $data['customer_id'] = $this->getOwnerRecord()->customer_id;

                        return $data;
                    }),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make(),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ]);
    }
}
