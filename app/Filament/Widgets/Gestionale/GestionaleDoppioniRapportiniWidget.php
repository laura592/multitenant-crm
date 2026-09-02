<?php

namespace App\Filament\Widgets\Gestionale;

use App\Models\ServiceReport;
use App\Support\DisplayName;
use App\Support\Gestionale\ConfrontoRapportini;
use Filament\Notifications\Notification;
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget as BaseWidget;

/**
 * Rapportini compilati nel CRM per i quali il sync ha trovato una scheda
 * lavoro importata da Eureka che documenta lo stesso intervento.
 *
 * Succede perche' l'ufficio inserisce in Eureka lavori che il tecnico ha
 * gia' registrato qui: dopo un import lo stesso intervento compare due
 * volte. Confermare tiene il rapportino del tecnico — con firma e dettaglio
 * — e gli travasa il collegamento a Eureka, eliminando la copia importata.
 */
class GestionaleDoppioniRapportiniWidget extends BaseWidget
{
    protected static ?string $heading = 'Rapportini doppi — stesso intervento su Eureka';

    protected int|string|array $columnSpan = 'full';

    // Vedi GestionaleDaRivedereWidget per il perche'.
    protected static bool $isLazy = false;

    public static function canView(): bool
    {
        return static::baseQuery()->exists();
    }

    private static function baseQuery()
    {
        return ServiceReport::query()->whereNotNull('duplicato_suggerito_id');
    }

    public function table(Table $table): Table
    {
        return $table
            ->queryStringIdentifier('doppioniRapportini')
            ->query(static::baseQuery()->with(['customer', 'machineUnit', 'duplicatoSuggerito']))
            ->columns([
                Tables\Columns\TextColumn::make('number')
                    ->label('Nostro rapportino')
                    ->weight('medium')
                    ->description(fn (ServiceReport $record) => $record->intervention_date?->format('d/m/Y')),

                Tables\Columns\TextColumn::make('customer.company_name')
                    ->label('Cliente')
                    ->formatStateUsing(fn (?string $state) => DisplayName::titleCase($state))
                    ->description(fn (ServiceReport $record) => $record->machineUnit?->serial_number),

                Tables\Columns\TextColumn::make('duplicatoSuggerito.number')
                    ->label('Copia da Eureka')
                    ->description(fn (ServiceReport $record) => $record->duplicatoSuggerito?->gestionale_number
                        ? "scheda n. {$record->duplicatoSuggerito->gestionale_number}"
                        : null),

                Tables\Columns\TextColumn::make('duplicato_suggerito_motivo')
                    ->label('Perché')
                    ->badge()
                    ->color(fn (?string $state) => $state === ConfrontoRapportini::CERTO ? 'success' : 'warning'),
            ])
            ->actions([
                Tables\Actions\Action::make('dettagli')
                    ->label('Confronta')
                    ->icon('heroicon-o-eye')
                    ->color('gray')
                    ->modalHeading('Sono lo stesso intervento?')
                    // Si decide da qui: chiudere il confronto per poi cercare
                    // la riga giusta e premere un altro bottone e' un giro a
                    // vuoto proprio nel momento in cui si e' appena guardato
                    // il dettaglio e si sa la risposta.
                    ->modalSubmitActionLabel('È lo stesso')
                    ->modalCancelActionLabel('Decido dopo')
                    ->modalWidth('4xl')
                    ->extraModalFooterActions([
                        Tables\Actions\Action::make('scarta_dal_confronto')
                            ->label('Sono diversi')
                            ->color('gray')
                            ->icon('heroicon-o-x-mark')
                            ->action(function (ServiceReport $record) {
                                $record->scartaDuplicato();
                                Notification::make()->title('Tenuti separati')->success()->send();
                            })
                            ->cancelParentActions(),
                    ])
                    ->action(function (ServiceReport $record) {
                        $record->confermaDuplicato();
                        Notification::make()->title('Rapportini uniti')->success()->send();
                    })
                    ->modalContent(fn (ServiceReport $record) => view(
                        'filament.widgets.confronto-rapportini',
                        [
                            'nostro' => $record->load(['technician', 'machineUnit', 'materialsUsed.material']),
                            'importato' => $record->duplicatoSuggerito
                                ->load(['technician', 'machineUnit', 'materialsUsed.material']),
                        ],
                    )),

                Tables\Actions\Action::make('conferma_doppione')
                    ->label('È lo stesso')
                    ->icon('heroicon-o-link')
                    ->color('warning')
                    ->requiresConfirmation()
                    ->modalHeading('Confermi che sono lo stesso intervento?')
                    ->modalDescription('Il rapportino del tecnico resta e riceve il collegamento a Eureka. La copia importata viene eliminata: si può recuperare, ma sparisce dagli elenchi.')
                    ->action(function (ServiceReport $record) {
                        $record->confermaDuplicato();
                        Notification::make()->title('Rapportini uniti')->success()->send();
                    }),

                Tables\Actions\Action::make('scarta_doppione')
                    ->label('Sono diversi')
                    ->icon('heroicon-o-x-mark')
                    ->color('gray')
                    ->requiresConfirmation()
                    ->action(fn (ServiceReport $record) => $record->scartaDuplicato()),
            ])
            ->paginated([10, 25, 50]);
    }
}
