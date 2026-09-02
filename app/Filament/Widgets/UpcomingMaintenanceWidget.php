<?php

namespace App\Filament\Widgets;

use App\Filament\Resources\MaintenanceScheduleResource;
use App\Models\MaintenanceSchedule;
use Filament\Widgets\Widget;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;

class UpcomingMaintenanceWidget extends Widget
{
    // A differenza di UpcomingDeadlinesWidget e LatestQuotesWidget (colSpan
    // 1, affiancate sulla dashboard): questa lista tende ad avere piu' righe
    // utili delle altre due, quindi occupa tutta la riga. Niente
    // Tables\Table qui (era un TableWidget): il contentGrid() di Filament
    // interlaccia i record riga per riga fra le colonne (1-2-3-4 sparsi su 2
    // colonne), mentre qui servono due liste sequenziali separate (1-5 a
    // sinistra, 6-10 a destra) - da qui la vista custom.
    // Chiude la sezione "Da fare adesso".
    protected static ?int $sort = 3;

    public static function canView(): bool
    {
        return Auth::user()->can('view_any_maintenance::schedule');
    }

    protected int|string|array $columnSpan = 'full';

    protected static string $view = 'filament.widgets.upcoming-maintenance-widget';

    protected int $perColumn = 5;

    public function getSchedules(): Collection
    {
        // Nessun limite superiore sulla data: i piani gia' scaduti
        // (next_due_date passata) devono comparire per primi, non sparire
        // dalla vista solo perche' sono oltre i "prossimi 30 giorni".
        return MaintenanceSchedule::query()
            ->where('status', MaintenanceSchedule::STATUS_ATTIVO)
            ->whereNotNull('next_due_date')
            ->with('customer')
            ->orderBy('next_due_date')
            ->limit($this->perColumn * 2)
            ->get();
    }

    /** @return array{0: Collection, 1: Collection} */
    public function getColumnsSplit(): array
    {
        $schedules = $this->getSchedules();

        return [
            $schedules->slice(0, $this->perColumn)->values(),
            $schedules->slice($this->perColumn)->values(),
        ];
    }

    public function impiantoHero(MaintenanceSchedule $record): string
    {
        return MaintenanceScheduleResource::impiantoHero($record);
    }

    public function beverageColor(MaintenanceSchedule $record): string
    {
        return MaintenanceScheduleResource::beverageColors()[$record->beverage_type] ?? 'gray';
    }

    public function recordUrl(MaintenanceSchedule $record): string
    {
        return MaintenanceScheduleResource::getUrl('view', ['record' => $record], tenant: $record->tenant);
    }
}
