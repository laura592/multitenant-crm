<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * La colonna `tipo` si dichiara qui perche' l'analisi statica non riesce a
 * leggerla: la migrazione 2026_11_27_190000 la aggiunge dentro un foreach
 * sulle due tabelle delle mail, e larastan ricava le colonne leggendo le
 * migrazioni — un nome di tabella che non e' una stringa letterale non lo
 * vede.
 *
 * @property string $tipo
 */
class QuoteGroupEmail extends Model
{
    use HasUuids, SoftDeletes;

    /** L'invio vero e proprio del preventivo o dell'offerta. */
    public const TIPO_PREVENTIVO = 'preventivo';

    /**
     * La mail che chiede un si' o un no su qualcosa di gia' mandato. Non
     * conta come "ultimo invio": vedi la migrazione 2026_11_27_190000.
     */
    public const TIPO_SOLLECITO = 'sollecito';

    protected $fillable = [
        'quote_group_id',
        'user_id',
        'recipient_email',
        'cc_email',
        'subject',
        'message',
        'status',
        'tipo',
        'error_message',
    ];

    public function quoteGroup(): BelongsTo
    {
        return $this->belongsTo(QuoteGroup::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
