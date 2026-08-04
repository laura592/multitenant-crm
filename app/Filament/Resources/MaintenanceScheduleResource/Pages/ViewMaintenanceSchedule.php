<?php

namespace App\Filament\Resources\MaintenanceScheduleResource\Pages;

use App\Filament\Resources\MaintenanceScheduleResource;
use Filament\Actions;
use Filament\Resources\Pages\ViewRecord;

class ViewMaintenanceSchedule extends ViewRecord
{
    protected static string $resource = MaintenanceScheduleResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\EditAction::make(),
        ];
    }
}
