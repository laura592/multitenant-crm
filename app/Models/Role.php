<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Permission\Models\Role as SpatieRole;

/**
 * Estende il Role di spatie/permission solo per aggiungere tenant():
 * richiesta dallo scoping automatico di Filament per il RoleResource di
 * Shield nel pannello tenant-aware (il team_foreign_key e' gia' "tenant_id",
 * §5.3 di docs/architecture.md, qui serve solo la relazione Eloquent).
 */
class Role extends SpatieRole
{
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }
}
