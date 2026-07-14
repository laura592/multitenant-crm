<?php

namespace App\Filament\Resources\TenantResource\RelationManagers;

use App\Filament\Concerns\HasDeadlinesTable;
use Filament\Resources\RelationManagers\RelationManager;

class DeadlinesRelationManager extends RelationManager
{
    use HasDeadlinesTable;

    protected static string $relationship = 'deadlines';

    protected static ?string $title = 'Scadenze (polizza RCT art. 17, rinnovo contratto art. 13, ...)';
}
