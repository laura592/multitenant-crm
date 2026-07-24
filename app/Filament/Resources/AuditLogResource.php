<?php

namespace App\Filament\Resources;

use App\Filament\Resources\AuditLogResource\Pages;
use App\Models\AuditLog;
use App\Models\User;
use Filament\Facades\Filament;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Form;
use Filament\Infolists\Components\KeyValueEntry;
use Filament\Infolists\Components\Section as InfolistSection;
use Filament\Infolists\Components\TextEntry;
use Filament\Infolists\Infolist;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

/**
 * Epic 6 (ticket 6.3): consultazione di sola lettura dell'audit log
 * (spatie/laravel-activitylog) su tutti i modelli sensibili tracciati
 * (App\Models\AuditLog::subjectLabels() - Tenant, Customer, Product,
 * ProductPrice, Material, Supplier, User; NON Quote/QuoteProduct, dominio di
 * un'altra sessione di lavoro). Una Resource unica con filtri e' piu'
 * pratica di N relation manager separati perche' i modelli tracciati sono
 * eterogenei e senza una relazione comune se non il subject polimorfico.
 *
 * Sola lettura: niente pagine/azioni create, edit o delete (vedi getPages()
 * e table(), che espone solo ViewAction). I permessi (view_any_audit::log /
 * view_audit::log) sono concessi solo al ruolo "admin" in
 * App\Support\RolePermissions; lo staff master (is_super_admin) bypassa
 * comunque tutto via Gate::before (AppServiceProvider).
 */
class AuditLogResource extends Resource
{
    protected static ?string $model = AuditLog::class;

    // Come Supplier/Material/Product (SharedAcrossTenants): lo scoping
    // automatico stretto di Filament nasconderebbe le righe con tenant_id
    // NULL (modifiche a record del catalogo condiviso). Lo scoping vero lo
    // fa gia' il trait BelongsToTenant sul modello AuditLog.
    protected static bool $isScopedToTenant = false;

    protected static ?string $navigationIcon = 'heroicon-o-clipboard-document-list';

    protected static ?string $navigationGroup = 'Amministrazione';

    protected static ?string $navigationLabel = 'Log modifiche';

    protected static ?string $modelLabel = 'Voce di log';

    protected static ?string $pluralModelLabel = 'Log modifiche';

    // Senza questo, Filament forza il Title Case su $modelLabel e
    // capitalizza anche il "di" ("Voce Di Log").
    protected static bool $hasTitleCaseModelLabel = false;

    public static function form(Form $form): Form
    {
        // Nessuna pagina create/edit registrata (vedi getPages()): questo
        // metodo esiste solo perche' e' astratto su Resource, non viene mai
        // renderizzato.
        return $form->schema([]);
    }

