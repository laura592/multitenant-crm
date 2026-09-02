<?php

namespace App\Filament\Widgets;

use App\Filament\Resources\ServiceReportResource;
use App\Models\ServiceReport;
use App\Support\DisplayName;
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget as BaseWidget;
use Illuminate\Support\Facades\Auth;

class FailedGestionaleServiceReportsWidget extends BaseWidget
{
    // Sezione "Da fare adesso", affiancato a Prossime scadenze (stesso sort,
    // stesso columnSpan): finora un invio fallito si scopriva solo aprendo il
    // singolo rapportino o notando l'icona rossa scorrendo l'elenco, niente
    // lo segnalava attivamente.
    protected static ?int $sort = 2;

    // Gate sulla pagina "Sync Eureka" e non su view_any_service::report: un
    // invio fallito si risolve guardando l'errore del gestionale e i log
    // (storage/logs/eureka-import.log), cioe' e' roba di chi la
    // sincronizzazione la governa — admin e amministratore, gli unici con
    // page_GestionaleSyncReview in RolePermissions. Col permesso sui
    // rapportini ci finivano dentro anche il tecnico che li compila e il
    // profilo amministrazione che li integra: per loro e' un messaggio
    // d'errore su cui non possono fare niente.
    public static function canView(): bool
    {
        return Auth::user()->can('page_GestionaleSyncReview');
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
                Tables\Columns\TextColumn::make('customer.company_name')->label('Cliente')->wrap()
                    ->formatStateUsing(fn (?string $state) => DisplayName::titleCase($state)),
                Tables\Columns\TextColumn::make('gestionale_sync_error')->label('Errore')->limit(40)->placeholder('—'),
            ])
            ->paginated(false)
            ->emptyStateHeading('Nessun invio fallito')
            ->emptyStateDescription('Tutti i rapportini inviati a Eureka sono andati a buon fine.');
    }
}
