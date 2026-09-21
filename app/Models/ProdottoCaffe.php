<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * Una voce del listino caffe' e solubili, da cui nasce l'offerta caffe'.
 *
 * Non e' un Product: quelli finiscono nel preventivo della macchina, e il
 * caffe' l'ufficio lo vuole in un documento a parte (vedi la migrazione
 * create_prodotti_caffe_table).
 */
class ProdottoCaffe extends Model
{
    use HasUuids;

    protected $table = 'prodotti_caffe';

    /** L'ordine e' quello in cui i gruppi escono sull'offerta. */
    public const GRUPPI = [
        'caffe' => 'Caffè',
        'liofilizzati' => 'Prodotti liofilizzati',
    ];

    protected $fillable = [
        'gruppo',
        'nome',
        'formato',
        'prezzo',
        'ordinamento',
        'attivo',
    ];

    protected $casts = [
        'prezzo' => 'decimal:2',
        'ordinamento' => 'integer',
        'attivo' => 'boolean',
    ];

    /** @param  Builder<self>  $query */
    public function scopeInListino(Builder $query): Builder
    {
        return $query->where('attivo', true)->orderBy('ordinamento')->orderBy('nome');
    }

    public function etichettaGruppo(): string
    {
        return self::GRUPPI[$this->gruppo] ?? $this->gruppo;
    }
}
