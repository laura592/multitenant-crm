<?php

namespace App\Filament\Resources;

use App\Filament\Resources\AppointmentResource\Pages;
use App\Models\Appointment;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class AppointmentResource extends Resource
{
    protected static ?string $model = Appointment::class;

    protected static ?string $navigationIcon = 'heroicon-o-calendar-days';

    protected static ?string $navigationGroup = 'Interventi tecnici';

    protected static ?string $navigationLabel = 'Appuntamenti';

    protected static ?string $modelLabel = 'Appuntamento';

    protected static ?string $pluralModelLabel = 'Appuntamenti';

    /**
     * Il calendario (pagina "Calendario appuntamenti") copre gia' creazione,
     * modifica e cancellazione in-place: questa risorsa resta raggiungibile
     * (filtri per tecnico/stato, vista tabella) ma non compare nel menu per
     * evitare la voce doppia in "Interventi tecnici".
     */
    public static function shouldRegisterNavigation(): bool
    {
        return false;
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\TextInput::make('title')
                ->label('Titolo')
                ->required()
                ->columnSpanFull(),
            Forms\Components\Select::make('customer_id')
                ->label('Cliente')
                ->relationship('customer', 'company_name', modifyQueryUsing: fn ($query) => $query->orderBy('company_name'))
                ->getOptionLabelFromRecordUsing(fn ($record) => $record->full_name)
                ->searchable(['company_name', 'first_name', 'last_name'])
                ->preload(),
            Forms\Components\Select::make('technician_id')
                ->label('Tecnico')
                ->relationship('technician', 'name')
                ->default(fn () => auth()->id())
                ->searchable()
                ->preload()
                ->required(),
            Forms\Components\Select::make('comodato_macchina_id')
                ->label('Macchina (comodato)')
                ->relationship('comodatoMacchina', 'nome_macchina')
                ->searchable()
                ->preload(),
            Forms\Components\DateTimePicker::make('starts_at')
                ->label('Inizio')
                ->required()
                ->native(false)
                ->default(now()->addDay()->setTime(9, 0)),
            Forms\Components\DateTimePicker::make('ends_at')
                ->label('Fine')
                ->required()
                ->native(false)
                ->after('starts_at')
                ->default(now()->addDay()->setTime(10, 0)),
            Forms\Components\Select::make('status')
                ->label('Stato')
                ->options([
                    Appointment::STATUS_PIANIFICATO => 'Pianificato',
                    Appointment::STATUS_CONFERMATO => 'Confermato',
                    Appointment::STATUS_IN_CORSO => 'In corso',
                    Appointment::STATUS_COMPLETATO => 'Completato',
                    Appointment::STATUS_ANNULLATO => 'Annullato',
                ])
                ->required(),
            Forms\Components\Textarea::make('notes')->label('Note')->columnSpanFull(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('starts_at')
            ->columns([
                Tables\Columns\TextColumn::make('starts_at')->label('Inizio')->dateTime('d/m/Y H:i')->sortable(),
                Tables\Columns\TextColumn::make('title')->label('Titolo')->searchable(),
                Tables\Columns\TextColumn::make('customer.company_name')->label('Cliente')->placeholder('—'),
                Tables\Columns\TextColumn::make('technician.name')->label('Tecnico'),
                Tables\Columns\TextColumn::make('status')
                    ->label('Stato')
                    ->badge()
                    ->formatStateUsing(fn (string $state) => ucfirst(str_replace('_', ' ', $state)))
                    ->color(fn (string $state) => match ($state) {
                        Appointment::STATUS_COMPLETATO => 'success',
                        Appointment::STATUS_ANNULLATO => 'danger',
                        Appointment::STATUS_IN_CORSO => 'warning',
                        default => 'gray',
                    }),
                Tables\Columns\IconColumn::make('google_event_id')
                    ->label('Google')
                    ->boolean()
                    ->getStateUsing(fn (Appointment $record) => filled($record->google_event_id)),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('technician_id')
                    ->label('Tecnico')
                    ->relationship('technician', 'name'),
                Tables\Filters\SelectFilter::make('status')
                    ->label('Stato')
                    ->options([
                        Appointment::STATUS_PIANIFICATO => 'Pianificato',
                        Appointment::STATUS_CONFERMATO => 'Confermato',
                        Appointment::STATUS_IN_CORSO => 'In corso',
                        Appointment::STATUS_COMPLETATO => 'Completato',
                        Appointment::STATUS_ANNULLATO => 'Annullato',
                    ]),
                Tables\Filters\SelectFilter::make('customer_id')
                    ->label('Cliente')
                    ->relationship('customer', 'company_name', modifyQueryUsing: fn ($query) => $query->orderBy('company_name'))
                    ->getOptionLabelFromRecordUsing(fn ($record) => $record->full_name)
                    ->searchable()
                    ->preload(),
                Tables\Filters\Filter::make('upcoming')
                    ->label('Solo futuri')
                    ->query(fn ($query) => $query->where('starts_at', '>=', now()->startOfDay()))
                    ->default(),
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
            'index' => Pages\ListAppointments::route('/'),
            'create' => Pages\CreateAppointment::route('/create'),
            'edit' => Pages\EditAppointment::route('/{record}/edit'),
        ];
    }
}
