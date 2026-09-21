<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Un invio di un'offerta caffe': a chi, quando, chi l'ha mandata. */
class OffertaCaffeEmail extends Model
{
    use HasUuids;

    protected $fillable = [
        'offerta_caffe_id',
        'user_id',
        'inviata_con',
        'recipient_email',
        'cc_email',
        'subject',
        'message',
        'status',
        'error_message',
    ];

    public function offertaCaffe(): BelongsTo
    {
        return $this->belongsTo(OffertaCaffe::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
