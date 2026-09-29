<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\LogsAuditTrail;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Storage;

/**
 * Un documento che il cliente ha consegnato: visura, esenzione IVA, mandato,
 * documento d'identita' del firmatario, la scheda anagrafica firmata.
 *
 * Sta sul disco `local`, la cui root e' storage/app/private, non su `public`: sono documenti fiscali e
 * d'identita', e un URL indovinabile li renderebbe leggibili a chiunque.
 * Si scaricano passando dal pannello, che controlla i permessi.
 */
class CustomerDocument extends Model
{
    use BelongsToTenant, HasUuids, LogsAuditTrail, SoftDeletes;

    protected $table = 'customer_documents';

    /** Le quattro voci che il modulo di anagrafica elenca, piu' la scheda stessa. */
    public const TIPI = [
        'visura' => 'Visura camerale o certificato P. IVA',
        'esenzione_iva' => 'Dichiarazione di esenzione IVA',
        'mandato' => 'Mandato Ri.Ba. / SDD firmato',
        'documento_identita' => 'Documento d\'identita\' del firmatario',
        'scheda_anagrafica' => 'Scheda anagrafica firmata',
        'contratto' => 'Contratto',
        'altro' => 'Altro',
    ];

    protected $fillable = [
        'tenant_id',
        'customer_id',
        'tipo',
        'titolo',
        'path',
        'mime',
        'dimensione',
        'scade_il',
        'caricato_da',
        'note',
    ];

    protected $casts = [
        'scade_il' => 'date',
        'dimensione' => 'integer',
    ];

    protected static function booted(): void
    {
        // Il file segue il suo record: cancellare la riga e lasciare il PDF
        // sul disco vuol dire accumulare documenti d'identita' che nessuno
        // sa piu' di avere. Solo sulla cancellazione definitiva: il soft
        // delete si puo' annullare, e senza file non si potrebbe.
        static::forceDeleted(function (self $doc) {
            Storage::disk('local')->delete($doc->path);
        });
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function caricatoDa(): BelongsTo
    {
        return $this->belongsTo(User::class, 'caricato_da');
    }

    public function tipoLeggibile(): string
    {
        return self::TIPI[$this->tipo] ?? $this->tipo;
    }

    /** Scaduto, o in scadenza entro un mese: una visura vecchia non vale. */
    public function scaduto(): bool
    {
        return $this->scade_il !== null && $this->scade_il->isPast();
    }

    public function dimensioneLeggibile(): string
    {
        if (! $this->dimensione) {
            return '—';
        }

        return $this->dimensione >= 1048576
            ? round($this->dimensione / 1048576, 1).' MB'
            : max(1, round($this->dimensione / 1024)).' KB';
    }
}
