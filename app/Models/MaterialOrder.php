<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class MaterialOrder extends Model
{
    use BelongsToTenant, HasUuids;

    protected $fillable = [
        'tenant_id',
        'supplier_id',
        'number',
        'notes',
        'status',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $order) {
            if (! $order->number) {
                $order->number = static::nextNumberForTenant($order->tenant_id);
            }
        });
    }

    public function items(): HasMany
    {
        return $this->hasMany(MaterialOrderItem::class);
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function emails(): HasMany
    {
        return $this->hasMany(MaterialOrderEmail::class);
    }

    /**
     * Numerazione scoped per tenant, stesso schema di Quote::nextNumberForTenant.
     */
    public static function nextNumberForTenant(?string $tenantId): string
    {
        $year = date('Y');
        $prefix = "MAT-{$year}-";

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
}