    public static function infolist(Infolist $infolist): Infolist
    {
        return $infolist->schema([
            InfolistSection::make('Voce di log')
                ->columns(3)
                ->schema([
                    TextEntry::make('created_at')->label('Quando')->dateTime('d/m/Y H:i:s'),
                    TextEntry::make('event')->label('Evento')->badge()
                        ->formatStateUsing(fn (?string $state) => match ($state) {
                            'created' => 'Creazione',
                            'updated' => 'Modifica',
                            'deleted' => 'Cancellazione',
                            default => $state ?? '—',
                        }),
                    TextEntry::make('subject_label')->label('Modello')
                        ->state(fn (AuditLog $record) => $record->subjectLabel()),
                    TextEntry::make('subject_id')->label('ID record')->placeholder('—'),
                    TextEntry::make('causer.name')->label('Utente')->placeholder('Sistema'),
                    TextEntry::make('tenant.name')->label('Tenant')->placeholder('Catalogo condiviso'),
                ]),
            InfolistSection::make('Valori precedenti')
                ->schema([
                    KeyValueEntry::make('attribute_changes.old')->label('Prima'),
                ])
                ->visible(fn (AuditLog $record) => filled($record->attribute_changes?->get('old'))),
            InfolistSection::make('Valori nuovi')
                ->schema([
                    KeyValueEntry::make('attribute_changes.attributes')->label('Dopo'),
                ])
                ->visible(fn (AuditLog $record) => filled($record->attribute_changes?->get('attributes'))),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->columns([
                Tables\Columns\TextColumn::make('created_at')
                    ->label('Quando')
                    ->dateTime('d/m/Y H:i:s')
                    ->sortable(),
                Tables\Columns\TextColumn::make('subject_type')
                    ->label('Modello')
                    ->formatStateUsing(fn (AuditLog $record) => $record->subjectLabel())
                    ->badge(),
                Tables\Columns\TextColumn::make('event')
                    ->label('Evento')
                    ->badge()
                    ->color(fn (?string $state) => match ($state) {
                        'created' => 'success',
                        'deleted' => 'danger',
                        'updated' => 'warning',
                        default => 'gray',
                    })
                    ->formatStateUsing(fn (?string $state) => match ($state) {
                        'created' => 'Creazione',
                        'updated' => 'Modifica',
                        'deleted' => 'Cancellazione',
                        default => $state ?? '—',
                    }),
                Tables\Columns\TextColumn::make('causer.name')
                    ->label('Utente')
                    ->placeholder('Sistema')
                    ->searchable(),
                Tables\Columns\TextColumn::make('tenant.name')
                    ->label('Tenant')
                    ->placeholder('Catalogo condiviso')
                    ->toggleable(),
                Tables\Columns\TextColumn::make('description')
                    ->label('Descrizione')
                    ->limit(60)
                    ->toggleable(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('subject_type')
                    ->label('Modello')
                    ->options(fn () => collect(AuditLog::subjectLabels())
                        ->mapWithKeys(fn (string $label, string $class) => [$class => $label])
                        ->all()),
                Tables\Filters\SelectFilter::make('causer_id')
                    ->label('Utente')
                    // Niente ->relationship(): "causer" e' un morphTo (puo' non
                    // puntare sempre a User), quindi non ha un singolo modello
                    // "related" da cui Filament possa ricavare le opzioni. Le
                    // opzioni sono ristrette al tenant corrente (o a tutti se
                    // staff master), stessa logica di scoping usata da
                    // UserResource per la lista utenti.
                    ->options(fn () => User::query()
                        ->when(
                            ! auth()->user()?->is_super_admin,
                            fn (Builder $q) => $q->where('tenant_id', Filament::getTenant()?->id)
                        )
                        ->orderBy('name')
                        ->pluck('name', 'id'))
                    ->searchable()
                    ->query(fn (Builder $query, array $data): Builder => $query->when(
                        $data['value'] ?? null,
                        fn (Builder $q, $value) => $q->where('causer_type', User::class)->where('causer_id', $value)
                    )),
                Tables\Filters\Filter::make('created_between')
                    ->label('Intervallo date')
                    ->form([
                        DatePicker::make('from')->label('Dal'),
                        DatePicker::make('until')->label('Al'),
                    ])
                    ->query(fn (Builder $query, array $data): Builder => $query
                        ->when($data['from'] ?? null, fn (Builder $q, $date) => $q->whereDate('created_at', '>=', $date))
                        ->when($data['until'] ?? null, fn (Builder $q, $date) => $q->whereDate('created_at', '<=', $date)))
                    ->indicateUsing(function (array $data): array {
                        $indicators = [];

                        if ($data['from'] ?? null) {
                            $indicators[] = 'Dal: '.Carbon::parse($data['from'])->format('d/m/Y');
                        }

                        if ($data['until'] ?? null) {
                            $indicators[] = 'Al: '.Carbon::parse($data['until'])->format('d/m/Y');
                        }

                        return $indicators;
                    }),
            ])
            ->actions([
                Tables\Actions\ViewAction::make(),
            ])
            ->bulkActions([]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListAuditLogs::route('/'),
            'view' => Pages\ViewAuditLog::route('/{record}'),
        ];
    }

    public static function canCreate(): bool
    {
        return false;
    }
}
