<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Una fattura registrata in contabilita' su Eureka, copiata in locale.
 *
 * Fotografia ricaricata dall'import, non un archivio che cresce: se un
 * documento sparisce da Eureka sparisce anche qui.
 */
class EurekaFattura extends Model
{
    use BelongsToTenant, HasUuids;

    public const TIPO_CLIENTE = 'cliente';

    public const TIPO_FORNITORE = 'fornitore';

    protected $table = 'eureka_fatture';

    protected $fillable = [
        'tenant_id', 'tipo', 'id_eureka', 'gestionale_code', 'customer_id',
        'ragione_sociale', 'partita_iva', 'numero_doc', 'data_doc',
        'totale_doc', 'imponibile', 'pagamento', 'causale', 'id_b10_origine',
        'e_acconto', 'detrae_acconto_numero', 'detrazione_ambigua',
    ];

    protected $casts = [
        'data_doc' => 'date',
        'totale_doc' => 'decimal:2',
        'imponibile' => 'decimal:2',
        'e_acconto' => 'boolean',
        'detrazione_ambigua' => 'boolean',
    ];

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /**
     * Le condizioni RiBa iniziano tutte per R (R001, R041, R030...): la
     * fattura viene incassata tramite effetto presentato in banca, non con
     * un pagamento diretto del cliente. Non compare mai fra le partite
     * aperte, quindi sollecitarla non avrebbe senso.
     */
    public function aRiba(): bool
    {
        return str_starts_with((string) $this->pagamento, 'R');
    }

    public function notaDiCredito(): bool
    {
        return (float) $this->totale_doc < 0;
    }
}
