<?php

namespace App\Filament\Resources\UserResource\Pages;

use App\Filament\Resources\UserResource;
use Filament\Actions;
use Filament\Facades\Filament;
use Filament\Resources\Pages\EditRecord;
use Spatie\Permission\PermissionRegistrar;

class EditUser extends EditRecord
{
    protected static string $resource = UserResource::class;

    protected function mutateFormDataBeforeFill(array $data): array
    {
        $tenantId = Filament::getTenant()?->id;

        $data['role_id'] = $this->record->roles()
            ->wherePivot('tenant_id', $tenantId)
            ->value('roles.id');

        return $data;
    }

    protected function handleRecordUpdate(\Illuminate\Database\Eloquent\Model $record, array $data): \Illuminate\Database\Eloquent\Model
    {
        $roleId = $data['role_id'] ?? null;
        unset($data['role_id']);

        $record->update($data);

        $tenantId = Filament::getTenant()?->id;

        app(PermissionRegistrar::class)->setPermissionsTeamId($tenantId);

        $record->roles()->newPivotStatement()
            ->where('model_type', $record->getMorphClass())
            ->where('model_id', $record->getKey())
            ->where('tenant_id', $tenantId)
            ->delete();

        if ($roleId) {
            $record->roles()->attach($roleId, [
                'tenant_id' => $tenantId,
            ]);
        }

        return $record;
    }

    protected function getHeaderActions(): array
    {
        return [
            // Non permettere di eliminare il proprio account dalla pagina di modifica:
            // si perderebbe l'accesso al pannello senza un altro admin che lo ripristini.
            Actions\DeleteAction::make()
                ->hidden(fn () => $this->record->id === auth()->id()),
        ];
    }
}
