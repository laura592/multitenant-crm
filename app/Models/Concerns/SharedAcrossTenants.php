<?php

namespace App\Models\Concerns;

/**
 * Marker trait: righe con tenant_id NULL sono visibili a tutti i tenant
 * (catalogo condiviso, §4.2/§11.2 di docs/architecture.md). Va usato insieme
 * a BelongsToTenant, non da solo.
 */
trait SharedAcrossTenants
{
    //
}
