<?php

namespace App\Filament\Resources\UserResource\Pages;

use App\Filament\Resources\UserResource;
use Filament\Facades\Filament;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use Spatie\Permission\PermissionRegistrar;

class CreateUser extends CreateRecord
{
    protected static string $resource = UserResource::class;

    protected function handleRecordCreation(array $data): Model
    {
        $roleId = $data['role_id'] ?? null;
        unset($data['role_id']);

        $record = static::getModel()::create($data);

        if ($roleId) {
            $tenantId = Filament::getTenant()?->id;

            app(PermissionRegistrar::class)->setPermissionsTeamId($tenantId);

            $record->roles()->attach($roleId, [
                'tenant_id' => $tenantId,
            ]);
        }

        return $record;
    }
}
