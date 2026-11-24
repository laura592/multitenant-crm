<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * "Questo mezzo non ha questo documento, e il motivo e' questo."
 *
 * Nasce per il bollo del Vito HG747AP, compreso nel canone di leasing
 * Mercedes-Benz Financial (24/11/2026). Senza una riga come questa l'unico
 * modo di far smettere l'avviso sarebbe inventare una scadenza finta, con
 * una data che non vuol dire niente e che fra un anno qualcuno rinnoverebbe.
 *
 * Il motivo e' obbligatorio di proposito: un'esenzione senza spiegazione, fra
 * dodici mesi, e' indistinguibile da una dimenticanza.
 */
class VehicleDeadlineExemption extends Model
{
    use HasUuids;

    protected $table = 'vehicle_deadline_exemptions';

    protected $fillable = [
        'vehicle_id',
        'type',
        'motivo',
    ];

    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class);
    }

    public function etichettaTipo(): string
    {
        return Deadline::typeLabels()[$this->type] ?? $this->type;
    }
}
