<?php

namespace App\Filament\Resources;

use App\Filament\Concerns\ScopesToOwnUserUnlessResponsabile;
use App\Filament\Resources\LeaveRequestResource\Pages;
use App\Models\LeaveRequest;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class LeaveRequestResource extends Resource
{
    use ScopesToOwnUserUnlessResponsabile;

    protected static ?string $model = LeaveRequest::class;

    protected static ?string $navigationIcon = 'heroicon-o-calendar';

    protected static ?string $navigationGroup = 'Personale';

    protected static ?string $navigationLabel = 'Ferie e permessi';

    protected static ?string $modelLabel = 'Richiesta ferie/permesso';

    protected static ?string $pluralModelLabel = 'Ferie e permessi';

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make('Richiesta')
                ->columns(3)
                ->schema([
                    Forms\Components\Select::make('user_id')
                        ->label('Dipendente')
                        ->relationship('user', 'name')
                        ->default(fn () => auth()->id())
                        ->disabled(fn () => ! static::isResponsabile(auth()->user()))
                        ->dehydrated()
                        ->live()
                        ->required()
                        ->helperText(function (Forms\Get $get) {
                            $userId = $get('user_id') ?? auth()->id();
                            $user = $userId ? \App\Models\User::find($userId) : null;
                            $remaining = $user?->remainingFerieDays();

                            return $remaining === null ? null : "Residuo ferie anno corrente: {$remaining} giorni";
                        }),
                    Forms\Components\Select::make('type')
                        ->label('Tipo')
                        ->options(['ferie' => 'Ferie', 'permesso' => 'Permesso', 'malattia' => 'Malattia'])
                        ->live()
                        ->required(),
                    // Solo il permesso orario ha bisogno delle ore: per ferie/malattia
                    // il campo restava visibile ma inutile, con rischio di lasciarlo
                    // valorizzato per errore da una richiesta precedente.
                    Forms\Components\TextInput::make('hours')
                        ->label('Ore')
                        ->numeric()
                        ->minValue(0)
                        ->visible(fn (Forms\Get $get) => $get('type') === 'permesso')
                        ->required(fn (Forms\Get $get) => $get('type') === 'permesso'),
                    Forms\Components\DatePicker::make('date_from')->label('Dal')->required(),
                    Forms\Components\DatePicker::make('date_to')->label('Al')->required()->afterOrEqual('date_from'),
                    Forms\Components\Textarea::make('notes')->label('Note')->columnSpanFull(),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('date_from', 'desc')
            ->columns([
                Tables\Columns\TextColumn::make('user.name')->label('Dipendente')->searchable()->sortable(),
                Tables\Columns\TextColumn::make('type')
                    ->label('Tipo')
                    ->badge()
                    ->formatStateUsing(fn (string $state) => match ($state) {
                        'ferie' => 'Ferie',
                        'permesso' => 'Permesso',
                        'malattia' => 'Malattia',
                        default => $state,
                    })
                    ->color(fn (string $state) => match ($state) {
                        'ferie' => 'info',
                        'permesso' => 'warning',
                        'malattia' => 'danger',
                        default => 'gray',
                    }),
                Tables\Columns\TextColumn::make('date_from')->label('Dal')->date()->sortable(),
                Tables\Columns\TextColumn::make('date_to')->label('Al')->date()->sortable(),
                Tables\Columns\TextColumn::make('days')->label('Giorni')->state(fn (LeaveRequest $record) => $record->days),
                Tables\Columns\TextColumn::make('status')
                    ->label('Stato')
                    ->badge()
                    ->color(fn (string $state) => match ($state) {
                        'approvato' => 'success',
                        'rifiutato' => 'danger',
                        default => 'warning',
                    }),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->label('Stato')
                    ->options(['richiesto' => 'Richiesto', 'approvato' => 'Approvato', 'rifiutato' => 'Rifiutato']),
                Tables\Filters\SelectFilter::make('type')
                    ->label('Tipo')
                    ->options(['ferie' => 'Ferie', 'permesso' => 'Permesso', 'malattia' => 'Malattia']),
            ])
            ->actions([
                Tables\Actions\Action::make('approve')
                    ->label('Approva')
                    ->icon('heroicon-o-check')
                    ->color('success')
                    ->visible(fn (LeaveRequest $record) => $record->status === 'richiesto' && static::isResponsabile(auth()->user()))
                    ->requiresConfirmation()
                    ->action(function (LeaveRequest $record) {
                        $record->approve(auth()->user());
                        Notification::make()->title('Richiesta approvata')->success()->send();
                    }),
                Tables\Actions\Action::make('reject')
                    ->label('Rifiuta')
                    ->icon('heroicon-o-x-mark')
                    ->color('danger')
                    ->visible(fn (LeaveRequest $record) => $record->status === 'richiesto' && static::isResponsabile(auth()->user()))
                    ->requiresConfirmation()
                    ->action(function (LeaveRequest $record) {
                        $record->reject(auth()->user());
                        Notification::make()->title('Richiesta rifiutata')->danger()->send();
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

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListLeaveRequests::route('/'),
            'create' => Pages\CreateLeaveRequest::route('/create'),
            'edit' => Pages\EditLeaveRequest::route('/{record}/edit'),
        ];
    }
}
