<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * Una singola voce dietro il totale di un mese di cash flow.
 *
 * L'anagrafica c'e' solo come testo libero: /contabilita/cashflow/dettaglio
 * non espone l'id, quindi queste righe non si collegano ai clienti del CRM.
 * Servono a spiegare un numero, non a partire da qui per fare qualcosa.
 */
class EurekaCashflowVoce extends Model
{
    use BelongsToTenant, HasUuids;

    /** Scadenze di fatture gia' emesse: impegni presi, non previsioni. */
    public const TIPI_FATTURA = ['FTC', 'FTF'];

    protected $table = 'eureka_cashflow_voci';

    protected $fillable = [
        'tenant_id', 'anno', 'mese', 'data_documento', 'data_scadenza',
        'numero', 'descrizione', 'tipo', 'importo_totale', 'importo',
    ];

    protected $casts = [
        'anno' => 'integer',
        'mese' => 'integer',
        'data_documento' => 'date',
        'data_scadenza' => 'date',
        'importo_totale' => 'decimal:2',
        'importo' => 'decimal:2',
    ];

    /** Il segno dell'importo E' il verso: positivo entra, negativo esce. */
    public function eEntrata(): bool
    {
        return (float) $this->importo > 0;
    }

    public function daFattura(): bool
    {
        return in_array($this->tipo, self::TIPI_FATTURA, true);
    }
}
