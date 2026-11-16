<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Un invio del rendiconto di una lettura, riuscito o fallito. */
class LetturaCasettaEmail extends Model
{
    use HasUuids;

    protected $table = 'lettura_casetta_emails';

    protected $fillable = [
        'lettura_casetta_id', 'user_id', 'recipient_email', 'cc_email',
        'subject', 'message', 'status', 'error_message',
    ];

    public function letturaCasetta(): BelongsTo
    {
        return $this->belongsTo(LetturaCasetta::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
