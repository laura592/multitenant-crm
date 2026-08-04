<?php

namespace App\Filament\Widgets\Gestionale;

use App\Models\Customer;
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget as BaseWidget;

class GestionaleDaRivedereWidget extends BaseWidget
{
    protected static ?string $heading = 'Da rivedere';

    protected int|string|array $columnSpan = 1;

    // Senza questo, tutte le tabelle della pagina "Sync Eureka" condividono
    // lo stesso parametro ?page= nell'URL: sfogliare una tabella lunga (es.
    // le macchine nuove) spingeva anche le altre alla stessa pagina, che per
    // loro poteva non esistere ("Nessun risultato" su dati che c'erano).
    protected function getTableQueryStringIdentifier(): ?string
    {
        return 'daRivedere';
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(Customer::query()->whereNotNull('gestionale_review_flagged_at')->orderByDesc('gestionale_review_flagged_at'))
            ->columns([
                Tables\Columns\TextColumn::make('full_name')->label('Cliente'),
                Tables\Columns\TextColumn::make('gestionale_review_note')->label('Nota')->wrap(),
                Tables\Columns\TextColumn::make('gestionale_review_flagged_at')->label('Dal')->date(),
            ])
            ->actions([
                Tables\Actions\Action::make('segna_aggiornato_gestionale')
                    ->label('Segna come controllato')
                    ->icon('heroicon-o-check-circle')
                    ->color('success')
                    ->requiresConfirmation()
                    ->modalDescription('Non scrive nulla su Eureka: toglie solo la segnalazione da questo cliente, per dire "ho controllato".')
                    ->action(fn (Customer $record) => $record->dismissGestionaleReview()),
            ])
            ->paginated([10, 25, 50]);
    }
}
