<?php

namespace App\Filament\Resources;

use App\Filament\Forms\ItalianAddressFields;
use App\Filament\Resources\TenantResource\Pages;
use App\Filament\Resources\TenantResource\RelationManagers\DeadlinesRelationManager;
use App\Models\Tenant;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Support\Str;

class TenantResource extends Resource
{
    protected static ?string $model = Tenant::class;

    // Un tenant non "appartiene" a se stesso: niente scoping automatico
    // Filament, l'accesso e' gia' ristretto via canViewAny() a is_super_admin.
    protected static bool $isScopedToTenant = false;

    protected static ?string $navigationIcon = 'heroicon-o-building-office-2';

    protected static ?string $navigationGroup = 'Amministrazione';

    protected static ?string $navigationLabel = 'Aziende partner';

    protected static ?string $modelLabel = 'Azienda partner';

    protected static ?string $pluralModelLabel = 'Aziende partner';

    /**
     * Solo lo staff Alex gestisce i tenant (docs/architecture.md §5.4).
     */
    public static function canViewAny(): bool
    {
        return (bool) auth()->user()?->is_super_admin;
    }

    public static function shouldRegisterNavigation(): bool
    {
        return static::canViewAny();
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make('Anagrafica')
                ->columns(2)
                ->schema([
                    Forms\Components\TextInput::make('name')
                        ->label('Nome')
                        ->required()
                        ->maxLength(255)
                        ->live(onBlur: true)
                        ->afterStateUpdated(fn (string $state, Forms\Set $set) => $set('slug', Str::slug($state))),
                    Forms\Components\TextInput::make('slug')
                        ->label('Slug (URL pannello)')
                        ->required()
                        ->unique(ignoreRecord: true)
                        ->maxLength(255),
                    Forms\Components\TextInput::make('legal_name')->label('Ragione sociale')->maxLength(255),
                    Forms\Components\TextInput::make('vat_number')->label('P.IVA')->maxLength(255),
                    Forms\Components\TextInput::make('tax_code')->label('Codice fiscale')->maxLength(255),
                    Forms\Components\TextInput::make('sdi')->label('Codice SDI')->maxLength(255),
                    Forms\Components\TextInput::make('email')->label('Email')->email()->maxLength(255),
                    Forms\Components\TextInput::make('phone')->label('Telefono')->tel()->maxLength(255),
                    Forms\Components\TextInput::make('fax')->label('Fax')->tel()->maxLength(255),
                    Forms\Components\Toggle::make('is_master')->label('Tenant master (Alex)'),
                    Forms\Components\Toggle::make('is_active')->label('Attivo')->default(true),
                ]),
            Forms\Components\Section::make('Indirizzo')
                ->columns(3)
                ->schema(ItalianAddressFields::schema()),
            Forms\Components\Section::make('Branding')
                ->columns(2)
                ->schema([
                    Forms\Components\FileUpload::make('logo_path')->label('Logo')->image()->directory('tenant-logos')->maxSize(5120),
                    Forms\Components\ColorPicker::make('primary_color')->label('Colore primario'),
                ]),
            Forms\Components\Section::make('Condizioni contrattuali')
                ->description('Vedi contratto di distribuzione tipo, artt. 3, 4, 11, 12, 13')
                ->columns(2)
                ->schema([
                    Forms\Components\TextInput::make('machine_discount_percent')
                        ->label('Sconto macchine (%)')
                        ->numeric()
                        ->default(30)
                        ->suffix('%'),
                    Forms\Components\Select::make('default_commission_scenario')
                        ->label('Scenario provvigione predefinito')
                        ->options([
                            'A' => 'A - Segnalazione cliente',
                            'B' => 'B - Partner procura cliente, installazione Alex',
                            'C' => 'C - Partner installa in autonomia',
                        ]),
                    Forms\Components\TextInput::make('scenario_a_commission_percent')
                        ->label('Provvigione Scenario A (%)')
                        ->numeric()
                        ->default(10)
                        ->suffix('%'),
                    Forms\Components\TextInput::make('scenario_b_installation_fee')
                        ->label('Compenso installazione Scenario B (€)')
                        ->numeric()
                        ->default(1500)
                        ->prefix('€'),
                    Forms\Components\TextInput::make('scenario_c_preinstallation_fee')
                        ->label('Compenso preinstallazione Scenario C (€)')
                        ->numeric()
                        ->default(500)
                        ->prefix('€'),
                    Forms\Components\Toggle::make('exclusive_supply_required')
                        ->label('Obbligo approvvigionamento esclusivo')
                        ->default(true),
                    Forms\Components\Toggle::make('territory_exclusive')->label('Esclusiva territoriale'),
                    Forms\Components\Textarea::make('territory_notes')->label('Note territorio')->columnSpanFull(),
                    Forms\Components\DatePicker::make('contract_start_date')->label('Inizio contratto'),
                    Forms\Components\TextInput::make('contract_duration_months')
                        ->label('Durata (mesi)')
                        ->numeric()
                        ->default(36),
                    Forms\Components\TextInput::make('notice_period_days')
                        ->label('Preavviso disdetta (giorni)')
                        ->numeric()
                        ->default(90),
                ]),
            Forms\Components\Section::make('Canone piattaforma (opzionale)')
                ->columns(3)
                ->schema([
                    Forms\Components\Toggle::make('saas_billing_enabled')
                        ->label('Canone attivo')
                        ->live(),
                    Forms\Components\TextInput::make('saas_plan_fee')
                        ->label('Importo canone (€)')
                        ->numeric()
                        ->prefix('€')
                        ->visible(fn (Forms\Get $get) => $get('saas_billing_enabled')),
                    Forms\Components\Select::make('saas_billing_cycle')
                        ->label('Periodicità')
                        ->options(['monthly' => 'Mensile', 'annual' => 'Annuale'])
                        ->visible(fn (Forms\Get $get) => $get('saas_billing_enabled')),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('name')->label('Nome')->searchable(),
                Tables\Columns\IconColumn::make('is_master')->label('Master')->boolean(),
                Tables\Columns\IconColumn::make('is_active')->label('Attivo')->boolean(),
                Tables\Columns\TextColumn::make('default_commission_scenario')->label('Scenario')->badge(),
                Tables\Columns\TextColumn::make('machine_discount_percent')->label('Sconto')->suffix('%'),
                Tables\Columns\TextColumn::make('contract_start_date')->label('Inizio contratto')->date(),
            ])
            ->filters([
                Tables\Filters\TernaryFilter::make('is_active')->label('Attivo'),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
                // Il tenant master (Alex) e' quello dello staff che gestisce tutti gli
                // altri partner: eliminarlo per errore blocca l'accesso di tutto lo staff.
                Tables\Actions\DeleteAction::make()
                    ->hidden(fn (Tenant $record) => $record->is_master),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make()
                        /** @param \Illuminate\Database\Eloquent\Collection<int, Tenant> $records */
                        ->action(function (\Illuminate\Database\Eloquent\Collection $records) {
                            $hadMaster = $records->contains('is_master', true);
                            $records->reject(fn (Tenant $record) => $record->is_master)
                                ->each->delete();

                            if ($hadMaster) {
                                \Filament\Notifications\Notification::make()
                                    ->title('Il tenant master non è stato eliminato')
                                    ->body('Il tenant master (Alex) non può essere eliminato dal pannello.')
                                    ->warning()
                                    ->send();
                            }
                        }),
                ]),
            ]);
    }

    public static function getRelations(): array
    {
        return [
            DeadlinesRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListTenants::route('/'),
            'create' => Pages\CreateTenant::route('/create'),
            'edit' => Pages\EditTenant::route('/{record}/edit'),
        ];
    }
}
