<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\LogsAuditTrail;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Un appuntamento preso su una richiesta informazioni.
 *
 * Ce ne possono essere piu' d'uno aperti insieme; la richiesta tiene nelle
 * sue colonne appointment_at/appointment_notes lo specchio del prossimo,
 * per l'elenco, la stampa e i promemoria.
 */
class InformationRequestAppointment extends Model
{
    use BelongsToTenant, HasUuids, LogsAuditTrail;

    protected $fillable = ['tenant_id', 'information_request_id', 'scheduled_at', 'notes'];

    protected $casts = ['scheduled_at' => 'datetime'];

    protected static function booted(): void
    {
        $aggiorna = fn (self $a) => $a->informationRequest?->sincronizzaProssimoAppuntamento();

        static::saved($aggiorna);
        static::deleted($aggiorna);
    }

    public function informationRequest(): BelongsTo
    {
        return $this->belongsTo(InformationRequest::class);
    }
}
