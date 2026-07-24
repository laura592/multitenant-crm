<?php

namespace App\Filament\Resources\VehicleResource\RelationManagers;

use App\Filament\Concerns\HasDeadlinesTable;
use Filament\Resources\RelationManagers\RelationManager;

class DeadlinesRelationManager extends RelationManager
{
    use HasDeadlinesTable;

    protected static string $relationship = 'deadlines';

    protected static ?string $title = 'Scadenze (assicurazione, bollo, revisione, leasing, ...)';
}
