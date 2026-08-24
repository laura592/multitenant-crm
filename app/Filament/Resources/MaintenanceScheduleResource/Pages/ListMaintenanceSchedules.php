<?php

namespace App\Filament\Resources\MaintenanceScheduleResource\Pages;

use App\Filament\Resources\MaintenanceScheduleResource;
use App\Filament\Widgets\UpcomingMaintenanceWidget;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListMaintenanceSchedules extends ListRecords
{
    protected static string $resource = MaintenanceScheduleResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make()
                ->extraAttributes(['data-tour' => 'maintenance-schedules-create']),
        ];
    }

    // Stessa "Piani in scadenza" gia' in Dashboard: qui il tecnico la vede
    // gia' aperto sulla lista piani, senza dover tornare in Dashboard per
    // sapere cosa fare per primo.
    protected function getHeaderWidgets(): array
    {
        return [
            UpcomingMaintenanceWidget::class,
        ];
    }
}
