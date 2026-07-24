<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\SharedAcrossTenants;
use Spatie\Activitylog\Models\Activity;

/**
 * Sottoclasse del modello Activity di spatie/laravel-activitylog (Epic 6,
 * ticket 6.1/6.2: audit log generico su tenant/prodotti/clienti/fornitori/
 * utenti, esclusi Quote/QuoteProduct che restano di dominio di un'altra
 * sessione di lavoro).
 *
 * Riusa BelongsToTenant/SharedAcrossTenants (stessa convenzione del resto
 * dell'app) solo per lo scoping in LETTURA: la colonna tenant_id qui non e'
 * mai popolata dal creating-hook di BelongsToTenant (che dipende dal tenant
 * corrente di Filament), ma da App\Providers\AuditLogServiceProvider, che
 * intercetta ogni riga PRIMA del salvataggio e la valorizza in base al
 * soggetto tracciato (vedi tapTenantId()) - l'hook di BelongsToTenant resta
 * comunque come fallback innocuo per soggetti non esplicitamente gestiti.
 * tenant_id NULL = riga su un record del catalogo condiviso (Product/
 * Material con tenant_id NULL) o senza soggetto risolvibile: visibile a
 * tutti i tenant, come da convenzione SharedAcrossTenants.
 */
class AuditLog extends Activity
{
    use BelongsToTenant, SharedAcrossTenants;

    protected $table = 'activity_log';

    /**
     * Etichette italiane per i modelli tracciati, usate in tabella/filtri
     * della Resource Filament invece del solo nome classe.
     *
     * @return array<class-string, string>
     */
    public static function subjectLabels(): array
    {
        return [
            Tenant::class => 'Partner (tenant)',
            Customer::class => 'Cliente',
            Product::class => 'Prodotto',
            ProductPrice::class => 'Prezzo prodotto',
            Material::class => 'Materiale',
            Supplier::class => 'Fornitore',
            User::class => 'Utente',
        ];
    }

    public function subjectLabel(): string
    {
        if (! $this->subject_type) {
            return '—';
        }

        return static::subjectLabels()[$this->subject_type] ?? class_basename($this->subject_type);
    }

    /**
     * Determina il tenant_id da attribuire alla riga di audit in base al
     * soggetto tracciato. Chiamato da AuditLogServiceProvider prima del
     * salvataggio (vedi Spatie\Activitylog\Actions\LogActivityAction::
     * beforeLogging), quando $subject e' gia' l'istanza in memoria (nessuna
     * query aggiuntiva per i casi comuni).
     */
    public static function resolveTenantIdForSubject(?object $subject): ?string
    {
        return match (true) {
            $subject === null => null,
            $subject instanceof Tenant => $subject->id,
            $subject instanceof ProductPrice => $subject->product?->tenant_id,
            default => $subject->tenant_id ?? null,
        };
    }
}
