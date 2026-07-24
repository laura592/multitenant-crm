<?php

namespace App\Filament\Resources\LeaveRequestResource\Pages;

use App\Filament\Resources\LeaveRequestResource;
use Filament\Resources\Pages\CreateRecord;

class CreateLeaveRequest extends CreateRecord
{
    protected static string $resource = LeaveRequestResource::class;

    /**
     * Il campo user_id e' disabled() lato UI per chi non e' responsabile/
     * amministrazione, ma disabled() non impedisce di forgiare un user_id
     * diverso nel payload della richiesta: qui lo si riscrive lato server.
     */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $user = auth()->user();

        if (! LeaveRequestResource::isResponsabile($user) && ! $user->hasRole('amministrazione')) {
            $data['user_id'] = $user->id;
        }

        return $data;
    }
}
