<?php

namespace App\Models;

use App\Models\Concerns\LogsAuditTrail;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

class QuoteProduct extends Model
{
    use HasUuids, LogsAuditTrail, SoftDeletes;

    protected $fillable = [
        'quote_id',
        'product_id',
        'parent_quote_product_id',
        'quantity',
        'price',
        'discount',
        'tax',
        'total',
        'contratto_assistenza',
    ];

    protected $casts = [
        'quantity' => 'decimal:2',
        'price' => 'decimal:2',
        'discount' => 'integer',
        'tax' => 'integer',
        'total' => 'decimal:2',
    ];

    public function quote(): BelongsTo
    {
        return $this->belongsTo(Quote::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(QuoteProduct::class, 'parent_quote_product_id');
    }

    public function options(): HasMany
    {
        return $this->hasMany(QuoteProduct::class, 'parent_quote_product_id');
    }

    public function isBase(): bool
    {
        return is_null($this->parent_quote_product_id);
    }

    public function isOption(): bool
    {
        return ! is_null($this->parent_quote_product_id);
    }

    public function getTotalWithOptions(): float
    {
        return (float) $this->total + (float) $this->options->sum('total');
    }

    /**
     * Mettere una macchina in un preventivo vuol dire che di quella si
     * stava parlando: il prodotto torna indietro sulla richiesta che ha
     * generato il preventivo, fra i "prodotti di interesse".
     *
     * Quel campo esisteva da sempre ma restava vuoto -- 54 richieste su
     * 316 -- perche' era una select su tutto il catalogo da riempire a
     * mano, dopo aver gia' scritto la stessa cosa nel preventivo. Nessuno
     * ricopia due volte lo stesso dato (Laura, 04/11/2026).
     *
     * Solo le righe base: le opzioni sono accessori di una macchina che
     * e' gia' nell'elenco. E solo in aggiunta, mai in sostituzione, per
     * non cancellare una scelta fatta a mano.
     */
    protected static function booted(): void
    {
        static::created(function (self $riga): void {
            if (! $riga->diceCosaInteressa()) {
                return;
            }

            /** @var \App\Models\Quote|null $preventivo */
            $preventivo = $riga->quote;
            /** @var \App\Models\InformationRequest|null $richiesta */
            $richiesta = $preventivo?->informationRequest;

            $richiesta?->products()->syncWithoutDetaching([$riga->product_id]);
        });
    }

    /**
     * Se questa riga dice di cosa il cliente era interessato.
     *
     * Di un preventivo da sei righe, quelle che rispondono alla domanda
     * "cosa voleva?" sono due: la macchina e il depuratore. Le altre --
     * installazione, trasporto, l'accessorio che va per forza con quella
     * macchina -- sono conseguenze della scelta, non la scelta. Messe fra
     * i "prodotti di interesse" allungano l'elenco e lo rendono illeggibile,
     * che e' il modo in cui un campo pieno torna a essere inutile come uno
     * vuoto.
     *
     * Il taglio e' sulla categoria perche' e' li' che il catalogo gia'
     * separa le due cose: "Opzioni Alex", "Accessori Franke".
     */
    public function diceCosaInteressa(): bool
    {
        if ($this->parent_quote_product_id !== null || blank($this->product_id)) {
            return false;
        }

        /** @var \App\Models\Product|null $prodotto */
        $prodotto = $this->product;
        /** @var \App\Models\Category|null $categoria */
        $categoria = $prodotto?->category;

        return ! Str::startsWith((string) $categoria?->name, ['Opzioni', 'Accessori']);
    }
}
