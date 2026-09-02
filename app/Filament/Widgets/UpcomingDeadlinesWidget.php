<?php

namespace App\Filament\Widgets;

use App\Filament\Resources\DeadlineResource;
use App\Models\Deadline;
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget as BaseWidget;
use Illuminate\Support\Facades\Auth;

class UpcomingDeadlinesWidget extends BaseWidget
{
    // Sezione "Da fare adesso", stesso sort di FailedGestionaleService
    // ReportsWidget: le due tabelle (colSpan 1) stanno sulla stessa riga.
    protected static ?int $sort = 2;

    public static function canView(): bool
    {
        return Auth::user()->can('view_any_deadline');
    }

    protected int|string|array $columnSpan = 1;

    protected static ?string $heading = 'Prossime scadenze';

    public function table(Table $table): Table
    {
        return $table
            ->query(Deadline::query()
                ->where('status', Deadline::STATUS_ATTIVA)
                ->where('due_date', '>=', now()->startOfDay())
                // La colonna "Collegata a" legge il morph: senza eager load
                // sono 5 query in piu' ad ogni apertura della dashboard.
                ->with('deadlinable')
                ->orderBy('due_date')
                ->limit(5))
            ->columns([
                Tables\Columns\TextColumn::make('type')
                    ->label('Tipo')
                    ->badge()
                    ->formatStateUsing(fn (string $state) => Deadline::typeLabels()[$state] ?? 'Altro'),
                // Senza questa colonna la riga diceva solo "Assicurazione —
                // 15/09": il tipo di scadenza da solo non basta a capire di
                // quale mezzo (o dell'azienda) si stia parlando.
                Tables\Columns\TextColumn::make('deadlinable_id')
                    ->label('Collegata a')
                    ->wrap()
                    ->state(fn (Deadline $record) => $record->relatedLabel()),
                Tables\Columns\TextColumn::make('due_date')
                    ->label('Scadenza')
                    ->date()
                    ->color(fn (Deadline $record) => $record->dueDateColor())
                    // "fra 3 giorni" sotto la data: leggere 15/09 costringe a
                    // fare il conto a mente per capire se e' un problema.
                    ->description(fn (Deadline $record) => PrioritaWidget::whenLabel($record)),
            ])
            // La riga porta alla scadenza, ma solo per chi la puo' aprire in
            // modifica: DeadlineResource non ha una pagina di sola lettura.
            ->recordUrl(fn (Deadline $record) => Auth::user()->can('update', $record)
                ? DeadlineResource::getUrl('edit', ['record' => $record])
                : null)
            ->emptyStateHeading('Nessuna scadenza in arrivo')
            ->emptyStateDescription('Non ci sono scadenze attive da qui in avanti.')
            ->emptyStateIcon('heroicon-o-check-circle')
            ->paginated(false);
    }
}
