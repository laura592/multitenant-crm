<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Traccia quali tour guidati (vedi App\Livewire\TourGuide) un utente ha
 * gia' visto, per farli partire da soli solo alla prima visita di ogni
 * pagina — una riga per utente+pagina, mai ripetuta (unique su
 * user_id+page_slug in migration).
 */
class TourView extends Model
{
    use BelongsToTenant, HasUuids;

    protected $fillable = [
        'tenant_id',
        'user_id',
        'page_slug',
        'viewed_at',
    ];

    protected $casts = [
        'viewed_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
