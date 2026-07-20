<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Un macchinario fisico con matricola, tracciato indipendentemente da chi lo
 * possiede legalmente (owner_name, es. "Dersut") e da dove si trova
 * fisicamente in questo momento (current_customer_id, es. un bar diverso).
 * Lo storico degli spostamenti vive in MachineUnitPlacement — vedi moveTo().
 */
class MachineUnit extends Model
{
    use BelongsToTenant, HasUuids;

    public const STATUS_IN_MAGAZZINO = 'in_magazzino';

    public const STATUS_INSTALLATA = 'installata';

    public const STATUS_RIMOSSA = 'rimossa';

    protected $fillable = [
        'tenant_id',
        'product_id',
        'current_customer_id',
        'serial_number',
        'model_name',
        'owner_name',
        'status',
        'notes',
    ];

    protected $attributes = [
        'status' => self::STATUS_IN_MAGAZZINO,
    ];

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function currentCustomer(): BelongsTo
    {
        return $this->belongsTo(Customer::class, 'current_customer_id');
    }

    public function placements(): HasMany
    {
        return $this->hasMany(MachineUnitPlacement::class)->latest('placed_at');
    }

    public function getDisplayNameAttribute(): string
    {
        return $this->product?->name ?? $this->model_name ?? 'Macchina senza modello';
    }

    /**
     * Chiude l'eventuale posizionamento aperto e ne apre uno nuovo (o
     * nessuno, se $customer e' null = rientro in magazzino/rimozione),
     * mantenendo lo storico invece di sovrascrivere current_customer_id e
     * basta.
     */
    public function moveTo(?Customer $customer, ?string $notes = null): void
    {
        $this->placements()->whereNull('removed_at')->update(['removed_at' => now()]);

        if ($customer) {
            $this->placements()->create([
                'tenant_id' => $this->tenant_id,
                'customer_id' => $customer->id,
                'placed_at' => now(),
                'notes' => $notes,
            ]);
        }

        $this->update([
            'current_customer_id' => $customer?->id,
            'status' => $customer ? self::STATUS_INSTALLATA : self::STATUS_IN_MAGAZZINO,
        ]);
    }
}
