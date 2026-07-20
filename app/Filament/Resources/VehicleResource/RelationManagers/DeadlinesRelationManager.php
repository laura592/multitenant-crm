<?php

namespace App\Filament\Resources\VehicleResource\RelationManagers;

use App\Filament\Concerns\HasDeadlinesTable;
use App\Models\Deadline;
use Filament\Resources\RelationManagers\RelationManager;

class DeadlinesRelationManager extends RelationManager
{
    use HasDeadlinesTable;

    protected static string $relationship = 'deadlines';

    protected static ?string $title = 'Altre scadenze (bollo, leasing, ...)';

    /**
     * Assicurazione e revisione si gestiscono dai campi dedicati sulla scheda
     * del veicolo (sincronizzati automaticamente): qui restano solo le
     * scadenze "extra" non coperte da un campo specifico.
     */
    protected static function excludedDeadlineTypes(): array
    {
        return [Deadline::TYPE_MANUTENZIONE_ORDINARIA, Deadline::TYPE_ASSICURAZIONE, Deadline::TYPE_REVISIONE];
    }
}
