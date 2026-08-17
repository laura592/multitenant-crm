<?php

namespace App\Filament\Resources\MaintenanceScheduleResource\Pages;

use App\Filament\Concerns\RedirectsCancelToView;
use App\Filament\Resources\MaintenanceScheduleResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditMaintenanceSchedule extends EditRecord
{
    use RedirectsCancelToView;

    protected static string $resource = MaintenanceScheduleResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }
}
