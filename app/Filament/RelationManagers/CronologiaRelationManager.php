<?php

namespace App\Filament\RelationManagers;

use App\Filament\Resources\AuditLogResource;
use App\Models\AuditLog;
use App\Support\Audit\Modifiche;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * "Chi ha toccato questo record, e cosa ha cambiato", sulla scheda del
 * record stesso.
 *
 * Il log generale (AuditLogResource) c'e' da sempre, ma sta sotto
 * Impostazioni e lo vede solo chi ha i permessi di amministrazione: per
 * rispondere a "chi ha messo Il Filare come pagante di questa macchina?"
 * bisognava andarlo a cercare fra migliaia di righe di tutti i record. Qui
 * la stessa storia e' attaccata alla cosa di cui parla.
 *
 * Sola lettura: l'audit non si corregge, altrimenti non e' un audit.
 */
class CronologiaRelationManager extends RelationManager
{
    protected static string $relationship = 'activitiesAsSubject';

    protected static ?string $title = 'Chi ha cambiato cosa';

    protected static ?string $icon = 'heroicon-o-clock';

    public function isReadOnly(): bool
    {
        return true;
    }

    /**
     * Stesso permesso del registro generale (view_any_audit::log, concesso
     * al solo ruolo admin in App\Support\RolePermissions): chi puo' aprire
     * un rapportino non per questo deve vedere chi l'ha toccato.
     */
    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return AuditLogResource::canViewAny();
    }

    public function table(Table $table): Table
    {
        return $table
            ->heading('Chi ha cambiato cosa')
            ->description('Solo le modifiche fatte da una persona dal pannello: import e sincronizzazioni con Eureka non lasciano traccia qui.')
            ->modifyQueryUsing(fn (Builder $query) => $query->with('causer'))
            ->defaultSort('created_at', 'desc')
            ->paginationPageOptions([10, 25, 50])
            ->emptyStateHeading('Nessuna modifica registrata')
            ->emptyStateDescription('Il record non è mai stato modificato a mano da quando c\'è il registro.')
            ->columns([
                Tables\Columns\TextColumn::make('created_at')
                    ->label('Quando')
                    ->dateTime('d/m/Y H:i')
                    ->sortable(),
                Tables\Columns\TextColumn::make('causer.name')
                    ->label('Chi')
                    ->placeholder('Sistema')
                    ->wrap(),
                Tables\Columns\TextColumn::make('event')
                    ->label('Cosa ha fatto')
                    ->badge()
                    ->color(fn (?string $state) => AuditLogResource::eventColors()[$state] ?? 'gray')
                    ->formatStateUsing(fn (?string $state) => AuditLogResource::eventLabels()[$state] ?? $state ?? '—'),
                Tables\Columns\TextColumn::make('modifiche')
                    ->label('Cosa è cambiato')
                    ->wrap()
                    ->listWithLineBreaks()
                    ->limitList(4)
                    ->expandableLimitedList()
                    ->state(fn (AuditLog $record) => static::descrizione($record)),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('event')
                    ->label('Evento')
                    ->options(fn () => AuditLogResource::eventLabels()),
            ])
            ->actions([
                Tables\Actions\Action::make('dettaglio')
                    ->label('Dettaglio')
                    ->icon('heroicon-m-eye')
                    ->url(fn (AuditLog $record) => AuditLogResource::getUrl('view', ['record' => $record->id]))
                    ->openUrlInNewTab()
                    ->visible(fn () => AuditLogResource::canViewAny()),
            ])
            ->bulkActions([]);
    }

    /**
     * Una riga per campo cambiato, gia' con i nomi al posto degli id: qui il
     * soggetto e' sempre lo stesso record, quindi risolvere i valori costa
     * poche query e non una per riga come nel log generale.
     *
     * @return array<int, string>
     */
    protected static function descrizione(AuditLog $record): array
    {
        if ($record->event !== 'updated') {
            return [match ($record->event) {
                'created' => 'Ha creato il record',
                'deleted' => 'Ha eliminato il record',
                'restored' => 'Ha ripristinato il record',
                default => '—',
            }];
        }

        $righe = Modifiche::righe($record);

        if ($righe === []) {
            return ['—'];
        }

        return array_map(
            fn (array $r) => "{$r['campo']}: {$r['prima']} → {$r['dopo']}",
            $righe,
        );
    }
}
