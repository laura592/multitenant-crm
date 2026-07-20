<?php

namespace App\Filament\Resources\TenantResource\Pages;

use App\Filament\Resources\TenantResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditTenant extends EditRecord
{
    protected static string $resource = TenantResource::class;

    protected function getHeaderActions(): array
    {
        return [
            // Il tenant master (Alex) non va mai eliminato dal pannello: e' quello
            // dello staff che gestisce tutti gli altri partner.
            Actions\DeleteAction::make()
                ->hidden(fn () => $this->record->is_master),
        ];
    }
}
