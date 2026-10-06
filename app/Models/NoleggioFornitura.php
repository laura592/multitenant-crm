<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Una voce compresa nel canone: tot kg di caffe', tot pastiglie, tot cacao.
 *
 * Il costo mensile si ricalcola a ogni salvataggio da quantita', prezzo e
 * ricarico, come il canone: un prezzo scritto a mano si scollega dai numeri
 * che lo giustificano al primo aggiornamento del listino.
 */
class NoleggioFornitura extends Model
{
    use HasUuids;

    protected $table = 'noleggio_forniture';

    public const GRUPPO_CAFFE = 'caffe';

    public const GRUPPO_POLVERI = 'polveri';

    public const GRUPPO_DETERGENTI = 'detergenti';

    protected $fillable = [
        'noleggio_id', 'voce', 'prodotto_caffe_id', 'material_id', 'gruppo',
        'quantita', 'unita', 'prezzo_unitario', 'ricarico', 'costo_mensile',
        'note', 'ordine',
    ];

    protected $casts = [
        'quantita' => 'decimal:3',
        'prezzo_unitario' => 'decimal:4',
        'ricarico' => 'decimal:2',
        'costo_mensile' => 'decimal:2',
        'ordine' => 'integer',
    ];

    /**
     * Da dove arriva la voce. Nulle quando e' scritta a mano: i detergenti
     * che si usano davvero non stanno in nessun listino.
     */
    public function prodottoCaffe(): BelongsTo
    {
        return $this->belongsTo(ProdottoCaffe::class);
    }

    public function materiale(): BelongsTo
    {
        return $this->belongsTo(Material::class, 'material_id');
    }

    protected static function booted(): void
    {
        static::saving(function (self $f) {
            $f->costo_mensile = round(
                (float) $f->quantita * (float) $f->prezzo_unitario * (1 + (float) $f->ricarico / 100),
                2,
            );
        });

        // Il canone del noleggio dipende da queste righe: va rifatto.
        static::saved(fn (self $f) => $f->noleggio?->save());
        static::deleted(fn (self $f) => $f->noleggio?->save());
    }

    public function noleggio(): BelongsTo
    {
        return $this->belongsTo(Noleggio::class);
    }

    public static function gruppiLabels(): array
    {
        return [
            self::GRUPPO_CAFFE => 'Caffè',
            self::GRUPPO_POLVERI => 'Polveri e solubili',
            self::GRUPPO_DETERGENTI => 'Detergenti e igiene',
        ];
    }
}
