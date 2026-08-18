<?php

namespace App\Filament\Widgets\Gestionale;

use App\Models\Customer;
use Filament\Notifications\Notification;
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget as BaseWidget;

class GestionaleCollegamentiClientiWidget extends BaseWidget
{
    protected static ?string $heading = 'Collegamenti proposti — clienti';

    protected int|string|array $columnSpan = 1;

    // Vedi GestionaleDaRivedereWidget per il perche'.
    protected static bool $isLazy = false;

    public static function canView(): bool
    {
        return static::baseQuery()->exists();
    }

    private static function baseQuery()
    {
        return Customer::query()->whereNotNull('gestionale_suggested_code');
    }

    public function table(Table $table): Table
    {
        return $table
            // Vedi GestionaleDaRivedereWidget per il perche'.
            ->queryStringIdentifier('collegamentiClienti')
            ->query(static::baseQuery())
            ->columns([
                Tables\Columns\TextColumn::make('full_name')->label('Cliente nel CRM'),
                Tables\Columns\TextColumn::make('gestionale_suggested_label')
                    ->label('Trovato su Eureka')
                    ->description(fn (Customer $record) => "id {$record->gestionale_suggested_code}")
                    ->placeholder('—'),
            ])
            ->actions([
                Tables\Actions\Action::make('conferma_collegamento_gestionale')
                    ->label('Conferma')
                    ->icon('heroicon-o-link')
                    ->color('warning')
                    ->requiresConfirmation()
                    ->modalDescription('Il sync automatico ha trovato questo possibile collegamento su Eureka. Confermi?')
                    ->action(function (Customer $record) {
                        // proposeCustomerLinks() ora esclude gia' gli id Eureka gia'
                        // assegnati altrove, ma questo resta un controllo di sicurezza
                        // per i suggerimenti creati prima del fix (o in caso di corsa
                        // tra due sync ravvicinati): senza, l'update sotto va a sbattere
                        // sul vincolo unique su gestionale_code (UniqueConstraintViolationException).
                        $conflict = Customer::where('gestionale_code', $record->gestionale_suggested_code)
                            ->where('id', '!=', $record->id)
                            ->first();

                        if ($conflict) {
                            $record->update([
                                'gestionale_suggested_code' => null,
                                'gestionale_suggested_label' => null,
                            ]);

                            Notification::make()
                                ->title('Collegamento scartato')
                                ->body("Il codice Eureka {$record->gestionale_suggested_code} è già assegnato a \"{$conflict->full_name}\": probabile doppione da unire a mano, non confermabile automaticamente.")
                                ->warning()
                                ->send();

                            return;
                        }

                        $record->update([
                            'gestionale_code' => $record->gestionale_suggested_code,
                            'gestionale_suggested_code' => null,
                            'gestionale_suggested_label' => null,
                        ]);
                        Notification::make()->title('Collegamento confermato')->success()->send();
                    }),
                Tables\Actions\Action::make('scarta_collegamento_gestionale')
                    ->label('Scarta')
                    ->icon('heroicon-o-x-mark')
                    ->color('gray')
                    ->requiresConfirmation()
                    ->action(fn (Customer $record) => $record->update([
                        'gestionale_suggested_code' => null,
                        'gestionale_suggested_label' => null,
                    ])),
            ])
            ->paginated([10, 25, 50]);
    }
}
