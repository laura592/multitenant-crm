<?php

namespace App\Filament\Resources\TenantResource\RelationManagers;

use App\Filament\Concerns\HasDeadlinesTable;
use App\Models\Tenant;
use Filament\Resources\RelationManagers\RelationManager;
use Illuminate\Database\Eloquent\Model;

class DeadlinesRelationManager extends RelationManager
{
    use HasDeadlinesTable;

    protected static string $relationship = 'deadlines';

    protected static ?string $title = 'Scadenze (polizza RCT art. 17, rinnovo contratto art. 13, ...)';

    // Scadenze contrattuali dei tenant partner (polizza RCT, rinnovo
    // contratto di distribuzione, ...): Alex e' il tenant master, non ha un
    // contratto di distribuzione con se stesso, quindi non ha senso mostrare
    // questa tab sulla sua scheda.
    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return ! ($ownerRecord instanceof Tenant && $ownerRecord->is_master);
    }
}
