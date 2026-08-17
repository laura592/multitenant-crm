<?php

namespace App\Filament\Widgets;

use App\Filament\Resources\ServiceReportResource;
use App\Models\ServiceReport;
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget as BaseWidget;
use Illuminate\Support\Facades\Auth;

class FailedGestionaleServiceReportsWidget extends BaseWidget
{
    // Stesso sort/columnSpan delle altre tabelle di sintesi in dashboard
    // (Prossime scadenze, Piani in scadenza): finora un invio fallito si
    // scopriva solo aprendo il singolo rapportino o notando l'icona rossa
    // scorrendo l'elenco, niente lo segnalava attivamente.
    protected static ?int $sort = 6;

    public static function canView(): bool
    {
        return Auth::user()->can('view_any_service::report');
    }

    protected int|string|array $columnSpan = 1;

    protected static ?string $heading = 'Invii Eureka falliti';

    public function table(Table $table): Table
    {
        return $table
            ->query(ServiceReport::query()
                ->where('gestionale_sync_status', 'failed')
                ->orderByDesc('gestionale_synced_at')
                ->limit(5))
            ->columns([
                Tables\Columns\TextColumn::make('number')
                    ->label('Rapportino')
                    ->url(fn (ServiceReport $record) => ServiceReportResource::getUrl('view', ['record' => $record->id], tenant: $record->tenant))
                    ->color('primary'),
                Tables\Columns\TextColumn::make('customer.company_name')->label('Cliente')->wrap(),
                Tables\Columns\TextColumn::make('gestionale_sync_error')->label('Errore')->limit(40)->placeholder('—'),
            ])
            ->paginated(false)
            ->emptyStateHeading('Nessun invio fallito')
            ->emptyStateDescription('Tutti i rapportini inviati a Eureka sono andati a buon fine.');
    }
}
