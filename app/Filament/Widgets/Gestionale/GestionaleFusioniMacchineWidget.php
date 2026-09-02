<?php

namespace App\Filament\Widgets\Gestionale;

use App\Models\MachineUnit;
use App\Support\DisplayName;
use App\Support\Gestionale\ConfrontoMacchine;
use Filament\Notifications\Notification;
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget as BaseWidget;

/**
 * Macchine che sono lo stesso apparecchio entrato due volte in anagrafica.
 *
 * Succede perche' chi registra scrive nel campo matricola quello che ha
 * sott'occhio: con o senza spazi ("BRL 003 020..." e "BRL003020..."), con lo
 * zero davanti, o con il modello prima del seriale ("A 300 3400000310192").
 * Ogni doppione e' un rapportino che non si abbinera' mai alla sua scheda.
 *
 * Confermare tiene la macchina piu' attendibile — quella collegata a Eureka —
 * e le sposta addosso rapportini e storico dell'altra, che viene archiviata.
 */
class GestionaleFusioniMacchineWidget extends BaseWidget
{
    protected static ?string $heading = 'Macchine doppie — stessa matricola scritta in due modi';

    protected int|string|array $columnSpan = 'full';

    // Vedi GestionaleDaRivedereWidget per il perche'.
    protected static bool $isLazy = false;

    public static function canView(): bool
    {
        return static::baseQuery()->exists();
    }

    private static function baseQuery()
    {
        return MachineUnit::query()->whereNotNull('fusione_suggerita_id');
    }

    public function table(Table $table): Table
    {
        return $table
            ->queryStringIdentifier('fusioniMacchine')
            ->query(static::baseQuery()->with(['fusioneSuggerita', 'currentCustomer']))
            ->defaultSort('fusione_suggerita_motivo')
            ->emptyStateHeading('Nessuna macchina doppia')
            ->columns([
                Tables\Columns\TextColumn::make('fusioneSuggerita.serial_number')
                    ->label('Si tiene questa')
                    ->weight('medium')
                    ->description(fn (MachineUnit $record) => $record->fusioneSuggerita?->model_name),

                Tables\Columns\TextColumn::make('serial_number')
                    ->label('Viene assorbita')
                    ->color('gray')
                    ->description(fn (MachineUnit $record) => $record->model_name),

                Tables\Columns\TextColumn::make('currentCustomer.company_name')
                    ->label('Cliente')
                    ->formatStateUsing(fn (?string $state) => DisplayName::titleCase($state))
                    ->placeholder('—'),

                Tables\Columns\TextColumn::make('rapportini')
                    ->label('Rapportini')
                    // MachineUnit non ha una relazione verso i rapportini:
                    // si conta dal loro lato. E' il numero che dice quanto
                    // pesa la fusione.
                    ->state(fn (MachineUnit $record) => \App\Models\ServiceReport::query()
                        ->where('machine_unit_id', $record->id)->count())
                    ->alignRight(),

                Tables\Columns\TextColumn::make('fusione_suggerita_motivo')
                    ->label('Perché')
                    ->badge()
                    // Le prime due si leggono dai dati e non sbagliano; la
                    // terza e' un'interpretazione e va guardata.
                    ->color(fn (?string $state) => $state === ConfrontoMacchine::MATRICOLA_CONTENUTA ? 'warning' : 'success'),
            ])
            ->actions([
                Tables\Actions\Action::make('fondi')
                    ->label('È la stessa')
                    ->icon('heroicon-o-link')
                    ->color('warning')
                    ->requiresConfirmation()
                    ->modalHeading('Confermi che sono la stessa macchina?')
                    ->modalDescription(fn (MachineUnit $record) => sprintf(
                        'Resta %s. Rapportini e storico di %s passano su di lei, e %s viene archiviata: si puo\' recuperare, ma sparisce dagli elenchi.',
                        $record->fusioneSuggerita?->serial_number,
                        $record->serial_number,
                        $record->serial_number,
                    ))
                    ->action(function (MachineUnit $record) {
                        $tenere = $record->fusioneSuggerita;

                        if (! $tenere) {
                            $record->scartaFusione();

                            return;
                        }

                        $tenere->assorbe($record);
                        Notification::make()->title('Macchine fuse')->success()->send();
                    }),

                Tables\Actions\Action::make('scarta')
                    ->label('Sono diverse')
                    ->icon('heroicon-o-x-mark')
                    ->color('gray')
                    ->requiresConfirmation()
                    ->action(fn (MachineUnit $record) => $record->scartaFusione()),
            ])
            ->paginated([10, 25, 50]);
    }
}
