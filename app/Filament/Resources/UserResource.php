<?php

namespace App\Filament\Resources;

use App\Filament\Resources\UserResource\Pages;
use App\Models\Role;
use App\Models\User;
use Filament\Facades\Filament;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Creazione/gestione utenti, riservata al ruolo "admin" e allo staff master
 * (is_super_admin) — vedi App\Support\RolePermissions. Prima di questa
 * risorsa non esisteva nessuna UI per creare utenti (solo tinker/seeder).
 */
class UserResource extends Resource
{
    protected static ?string $model = User::class;

    protected static ?string $navigationIcon = 'heroicon-o-user-group';

    protected static ?string $navigationGroup = 'Amministrazione';

    protected static ?string $navigationLabel = 'Utenti';

    protected static ?string $modelLabel = 'Utente';

    protected static ?string $pluralModelLabel = 'Utenti';

    /**
     * Filament scopa di default questa risorsa per tenant_id = tenant corrente
     * (whereBelongsTo), che esclude i record con tenant_id NULL - esattamente
     * lo staff master is_super_admin (Alex, i super admin generici), che non
     * appartiene a un singolo tenant per scelta (vedi User::getTenants()).
     * Senza questa eccezione lo staff master sparisce dall'elenco Utenti in
     * OGNI tenant, anche il proprio.
     */
    public static function getEloquentQuery(): Builder
    {
        $query = static::getModel()::query();
        $tenant = Filament::getTenant();

        if ($tenant) {
            $query->where(function (Builder $query) use ($tenant) {
                $query->where('tenant_id', $tenant->getKey())
                    ->orWhereNull('tenant_id');
            });
        }

        return $query;
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make('Anagrafica')
                ->columns(2)
                ->schema([
                    Forms\Components\TextInput::make('name')->label('Nome')->required()->maxLength(255),
                    Forms\Components\TextInput::make('email')->label('Email')->email()->required()->maxLength(255)->unique(ignoreRecord: true),
                    Forms\Components\TextInput::make('password')
                        ->label('Password')
                        ->password()
                        ->revealable()
                        ->required(fn (string $context) => $context === 'create')
                        ->dehydrated(fn (?string $state) => filled($state))
                        ->dehydrateStateUsing(fn (string $state) => bcrypt($state))
                        ->maxLength(255)
                        ->helperText('Lascia vuoto per non modificarla.'),
                    Forms\Components\Select::make('role_id')
                        ->label('Ruolo')
                        ->options(function () {
                            $tenantId = Filament::getTenant()?->id;

                            return Role::query()
                                ->where('tenant_id', $tenantId)
                                ->orderBy('name')
                                ->pluck('name', 'id');
                        })
                        ->preload()
                        ->searchable()
                        ->required()
                        ->helperText('Ogni utente ha un solo ruolo applicativo.'),
                    Forms\Components\Hidden::make('tenant_id')
                        ->default(fn () => Filament::getTenant()?->id)
                        ->dehydrated(fn () => ! (bool) auth()->user()?->is_super_admin)
                        ->dehydrateStateUsing(fn () => Filament::getTenant()?->id),
                    Forms\Components\Toggle::make('is_super_admin')
                        ->label('Staff master (accesso a tutti i tenant)')
                        ->visible(fn () => (bool) auth()->user()?->is_super_admin)
                        ->dehydrated(fn () => (bool) auth()->user()?->is_super_admin)
                        ->columnSpanFull(),
                ]),
            // Le tre colonne sotto sono NOT NULL con un default lato DB
            // (8 ore, 40 ore, 26 giorni — vedi migration
            // add_tenant_fields_to_users_table): il default DB pero' scatta
            // solo se la colonna resta assente dall'INSERT, non se arriva
            // esplicitamente NULL. Un campo lasciato vuoto qui manda NULL
            // (Filament svuota le TextInput a null), quindi bypassava il
            // default e faceva fallire la creazione con un 500 - required()
            // + default() qui evita che possano mai arrivare vuoti.
            Forms\Components\Section::make('Contratto')
                ->columns(3)
                ->schema([
                    Forms\Components\TextInput::make('daily_contract_hours')->label('Ore giornaliere')->numeric()->default(8)->required(),
                    Forms\Components\TextInput::make('weekly_contract_hours')->label('Ore settimanali')->numeric()->default(40)->required(),
                    Forms\Components\TextInput::make('annual_leave_days')->label('Giorni ferie annui')->numeric()->default(26)->required(),
                ]),
            Forms\Components\Section::make('Orario standard')
                ->description('Usato per pre-compilare gli orari in Presenze e per il pulsante "Turno standard di oggi". Lascia vuoto se l\'orario di questo dipendente varia troppo per avere un default.')
                ->columns(4)
                ->schema([
                    Forms\Components\TimePicker::make('default_morning_in')->label('Mattina - entrata')->seconds(false),
                    Forms\Components\TimePicker::make('default_morning_out')->label('Mattina - uscita')->seconds(false),
                    Forms\Components\TimePicker::make('default_afternoon_in')->label('Pomeriggio - entrata')->seconds(false),
                    Forms\Components\TimePicker::make('default_afternoon_out')->label('Pomeriggio - uscita')->seconds(false),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('name')
            ->columns([
                Tables\Columns\TextColumn::make('name')->label('Nome')->searchable(),
                Tables\Columns\TextColumn::make('email')->label('Email')->searchable(),
                Tables\Columns\TextColumn::make('roles.name')->label('Ruolo')->badge(),
                Tables\Columns\IconColumn::make('is_super_admin')->label('Staff master')->boolean(),
                Tables\Columns\IconColumn::make('is_active')->label('Attivo')->boolean(),
                Tables\Columns\TextColumn::make('created_at')->label('Creato il')->date(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('roles')
                    ->label('Ruolo')
                    ->relationship('roles', 'name'),
                Tables\Filters\TernaryFilter::make('is_active')
                    ->label('Attivo'),
            ])
            ->actions([
                // Disattivare al posto di eliminare: preserva lo storico (appuntamenti,
                // preventivi, ecc.) collegato all'utente e permette di riattivarlo in seguito.
                // Non permettere di disattivare/cancellare il proprio account dalla lista:
                // si perderebbe l'accesso al pannello senza un altro admin che lo ripristini.
                Tables\Actions\Action::make('toggleActive')
                    ->label(fn (User $record) => $record->is_active ? 'Disattiva' : 'Attiva')
                    ->icon(fn (User $record) => $record->is_active ? 'heroicon-o-lock-closed' : 'heroicon-o-lock-open')
                    ->color(fn (User $record) => $record->is_active ? 'danger' : 'success')
                    ->requiresConfirmation(fn (User $record) => $record->is_active)
                    ->hidden(fn (User $record) => $record->id === auth()->id())
                    ->action(fn (User $record) => $record->update(['is_active' => ! $record->is_active])),
                Tables\Actions\ActionGroup::make([
                    Tables\Actions\EditAction::make(),
                    Tables\Actions\DeleteAction::make()
                        ->hidden(fn (User $record) => $record->id === auth()->id()),
                ]),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\BulkAction::make('deactivate')
                        ->label('Disattiva selezionati')
                        ->icon('heroicon-o-lock-closed')
                        ->color('danger')
                        ->requiresConfirmation()
                        ->action(function (\Illuminate\Database\Eloquent\Collection $records) {
                            $ownAccount = $records->contains('id', auth()->id());
                            $records->reject(fn (User $record) => $record->id === auth()->id())
                                ->each(fn (User $record) => $record->update(['is_active' => false]));

                            if ($ownAccount) {
                                \Filament\Notifications\Notification::make()
                                    ->title('Il tuo account non è stato disattivato')
                                    ->body('Non puoi disattivare l\'utente con cui hai effettuato l\'accesso.')
                                    ->warning()
                                    ->send();
                            }
                        }),
                    Tables\Actions\BulkAction::make('activate')
                        ->label('Attiva selezionati')
                        ->icon('heroicon-o-lock-open')
                        ->color('success')
                        ->action(fn (\Illuminate\Database\Eloquent\Collection $records) => $records->each(fn (User $record) => $record->update(['is_active' => true]))),
                    Tables\Actions\DeleteBulkAction::make()
                        ->action(function (\Illuminate\Database\Eloquent\Collection $records) {
                            $ownAccount = $records->contains('id', auth()->id());
                            $records->reject(fn (User $record) => $record->id === auth()->id())
                                ->each->delete();

                            if ($ownAccount) {
                                \Filament\Notifications\Notification::make()
                                    ->title('Il tuo account non è stato eliminato')
                                    ->body('Non puoi eliminare l\'utente con cui hai effettuato l\'accesso.')
                                    ->warning()
                                    ->send();
                            }
                        }),
                ]),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListUsers::route('/'),
            'create' => Pages\CreateUser::route('/create'),
            'edit' => Pages\EditUser::route('/{record}/edit'),
        ];
    }
}
