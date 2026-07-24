<?php

namespace App\Models\Concerns;

use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * Wrapper sopra il trait LogsActivity di spatie/laravel-activitylog (Epic 6,
 * ticket 6.1/6.2) con le impostazioni di default scelte per questo repo:
 * - logOnlyDirty(): solo i campi realmente cambiati in un update, non ogni
 *   "touch" (es. un save() senza modifiche non genera rumore);
 * - dontLogEmptyChanges(): se dopo il filtro sopra non resta nulla di
 *   davvero cambiato, niente riga di audit;
 * - logFillable(): traccia i campi assegnabili in massa del modello (stesso
 *   perimetro dei form Filament), non i timestamp o colonne tecniche.
 *
 * Un modello puo' sovrascrivere getActivitylogOptions() per restringere
 * ulteriormente (es. User esclude "password").
 */
trait LogsAuditTrail
{
    use LogsActivity;

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logFillable()
            ->logOnlyDirty()
            ->dontLogEmptyChanges();
    }
}
