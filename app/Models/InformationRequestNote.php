<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Diario libero della richiesta (es. "mandata mail con listino il 20/08",
 * "chiamato, non risponde"): a differenza di appointment_at (un solo
 * prossimo appuntamento programmato), qui si accumula lo storico di cosa
 * e' gia' stato fatto, una riga per contatto.
 */
class InformationRequestNote extends Model
{
    use BelongsToTenant, HasUuids;

    protected $fillable = [
        'tenant_id',
        'information_request_id',
        'logged_at',
        'body',
    ];

    protected $casts = [
        'logged_at' => 'date',
    ];

    public function informationRequest(): BelongsTo
    {
        return $this->belongsTo(InformationRequest::class);
    }
}
