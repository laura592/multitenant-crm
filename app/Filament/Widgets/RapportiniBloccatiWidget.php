<?php

namespace App\Filament\Widgets;

use App\Filament\Resources\ServiceReportResource;
use App\Models\ServiceReport;
use App\Support\DisplayName;
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget as BaseWidget;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;

/**
 * I rapportini che non si possono mandare a Eureka, prima che qualcuno ci
 * provi (03/11/2026).
 *
 * Il blocco si scopriva premendo "Invia a gestionale": macchina senza
 * articolo, cliente senza codice, sintomo mancante. In una sola mattina e'
 * successo tre volte -- Roncal, Duilio, Le Soleil -- e ogni volta il lavoro
 * era gia' fatto e il cliente gia' in attesa della fattura.
 *
 * Le ragioni le calcola ServiceReport::gestionaleValidationErrors(), le
 * stesse che l'invio mostrerebbe: qui si leggono in anticipo, in elenco,
 * invece che una alla volta a sorpresa.
 */
class RapportiniBloccatiWidget extends BaseWidget
{
    // Accanto agli invii falliti: sono due facce della stessa cosa, uno e'
    // "l'ho mandato e Eureka l'ha respinto", l'altro "non si puo' nemmeno
    // provare".
    protected static ?int $sort = 2;

    protected int|string|array $columnSpan = 'full';

    protected static ?string $heading = 'Rapportini che non si possono inviare';

    /**
     * Stesso cancello degli invii falliti: e' roba di chi governa la
     * sincronizzazione, non del tecnico che il rapportino lo compila.
     */
    public static function canView(): bool
    {
        return Auth::user()->can('page_GestionaleSyncReview');
    }

    /**
     * Il widget sparisce quando non c'e' niente da sbloccare: una tabella
     * vuota in cima alla dashboard e' rumore, e la dashboard ne ha gia'.
     */
    public static function canDisplay(): bool
    {
        return static::bloccati()->isNotEmpty();
    }

    /**
     * I rapportini completati, mai inviati, che oggi non passerebbero.
     *
     * Il controllo gira in PHP e non in SQL perche' le ragioni stanno in
     * relazioni diverse (cliente, articolo, sintomo): si guardano solo i
     * completati non inviati degli ultimi mesi, non quattromila.
     *
     * @return Collection<int, ServiceReport>
     */
    protected static function bloccati(): Collection
    {
        return ServiceReport::query()
            ->where('status', 'completato')
            ->whereNull('gestionale_sync_status')
            ->where('intervention_date', '>=', now()->subMonths(6))
            ->with(['customer', 'machineUnit.material', 'technician'])
            ->orderByDesc('intervention_date')
            ->get()
            ->filter(fn (ServiceReport $r) => $r->gestionaleValidationErrors() !== [])
            ->values();
    }

    public function table(Table $table): Table
    {
        $bloccati = static::bloccati();

        return $table
            ->query(ServiceReport::query()->whereKey($bloccati->pluck('id')->all())->orderByDesc('intervention_date'))
            ->columns([
                Tables\Columns\TextColumn::make('number')
                    ->label('Rapportino')
                    ->url(fn (ServiceReport $record) => ServiceReportResource::getUrl('view', ['record' => $record->id], tenant: $record->tenant))
                    ->color('primary'),
                Tables\Columns\TextColumn::make('intervention_date')->label('Data')->date('d/m/Y'),
                Tables\Columns\TextColumn::make('customer.company_name')
                    ->label('Cliente')
                    ->wrap()
                    ->formatStateUsing(fn (?string $state) => DisplayName::titleCase($state)),
                Tables\Columns\TextColumn::make('motivo')
                    ->label('Cosa manca')
                    ->state(fn (ServiceReport $record) => $record->gestionaleValidationErrors())
                    ->badge()
                    ->color('warning')
                    ->wrap(),
            ])
            ->paginated(false)
            ->emptyStateHeading('Nessun rapportino bloccato')
            ->emptyStateDescription('Tutti i rapportini completati si possono mandare a Eureka.');
    }
}
