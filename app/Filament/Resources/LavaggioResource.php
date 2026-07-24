<?php

namespace App\Filament\Resources;

use App\Filament\Resources\LavaggioResource\Pages;
use App\Models\Lavaggio;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class LavaggioResource extends Resource
{
    protected static ?string $model = Lavaggio::class;

    protected static ?string $navigationIcon = 'heroicon-o-sparkles';

    protected static ?string $navigationGroup = 'Interventi tecnici';

    protected static ?string $navigationLabel = 'Lavaggi';

    protected static ?string $modelLabel = 'Lavaggio';

    protected static ?string $pluralModelLabel = 'Lavaggi';

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make('Cliente e macchina')
                ->columns(2)
                ->schema([
                    Forms\Components\Select::make('customer_id')
                        ->label('Cliente')
                        ->relationship('customer', 'company_name', modifyQueryUsing: fn ($query) => $query->orderBy('company_name'))
                        ->getOptionLabelFromRecordUsing(fn ($record) => $record->full_name)
                        ->searchable(['company_name', 'first_name', 'last_name'])
                        ->preload()
                        ->required(),
                    Forms\Components\Select::make('machine_unit_id')
                        ->label('Macchina')
                        ->relationship('machineUnit', 'serial_number')
                        ->getOptionLabelFromRecordUsing(fn ($record) => $record->display_name.' — '.$record->serial_number)
                        ->searchable()
                        ->preload(),
                ]),
            Forms\Components\Section::make('Lavaggio')
                ->columns(2)
                ->schema([
                    Forms\Components\DatePicker::make('data')->label('Data')->required()->default(now()),
                    Forms\Components\TextInput::make('descrizione')
                        ->label('Descrizione')
                        ->helperText('Es. "5 vie + apertura", "chiusura stagionale".')
                        ->required()
                        ->maxLength(255),
                    Forms\Components\Textarea::make('note')->label('Note')->columnSpanFull(),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('data', 'desc')
            ->columns([
                Tables\Columns\TextColumn::make('data')->label('Data')->date()->sortable(),
                Tables\Columns\TextColumn::make('customer.company_name')->label('Cliente')->searchable()->sortable(),
                Tables\Columns\TextColumn::make('machineUnit.serial_number')->label('Macchina')->placeholder('—'),
                Tables\Columns\TextColumn::make('descrizione')->label('Descrizione')->searchable(),
                Tables\Columns\TextColumn::make('customer.lavaggio_next_due_date')
                    ->label('Prossima scadenza')
                    ->date()
                    ->placeholder('—')
                    ->sortable()
                    ->color(fn (Lavaggio $record) => match (true) {
                        $record->customer?->lavaggio_next_due_date === null => 'gray',
                        $record->customer->lavaggio_next_due_date->isPast() => 'danger',
                        $record->customer->lavaggio_next_due_date->diffInDays(now()) <= 5 => 'warning',
                        default => 'success',
                    }),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('customer_id')
                    ->label('Cliente')
                    ->relationship('customer', 'company_name', modifyQueryUsing: fn ($query) => $query->orderBy('company_name'))
                    ->getOptionLabelFromRecordUsing(fn ($record) => $record->full_name)
                    ->searchable()
                    ->preload(),
                Tables\Filters\Filter::make('in_scadenza')
                    ->label('In scadenza (7 giorni) o scaduti')
                    ->query(fn ($query) => $query->whereHas('customer', fn ($q) => $q
                        ->whereNotNull('lavaggio_next_due_date')
                        ->where('lavaggio_next_due_date', '<=', now()->addDays(7)))),
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

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListLavaggi::route('/'),
            'create' => Pages\CreateLavaggio::route('/create'),
            'edit' => Pages\EditLavaggio::route('/{record}/edit'),
        ];
    }
}
