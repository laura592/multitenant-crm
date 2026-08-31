<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Un macchinario fisico con matricola, tracciato indipendentemente da dove si
 * trova fisicamente in questo momento (current_customer_id, es. un bar
 * diverso) e da chi paga (billing_customer_id, se diverso da chi lo ospita).
 * Lo storico degli spostamenti vive in MachineUnitPlacement — vedi moveTo().
 */
class MachineUnit extends Model
{
    use BelongsToTenant, HasUuids, SoftDeletes;

    public const STATUS_IN_MAGAZZINO = 'in_magazzino';

    public const STATUS_INSTALLATA = 'installata';

    public const STATUS_RIMOSSA = 'rimossa';

    public const SOURCE_MANUALE = 'manuale';

    public const SOURCE_EUREKA = 'eureka';

    public const TYPE_COLONNA_SPINA = 'colonna_spina';

    public const TYPE_IMPIANTO_ACQUA = 'impianto_acqua';

    protected $fillable = [
        'tenant_id',
        'source',
        'product_id',
        'material_id',
        'current_customer_id',
        'billing_customer_id',
        'serial_number',
        'model_name',
        'type',
        'status',
        'notes',
        'gestionale_code',
        'gestionale_suggested_code',
        'gestionale_suggested_label',
        'eureka_billing_customer_code',
    ];

    protected $attributes = [
        'status' => self::STATUS_IN_MAGAZZINO,
    ];

    protected static function booted(): void
    {
        // La FK cascadeOnDelete() del DB non scatta piu' su un soft delete
        // (e' un UPDATE, non una DELETE): replichiamo la cascata a mano sullo
        // storico posizionamenti.
        static::deleting(function (self $unit) {
            $unit->placements->each->delete();
        });
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /**
     * L'articolo Eureka da cui nasce questa matricola: e' la stessa anagrafica
     * che il rapportino referenzia in machine_material_id. product() resta per
     * le macchine a listino, quelle che vendiamo e configuriamo a preventivo.
     */
    public function material(): BelongsTo
    {
        return $this->belongsTo(Material::class);
    }

    public function currentCustomer(): BelongsTo
    {
        return $this->belongsTo(Customer::class, 'current_customer_id');
    }

    public function billingCustomer(): BelongsTo
    {
        return $this->belongsTo(Customer::class, 'billing_customer_id');
    }

    public function placements(): HasMany
    {
        return $this->hasMany(MachineUnitPlacement::class)->latest('placed_at');
    }

    public function getDisplayNameAttribute(): string
    {
        return $this->product?->name
            ?? $this->material?->display_label
            ?? $this->model_name
            ?? 'Macchina senza modello';
    }

    /**
     * Chiude l'eventuale posizionamento aperto e ne apre uno nuovo (o
     * nessuno, se $customer e' null = rientro in magazzino/rimozione),
     * mantenendo lo storico invece di sovrascrivere current_customer_id e
     * basta. $placedAt di default e' now() (spostamento fatto ora dal
     * tecnico), ma un import storico (es. da Eureka, dove la data vera e'
     * quella del DDT di consegna/installazione) puo' passare la data reale
     * invece di intestare tutto a "oggi".
     */
    public function moveTo(?Customer $customer, ?string $notes = null, ?\DateTimeInterface $placedAt = null): void
    {
        $this->placements()->whereNull('removed_at')->update(['removed_at' => now()]);

        if ($customer) {
            $this->placements()->create([
                'tenant_id' => $this->tenant_id,
                'customer_id' => $customer->id,
                'placed_at' => $placedAt ?? now(),
                'notes' => $notes,
            ]);
        }

        $this->update([
            'current_customer_id' => $customer?->id,
            'status' => $customer ? self::STATUS_INSTALLATA : self::STATUS_IN_MAGAZZINO,
        ]);
    }

    /**
     * Accetta la proposta di collegamento trovata dal sync
     * (GestionaleSyncRunner::proposeMachineUnitLinks()).
     *
     * Marca la macchina come proveniente da Eureka invece di scrivere
     * gestionale_code: la proposta nasce da /show/q/art_installati, che
     * espone l'id dell'ARTICOLO e non l'id matricola (M14) che quella colonna
     * contiene ("id m14 (matricola) su Eureka", vedi la migration). L'id
     * matricola si leggeva da /crm_api/m14/search, che dal 2026-08-27
     * risponde 403 perche' il modulo `crm` non e' abilitato sulle nostre
     * credenziali. Scriverci dentro l'id articolo sarebbe un dato sbagliato
     * in una colonna dal significato preciso.
     *
     * source=eureka e' comunque il segnale che conta davvero: e' quello a
     * decidere l'invio di sl_matricola (ServiceReport::toGestionalePayload())
     * e chi legge gestionale_code lo tratta gia' come equivalente
     * (MachineUnitResource::table(), ServiceReportResource::isMachineUnitLinkedToEureka()).
     *
     * Il prodotto si collega solo se non ce n'e' gia' uno: la proposta porta
     * l'articolo Eureka della matricola, utile per le macchine importate
     * senza prodotto, ma non deve sovrascrivere una scelta gia' fatta a mano.
     */
    public function confermaCollegamentoEureka(): void
    {
        $product = $this->product_id === null && $this->gestionale_suggested_code !== null
            ? Product::query()->where('gestionale_code', $this->gestionale_suggested_code)->first()
            : null;

        $this->update([
            'source' => self::SOURCE_EUREKA,
            'product_id' => $product->id ?? $this->product_id,
            'gestionale_suggested_code' => null,
            'gestionale_suggested_label' => null,
        ]);
    }
}
