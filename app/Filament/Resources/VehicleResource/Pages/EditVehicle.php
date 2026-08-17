<?php

namespace App\Filament\Resources\VehicleResource\Pages;

use App\Filament\Concerns\RedirectsCancelToView;
use App\Filament\Resources\VehicleResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditVehicle extends EditRecord
{
    use RedirectsCancelToView;

    protected static string $resource = VehicleResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }
}
