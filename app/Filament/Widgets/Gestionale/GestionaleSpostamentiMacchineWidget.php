<?php

namespace App\Filament\Widgets\Gestionale;

use App\Models\MachineUnit;
use App\Models\ServiceReport;
use App\Support\DisplayName;
use Filament\Notifications\Notification;
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget as BaseWidget;
use Illuminate\Database\Eloquent\Collection;

/**
 * Macchine che su Eureka risultano consegnate a un altro cliente dopo
 * quello che dice il CRM (GestionaleSyncRunner::proponiSpostamentiMacchine()).
 *
 * L'ultimo intervento serve a decidere: se il tecnico e' andato proprio li',
 * lo spostamento e' quasi certamente vero.
 */
class GestionaleSpostamentiMacchineWidget extends BaseWidget
{
    protected static ?string $heading = 'Macchine spostate o ritirate — da aggiornare qui';

    protected int|string|array $columnSpan = 'full';

    // Vedi GestionaleDaRivedereWidget per il perche'.
    protected static bool $isLazy = false;

    public static function canView(): bool
    {
        return static::baseQuery()->exists();
    }

    private static function baseQuery()
    {
        // Senza cliente proposto e' un rientro in magazzino (RientriMagazzino).
        return MachineUnit::query()->whereNotNull('spostamento_suggerito_motivo');
    }

    public function table(Table $table): Table
    {
        return $table
            ->queryStringIdentifier('spostamentiMacchine')
            ->query(static::baseQuery()->with(['spostamentoSuggerito', 'currentCustomer']))
            ->defaultSort('spostamento_suggerito_il', 'desc')
            ->emptyStateHeading('Nessuna macchina da spostare')
            ->columns([
                Tables\Columns\TextColumn::make('serial_number')
                    ->label('Matricola')
                    ->weight('medium')
                    ->searchable()
                    ->description(fn (MachineUnit $record) => $record->model_name),

                Tables\Columns\TextColumn::make('currentCustomer.company_name')
                    ->label('Nel CRM presso')
                    ->formatStateUsing(fn (?string $state) => DisplayName::titleCase($state))
                    ->color('gray')
                    ->placeholder('magazzino'),

                Tables\Columns\TextColumn::make('spostamentoSuggerito.company_name')
                    ->label('Dovrebbe essere')
                    ->formatStateUsing(fn (?string $state) => DisplayName::titleCase($state))
                    ->weight('medium')
                    ->placeholder('in magazzino')
                    ->description(fn (MachineUnit $record) => $record->spostamentoSuggerito?->city),

                Tables\Columns\TextColumn::make('spostamento_suggerito_il')
                    ->label('Dal')
                    ->date('d/m/Y')
                    ->sortable(),

                Tables\Columns\TextColumn::make('ultimo_intervento')
                    ->label('Ultimo intervento')
                    ->state(function (MachineUnit $record): ?string {
                        $r = ServiceReport::query()->with('customer')
                            ->where('machine_unit_id', $record->id)
                            ->latest('intervention_date')->first();

                        return $r ? $r->intervention_date->format('d/m/Y').' — '.DisplayName::titleCase($r->customer?->company_name) : null;
                    })
                    ->color(function (MachineUnit $record): string {
                        $presso = ServiceReport::query()->where('machine_unit_id', $record->id)
                            ->latest('intervention_date')->value('customer_id');

                        return $presso === $record->spostamento_suggerito_customer_id ? 'success' : 'gray';
                    })
                    ->placeholder('—'),

                Tables\Columns\TextColumn::make('spostamento_suggerito_motivo')
                    ->label('Perché')
                    ->wrap()
                    ->color('gray'),
            ])
            ->actions([
                Tables\Actions\Action::make('sposta')
                    ->label('Sposta')
                    ->icon('heroicon-o-arrow-right-circle')
                    ->color('success')
                    ->requiresConfirmation()
                    ->modalHeading('Spostare la macchina?')
                    ->modalDescription(fn (MachineUnit $record) => sprintf(
                        'La matricola %s %s dal %s. Nello storico resta dov\'era prima.',
                        $record->serial_number,
                        $record->spostamentoSuggerito
                            ? 'passa presso '.DisplayName::titleCase($record->spostamentoSuggerito->company_name)
                            : 'rientra in magazzino',
                        $record->spostamento_suggerito_il?->format('d/m/Y'),
                    ))
                    ->action(function (MachineUnit $record) {
                        $record->accettaSpostamento();
                        Notification::make()->title('Macchina spostata')->success()->send();
                    }),

                Tables\Actions\Action::make('scarta')
                    ->label('Non è vero')
                    ->icon('heroicon-o-x-mark')
                    ->color('gray')
                    ->requiresConfirmation()
                    ->modalDescription('La proposta sparisce e non torna, finché Eureka non registra una bolla nuova per questa macchina.')
                    ->action(fn (MachineUnit $record) => $record->scartaSpostamento()),
            ])
            ->bulkActions([
                Tables\Actions\BulkAction::make('sposta_tutte')
                    ->label('Sposta le selezionate')
                    ->icon('heroicon-o-arrow-right-circle')
                    ->color('success')
                    ->requiresConfirmation()
                    ->action(function (Collection $records) {
                        $n = $records->filter(fn (MachineUnit $m) => $m->accettaSpostamento())->count();
                        Notification::make()->title("{$n} macchine spostate")->success()->send();
                    }),
            ])
            ->paginated([10, 25, 50]);
    }
}
