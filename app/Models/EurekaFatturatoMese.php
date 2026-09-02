<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Il fatturato di un mese come lo calcola Eureka.
 *
 * "Come lo calcola Eureka" non e' un dettaglio: il netto qui dentro non
 * coincide con la somma degli imponibili della nostra copia delle fatture,
 * perche' il gestionale pesa le causali col piano dei conti e conta i
 * documenti per data di registrazione. E' il numero che l'ufficio vede sul
 * gestionale, ed e' quello che deve vedere anche qui.
 */
class EurekaFatturatoMese extends Model
{
    use BelongsToTenant, HasUuids;

    public const TIPO_CLIENTE = 'cliente';

    public const TIPO_FORNITORE = 'fornitore';

    protected $table = 'eureka_fatturato_mesi';

    protected $fillable = [
        'tenant_id', 'tipo', 'anno', 'mese', 'dare', 'avere', 'netto',
    ];

    protected $casts = [
        'anno' => 'integer',
        'mese' => 'integer',
        'dare' => 'decimal:2',
        'avere' => 'decimal:2',
        'netto' => 'decimal:2',
    ];

    /** "gen", "feb"... per le etichette dei grafici e delle tabelle. */
    public function etichettaMese(): string
    {
        return ucfirst(mb_substr(
            Carbon::create($this->anno, $this->mese, 1)->translatedFormat('F'),
            0,
            3,
        ));
    }
}
