<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\SharedAcrossTenants;
use Filament\Facades\Filament;
use Filament\Resources\Resource;
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
            // Dal 04/09/2026: quello su cui lavorano i tecnici. Prima un
            // rapportino corretto o un'ora ritoccata non lasciavano traccia.
            ServiceReport::class => 'Rapportino',
            ServiceReportMaterial::class => 'Riga materiale (rapportino)',
            ServiceReportProduct::class => 'Riga ricambio (rapportino)',
            MachineUnit::class => 'Macchina',
            MachineUnitPlacement::class => 'Collocazione macchina',
            MaintenanceSchedule::class => 'Piano manutenzione',
            Lavaggio::class => 'Lavaggio',
            MaterialOrder::class => 'Ordine materiali',
            MaterialOrderItem::class => 'Riga ordine materiali',
            TimeEntry::class => 'Timbratura',
            LeaveRequest::class => 'Richiesta ferie/permesso',
            InformationRequest::class => 'Richiesta informazioni',
            InformationRequestNote::class => 'Nota richiesta informazioni',
            CustomerDocument::class => 'Documento del cliente',
            InterventoProgrammato::class => 'Intervento programmato',
            // Dal 30/09/2026: i preventivi non lasciavano traccia di chi
            // cambiava un prezzo o uno stato, che e' esattamente il tipo di
            // domanda che arriva dopo.
            Quote::class => 'Preventivo',
            QuoteProduct::class => 'Riga preventivo',
            QuoteGroup::class => 'Gruppo di preventivi',
            Deadline::class => 'Scadenza',
            Vehicle::class => 'Veicolo',
            PriceList::class => 'Listino',
        ];
    }

    /**
     * Link alla scheda del record toccato, quando esiste una Resource che lo
     * sa mostrare: dall'audit si deve poter arrivare al dato, altrimenti
     * "chi ha cambiato cosa" resta una notizia e non una cosa su cui agire.
     * Per le righe figlie (una riga materiale, una collocazione) si apre il
     * documento che le contiene, che e' l'unico posto dove si vedono.
     */
    public function urlRecord(): ?string
    {
        $subject = $this->subject;

        if (! $subject) {
            return null;
        }

        $apribile = match (true) {
            $subject instanceof ServiceReportMaterial,
            $subject instanceof ServiceReportProduct => $subject->serviceReport,
            $subject instanceof MaterialOrderItem => $subject->order,
            $subject instanceof MachineUnitPlacement => $subject->machineUnit,
            $subject instanceof InformationRequestNote => $subject->informationRequest,
            $subject instanceof ProductPrice => $subject->product,
            $subject instanceof Lavaggio => $subject->machineUnit,
            default => $subject,
        };

        if (! $apribile) {
            return null;
        }

        $panel = Filament::getCurrentPanel();

        if (! $panel) {
            return null;
        }

        foreach ($panel->getResources() as $resource) {
            /** @var class-string<Resource> $resource */
            if ($resource::getModel() !== $apribile::class) {
                continue;
            }

            $pagina = collect(['view', 'edit', 'index'])
                ->first(fn (string $nome) => array_key_exists($nome, $resource::getPages()));

            if (! $pagina) {
                return null;
            }

            try {
                return $resource::getUrl(
                    $pagina,
                    $pagina === 'index' ? [] : ['record' => $apribile->getKey()],
                    panel: $panel->getId(),
                    tenant: self::tenantPerUrl($apribile->getAttribute('tenant_id')),
                );
            } catch (\Throwable) {
                return null;
            }
        }

        return null;
    }

    private static function stringaONull(mixed $valore): ?string
    {
        return is_string($valore) && $valore !== '' ? $valore : null;
    }

    /** @var array<string, ?Tenant> */
    private static array $tenantiPerUrl = [];

    private static function tenantPerUrl(?string $tenantId): ?Tenant
    {
        $corrente = Filament::getTenant();
        $corrente = $corrente instanceof Tenant ? $corrente : null;

        if (! $tenantId || $corrente?->getKey() === $tenantId) {
            return $corrente;
        }

        if (! array_key_exists($tenantId, self::$tenantiPerUrl)) {
            $tenant = Tenant::query()->find($tenantId);
            self::$tenantiPerUrl[$tenantId] = $tenant instanceof Tenant ? $tenant : null;
        }

        return self::$tenantiPerUrl[$tenantId];
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
            // Le righe figlie non hanno tenant_id proprio: senza questi tre
            // casi finirebbero a NULL, che per SharedAcrossTenants significa
            // "catalogo condiviso" — cioe' la riga di un rapportino di un
            // partner leggibile dall'audit di tutti gli altri.
            $subject instanceof ServiceReportMaterial,
            $subject instanceof ServiceReportProduct => $subject->serviceReport?->tenant_id,
            $subject instanceof MaterialOrderItem => $subject->order?->tenant_id,
            $subject instanceof QuoteProduct => self::stringaONull($subject->quote?->getAttribute('tenant_id')),
            default => $subject->tenant_id ?? null,
        };
    }
}
