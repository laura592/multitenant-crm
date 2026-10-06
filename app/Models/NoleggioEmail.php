<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Un invio del contratto di noleggio, riuscito o fallito. */
class NoleggioEmail extends Model
{
    use HasUuids;

    protected $table = 'noleggio_emails';

    protected $fillable = [
        'noleggio_id', 'user_id', 'recipient_email', 'cc_email',
        'subject', 'message', 'status', 'error_message',
    ];

    public function noleggio(): BelongsTo
    {
        return $this->belongsTo(Noleggio::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
