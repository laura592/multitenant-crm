<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Una volta in cui abbiamo chiesto una risposta su un preventivo.
 *
 * Vedi la migrazione 2026_11_27_110000: qui finiscono sia la mail di
 * sollecito sia la telefonata, perche' all'elenco di chi richiamare
 * servono tutte e due allo stesso modo -- un cliente sentito ieri non si
 * richiama oggi, che l'abbiamo sentito per mail o a voce.
 *
 * Il contatto sta sulla TRATTATIVA: quote_id se il preventivo e' singolo,
 * quote_group_id se fa parte di un'offerta con alternative. Mai tutti e
 * due: su sei alternative si telefona una volta sola.
 */
class QuoteContatto extends Model
{
    use BelongsToTenant, HasUuids, SoftDeletes;

    protected $table = 'quote_contatti';

    /**
     * La mail che chiede un si' o un no. E' questa che si conta per
     * decidere quando smettere: due e basta.
     */
    public const TIPO_SOLLECITO = 'sollecito';

    /** Una telefonata, o comunque un contatto fatto a voce. */
    public const TIPO_TELEFONATA = 'telefonata';

    /** Tutto il resto: un incontro, un messaggio, il passaggio in sede. */
    public const TIPO_ALTRO = 'altro';

    /**
     * Quante volte si chiede prima di smettere.
     *
     * Due (indicazione di Laura, 27/11/2026): il primo sollecito dopo la
     * scadenza della validita', il secondo a distanza. Se tace anche dopo
     * il secondo si smette, e per l'azienda quella trattativa vale un no --
     * ma lo stato resta "scaduto", che un rifiuto non detto non si scrive
     * in bocca al cliente.
     */
    public const SOLLECITI_MASSIMI = 2;

    protected $fillable = [
        'tenant_id',
        'quote_id',
        'quote_group_id',
        'user_id',
        'tipo',
        'data',
        'nota',
    ];

    protected $casts = [
        'data' => 'datetime',
    ];

    /** @return array<string, string> */
    public static function tipi(): array
    {
        return [
            self::TIPO_SOLLECITO => 'Mail di sollecito',
            self::TIPO_TELEFONATA => 'Telefonata',
            self::TIPO_ALTRO => 'Altro contatto',
        ];
    }

    public function quote(): BelongsTo
    {
        return $this->belongsTo(Quote::class);
    }

    public function quoteGroup(): BelongsTo
    {
        return $this->belongsTo(QuoteGroup::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
