<?php

namespace App\Filament\Widgets\Gestionale;

use App\Models\MachineUnit;
use Filament\Notifications\Notification;
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget as BaseWidget;

class GestionaleCollegamentiMacchinariWidget extends BaseWidget
{
    protected static ?string $heading = 'Collegamenti proposti — macchinari';

    protected int|string|array $columnSpan = 1;

    // Vedi GestionaleDaRivedereWidget: senza un identificatore proprio, la
    // paginazione di questa tabella condivide ?page= con le altre 4 della
    // stessa pagina.
    protected function getTableQueryStringIdentifier(): ?string
    {
        return 'collegamentiMacchinari';
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(MachineUnit::query()->whereNotNull('gestionale_suggested_code'))
            ->columns([
                Tables\Columns\TextColumn::make('serial_number')->label('Matricola'),
                Tables\Columns\TextColumn::make('display_name')->label('Modello'),
                Tables\Columns\TextColumn::make('gestionale_suggested_label')
                    ->label('Trovata su Eureka')
                    ->description(fn (MachineUnit $record) => "id {$record->gestionale_suggested_code}")
                    ->placeholder('—'),
            ])
            ->actions([
                Tables\Actions\Action::make('conferma_collegamento_gestionale')
                    ->label('Conferma')
                    ->icon('heroicon-o-link')
                    ->color('warning')
                    ->requiresConfirmation()
                    ->modalDescription('Il sync automatico ha trovato questa matricola su Eureka. Confermi?')
                    ->action(function (MachineUnit $record) {
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
                    ->action(fn (MachineUnit $record) => $record->update([
                        'gestionale_suggested_code' => null,
                        'gestionale_suggested_label' => null,
                    ])),
            ])
            ->paginated([10, 25, 50]);
    }
}
