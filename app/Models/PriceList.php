<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\SharedAcrossTenants;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PriceList extends Model
{
    use BelongsToTenant, HasUuids, SharedAcrossTenants;

    protected $fillable = [
        'tenant_id',
        'supplier_id',
        'name',
        'valid_from',
        'valid_to',
        'file_path',
        'notes',
    ];

    protected $casts = [
        'valid_from' => 'date',
        'valid_to' => 'date',
    ];

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    /**
     * Nessuna data = validita' aperta (listino tuttora in vigore, senza una
     * scadenza nota). Usato per il badge di stato in tabella.
     */
    public function status(): string
    {
        $today = now()->startOfDay();

        if ($this->valid_from && $today->lt($this->valid_from)) {
            return 'futuro';
        }

        if ($this->valid_to && $today->gt($this->valid_to)) {
            return 'scaduto';
        }

        return 'in_corso';
    }
}
