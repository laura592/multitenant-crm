<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class QuoteGroupEmail extends Model
{
    use HasUuids, SoftDeletes;

    protected $fillable = [
        'quote_group_id',
        'user_id',
        'recipient_email',
        'cc_email',
        'subject',
        'message',
        'status',
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
