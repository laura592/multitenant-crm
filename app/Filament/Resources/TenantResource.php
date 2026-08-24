<?php

namespace App\Filament\Resources;

use App\Filament\Forms\ItalianAddressFields;
use App\Filament\Resources\TenantResource\Pages;
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
                        ->afterStateUpdated(fn (string $state, Forms\Set $set) => $set('slug', Str::slug($state)))
                        ->extraAttributes(['data-tour' => 'tenants-field-name']),
                    Forms\Components\TextInput::make('slug')
                        ->label('Slug (URL pannello)')
                        ->required()
                        ->unique(ignoreRecord: true)
                        ->maxLength(255),
                    Forms\Components\TextInput::make('legal_name')->label('Ragione sociale')->maxLength(255),
                    Forms\Components\TextInput::make('vat_number')->label('P.IVA')->maxLength(255),
                    Forms\Components\TextInput::make('tax_code')->label('Codice fiscale')->maxLength(255),
                    Forms\Components\TextInput::make('sdi')->label('Codice SDI')->maxLength(255),
                    Forms\Components\TextInput::make('iban')->label('IBAN')->maxLength(255),
                    Forms\Components\TextInput::make('email')->label('Email')->email()->maxLength(255),
                    Forms\Components\TextInput::make('phone')->label('Telefono')->tel()->maxLength(255),
                    Forms\Components\TextInput::make('fax')->label('Fax')->tel()->maxLength(255),
                    Forms\Components\Toggle::make('is_master')->label('Tenant master (Alex)'),
                    Forms\Components\Toggle::make('is_active')->label('Attivo')->default(true)
                        ->extraAttributes(['data-tour' => 'tenants-field-active']),
                ]),
            Forms\Components\Section::make('Indirizzo')
                ->columns(3)
                ->schema(ItalianAddressFields::schema()),
            Forms\Components\Section::make('Branding')
                ->columns(2)
                ->schema([
                    Forms\Components\FileUpload::make('logo_path')
                        ->label('Logo')
                        ->image()
                        ->directory('tenant-logos')
                        ->maxSize(5120)
                        // Nei PDF il logo è mostrato al massimo a 180x60px: senza questo
                        // resize lato client, un logo caricato a risoluzione fotocamera
                        // (es. 4000x3000) viene incorporato nel PDF a piena risoluzione,
                        // gonfiandone il peso di decine di volte.
                        ->imageResizeTargetWidth(600)
                        ->imageResizeTargetHeight(600)
                        ->imageResizeMode('contain')
                        ->imageResizeUpscale(false),
                    Forms\Components\ColorPicker::make('primary_color')->label('Colore primario'),
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
            ])
            ->filters([
                Tables\Filters\TernaryFilter::make('is_active')->label('Attivo'),
                Tables\Filters\TrashedFilter::make(),
            ])
            ->actions([
                Tables\Actions\ActionGroup::make([
                    Tables\Actions\EditAction::make(),
                    // Il tenant master (Alex) e' quello dello staff che gestisce tutti gli
                    // altri partner: eliminarlo per errore blocca l'accesso di tutto lo staff.
                    Tables\Actions\DeleteAction::make()
                        ->hidden(fn (Tenant $record) => $record->is_master)
                        ->before(function (Tenant $record, Tables\Actions\DeleteAction $action) {
                            if ($record->hasBlockingRelatedRecords()) {
                                \Filament\Notifications\Notification::make()
                                    ->title('Impossibile eliminare')
                                    ->body('Questo tenant ha ancora dati collegati (utenti, clienti, preventivi, rapportini...): va svuotato prima di poterlo eliminare.')
                                    ->danger()
                                    ->send();

                                $action->halt();
                            }
                        }),
                    Tables\Actions\RestoreAction::make(),
                    Tables\Actions\ForceDeleteAction::make()
                        ->hidden(fn (Tenant $record) => $record->is_master),
                ]),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make()
                        /** @param \Illuminate\Database\Eloquent\Collection<int, Tenant> $records */
                        ->action(function (\Illuminate\Database\Eloquent\Collection $records) {
                            $hadMaster = $records->contains('is_master', true);
                            $deletable = $records->reject(fn (Tenant $record) => $record->is_master);
                            $blocked = $deletable->filter(fn (Tenant $record) => $record->hasBlockingRelatedRecords());

                            $deletable->reject(fn (Tenant $record) => $record->hasBlockingRelatedRecords())
                                ->each->delete();

                            if ($hadMaster) {
                                \Filament\Notifications\Notification::make()
                                    ->title('Il tenant master non è stato eliminato')
                                    ->body('Il tenant master (Alex) non può essere eliminato dal pannello.')
                                    ->warning()
                                    ->send();
                            }

                            if ($blocked->isNotEmpty()) {
                                \Filament\Notifications\Notification::make()
                                    ->title('Alcuni tenant non sono stati eliminati')
                                    ->body('Hanno ancora dati collegati: '.$blocked->pluck('name')->implode(', ').'.')
                                    ->danger()
                                    ->send();
                            }
                        }),
                    Tables\Actions\RestoreBulkAction::make(),
                    Tables\Actions\ForceDeleteBulkAction::make(),
                ]),
            ]);
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
