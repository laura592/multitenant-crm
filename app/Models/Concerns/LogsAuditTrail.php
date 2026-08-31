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
 *
 * Registra SOLO le modifiche fatte da una persona (2026-08-31). L'audit
 * trail serve a rispondere a "chi ha cambiato questo dato": una riga senza
 * utente non risponde a niente. La sincronizzazione notturna con Eureka ne
 * produceva a migliaia — 16.229 righe anonime su 16.305, e activity_log era
 * arrivato a 13,2 MB su 35,7 di database, piu' di clienti, rapportini e
 * macchine messi insieme.
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

    /**
     * Senza un utente autenticato non si registra nulla.
     *
     * Import, sincronizzazioni e comandi di allineamento cambiano migliaia di
     * record senza che nessuna persona abbia deciso niente: quelle righe non
     * ricostruiscono nessuna responsabilita', e sommergono le poche che lo
     * fanno. Cosa e' cambiato lo dicono gia' i log dei comandi.
     *
     * Il controllo e' sull'utente e non su runningInConsole(): un job in coda
     * lanciato da una persona ha comunque un causer, e i test — che girano in
     * console ma con actingAs() — devono continuare a vedere l'audit.
     */
    public function shouldLogEvent(string $eventName): bool
    {
        return auth()->hasUser();
    }
}
