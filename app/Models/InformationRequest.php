<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class InformationRequest extends Model
{
    use BelongsToTenant, HasUuids;

    protected $fillable = [
        'tenant_id',
        'customer_id',
        'number',
        'request_details',
        'status',
        'appointment_at',
        'appointment_notes',
        'handled_by_user_id',
        'source',
        'origin_url',
        'raw_payload',
        'external_id',
    ];

    protected $casts = [
        'appointment_at' => 'datetime',
        'raw_payload' => 'array',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $request) {
            if (! $request->number) {
                $request->number = static::nextNumberForTenant($request->tenant_id);
            }
        });
    }

    /**
     * Numerazione scoped per tenant (vedi docs/architecture.md §10.5).
     */
    public static function nextNumberForTenant(?string $tenantId): string
    {
        $year = date('Y');
        $prefix = "RI-{$year}-";

        $last = static::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->whereYear('created_at', $year)
            ->where('number', 'like', "{$prefix}%")
            ->orderByRaw("CAST(SUBSTRING(number, -4) AS UNSIGNED) DESC")
            ->first();

        $next = 1;
        if ($last && preg_match('/-(\d+)$/', $last->number, $matches)) {
            $next = (int) $matches[1] + 1;
        }

        return $prefix.str_pad((string) $next, 4, '0', STR_PAD_LEFT);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function products(): BelongsToMany
    {
        // ->using() non e' cosmetico: senza, sync()/attach() inseriscono con
        // il query builder e la chiave `id` della pivot resta vuota (vedi
        // InformationRequestProduct).
        return $this->belongsToMany(Product::class, 'information_request_product')
            ->using(InformationRequestProduct::class)
            ->withTimestamps();
    }

    public function handledByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'handled_by_user_id');
    }

    public function notes(): HasMany
    {
        return $this->hasMany(InformationRequestNote::class)->orderByDesc('logged_at');
    }
}
