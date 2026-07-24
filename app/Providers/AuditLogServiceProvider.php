<?php

namespace App\Providers;

use App\Models\AuditLog;
use Illuminate\Support\ServiceProvider;
use Spatie\Activitylog\Actions\LogActivityAction;
use Spatie\Activitylog\Contracts\Activity;

/**
 * Epic 6 (ticket 6.2): valorizza activity_log.tenant_id PRIMA che la riga
 * venga salvata, cosi' la Resource Filament di sola lettura (AuditLogResource)
 * puo' filtrare per tenant come ogni altra risorsa scoped (vedi
 * App\Models\Concerns\BelongsToTenant).
 *
 * beforeLogging() e' un hook globale del package (Spatie\Activitylog\Actions\
 * LogActivityAction), eseguito per OGNI riga registrata da un modello con il
 * trait LogsActivity, indipendentemente da quale modello sia: a quel punto
 * $activity->subject e' gia' l'istanza in memoria del record tracciato
 * (associata da performedOn() prima del log), quindi risolvere il tenant_id
 * non costa una query aggiuntiva per i casi comuni.
 */
class AuditLogServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        LogActivityAction::beforeLogging(function (Activity $activity) {
            $activity->tenant_id = AuditLog::resolveTenantIdForSubject($activity->subject);
        });
    }
}
