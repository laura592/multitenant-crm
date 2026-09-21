<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Un'offerta caffe' a un cliente: i caffe' scelti, ai prezzi promessi a lui.
 *
 * Documento a parte dal preventivo della macchina, senza quantita' ne'
 * totale: quanti chili prendera' il cliente non si sa, si offre un prezzo.
 * Le righe sono una copia (nome, formato, prezzo), non un rimando al
 * listino: se il listino cambia, l'offerta continua a dire quello che e'
 * stato promesso.
 */
class OffertaCaffe extends Model
{
    use BelongsToTenant, HasUuids, SoftDeletes;

    protected $table = 'offerte_caffe';

    public const STATI = [
        'bozza' => 'Bozza',
        'inviata' => 'Inviata',
    ];

    protected $fillable = [
        'tenant_id',
        'customer_id',
        'user_id',
        'number',
        'date',
        'valida_fino',
        'righe',
        'note',
        'status',
    ];

    protected $casts = [
        'date' => 'date',
        'valida_fino' => 'date',
        'righe' => 'array',
    ];

    protected $attributes = [
        'status' => 'bozza',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $offerta) {
            $offerta->number ??= static::nextNumberForTenant($offerta->tenant_id);
            $offerta->date ??= now();
            $offerta->user_id ??= auth()->id();
        });
    }

    /** Stessa numerazione per tenant e per anno dei preventivi. */
    public static function nextNumberForTenant(?string $tenantId): string
    {
        $prefix = 'OC-'.date('Y').'-';

        $ultimo = static::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->where('number', 'like', "{$prefix}%")
            ->orderByRaw('CAST(SUBSTRING(number, -4) AS UNSIGNED) DESC')
            ->value('number');

        $prossimo = $ultimo && preg_match('/-(\d+)$/', $ultimo, $m) ? (int) $m[1] + 1 : 1;

        return $prefix.str_pad((string) $prossimo, 4, '0', STR_PAD_LEFT);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function emails(): HasMany
    {
        return $this->hasMany(OffertaCaffeEmail::class)->latest();
    }

    /** L'etichetta con cui la si sceglie da un elenco (es. allegandola a un preventivo). */
    public function etichetta(): string
    {
        $prodotti = count($this->righe ?? []);

        return "{$this->number} del {$this->date?->format('d/m/Y')} · {$prodotti} ".($prodotti === 1 ? 'prodotto' : 'prodotti');
    }
}
