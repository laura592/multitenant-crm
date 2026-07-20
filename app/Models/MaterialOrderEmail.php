<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MaterialOrderEmail extends Model
{
    use HasUuids;

    protected $fillable = [
        'material_order_id',
        'user_id',
        'recipient_email',
        'cc_email',
        'subject',
        'message',
        'status',
        'error_message',
    ];

    public function materialOrder(): BelongsTo
    {
        return $this->belongsTo(MaterialOrder::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
