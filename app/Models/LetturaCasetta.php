<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\LogsAuditTrail;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Una lettura della casetta dell'acqua: il giro che il tecnico fa sul posto
 * (30/10/2026).
 *
 * Tre contatori -- acqua erogata, incasso, venduto -- piu' le quattro
 * operazioni del rendiconto e le foto dei display. Il rendiconto che il
 * Comune riceve non chiede i totali dei contatori ma il CONSUMO DEL PERIODO,
 * cioe' la differenza con la lettura precedente: e' quella la riga che si
 * fattura.
 *
 * Qui sta la regola che i fogli di calcolo sbagliavano: senza una lettura
 * precedente la differenza NON esiste, e restituire il contatore intero
 * significa fatturare 1.052.210 litri di acqua a un Comune. Tutti i metodi
 * di periodo tornano quindi null quando il confronto non si puo' fare, e
 * chi stampa scrive una casella vuota.
 *
 * @property-read MachineUnit|null $machineUnit
 * @property-read Customer|null $customer
 * @property-read ServiceReport|null $serviceReport
 * @property-read User|null $rilevataDa
 */
class LetturaCasetta extends Model
{
    use BelongsToTenant, HasUuids, LogsAuditTrail;

    protected $table = 'letture_casette';

    public const UNITA_M3 = 'm3';

    public const UNITA_LITRI = 'litri';

    /** @var array<string, string> */
    public const UNITA = [
        self::UNITA_M3 => 'metri cubi',
        self::UNITA_LITRI => 'litri',
    ];

    /**
     * Le quattro operazioni come le elencano i rendiconti dei Comuni (A, B,
     * C, D sui fogli), nell'ordine in cui ci sono abituati.
     *
     * @var array<string, string>
     */
    public const OPERAZIONI = [
        'controllo_pulizia' => 'Controllo e pulizia',
        'sanificazione' => 'Sanificazione',
        'ricarica_co2' => 'Ricarica CO2',
        'sostituzione_filtro' => 'Sostituzione filtro carboni attivi',
    ];

    protected $fillable = [
        'tenant_id',
        'machine_unit_id',
        'customer_id',
        'service_report_id',
        'data',
        'rilevata_da',
        'contatore_acqua',
        'incasso_totale',
        'valore_vendite',
        'controllo_pulizia',
        'sanificazione',
        'ricarica_co2',
        'sostituzione_filtro',
        'foto_contatore',
        'foto_incasso',
        'foto_vendite',
        'foto_altre',
        'note',
    ];

    protected $casts = [
        'data' => 'date',
        'contatore_acqua' => 'decimal:2',
        'incasso_totale' => 'decimal:2',
        'valore_vendite' => 'decimal:2',
        'controllo_pulizia' => 'boolean',
        'sanificazione' => 'boolean',
        'ricarica_co2' => 'boolean',
        'sostituzione_filtro' => 'boolean',
        'foto_altre' => 'array',
    ];

    /**
     * La lettura precedente, risolta una volta sola per riga. Non e' una
     * relazione Eloquent (dipende da data + created_at, non da una chiave):
     * memorizzarla qui evita di rifare la query per ogni colonna della
     * tabella, che sono quattro.
     */
    private ?self $precedenteRisolta = null;

    private bool $precedenteCercata = false;

    protected static function booted(): void
    {
        // Il Comune che ospita la casetta oggi: si copia alla creazione, cosi'
        // una lettura vecchia resta intestata a chi c'era allora.
        static::creating(function (self $lettura) {
            $lettura->rilevata_da ??= auth()->id();

            if (! $lettura->customer_id) {
                $lettura->customer_id = MachineUnit::withoutGlobalScopes()
                    ->whereKey($lettura->machine_unit_id)
                    ->value('current_customer_id');
            }
        });
    }

    public function machineUnit(): BelongsTo
    {
        return $this->belongsTo(MachineUnit::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function serviceReport(): BelongsTo
    {
        return $this->belongsTo(ServiceReport::class);
    }

    public function rilevataDa(): BelongsTo
    {
        return $this->belongsTo(User::class, 'rilevata_da');
    }

    /** Le letture di una casetta dalla piu' recente, l'ordine del rendiconto a rovescio. */
    public function scopeDellaCasetta(Builder $query, string $machineUnitId): Builder
    {
        return $query->where('machine_unit_id', $machineUnitId)->orderByDesc('data')->orderByDesc('created_at');
    }

    /**
     * La lettura precedente della stessa casetta. Il confronto e' su data e,
     * a pari data, su created_at: due letture lo stesso giorno sono un errore
     * di inserimento, ma se ci sono l'ordine resta quello in cui sono state
     * scritte.
     */
    public function precedente(): ?self
    {
        if ($this->precedenteCercata) {
            return $this->precedenteRisolta;
        }

        $this->precedenteCercata = true;

        if (! $this->machine_unit_id) {
            return null;
        }

        $this->precedenteRisolta = static::query()
            ->where('machine_unit_id', $this->machine_unit_id)
            ->whereKeyNot($this->getKey())
            ->where(function (Builder $q) {
                $q->whereDate('data', '<', $this->data)
                    ->orWhere(function (Builder $q) {
                        $q->whereDate('data', $this->data)
                            ->where('created_at', '<', $this->created_at ?? now());
                    });
            })
            ->orderByDesc('data')
            ->orderByDesc('created_at')
            ->first();

        return $this->precedenteRisolta;
    }

    /**
     * L'acqua erogata dalla lettura precedente: la riga che va nel rendiconto.
     * Null se manca il confronto -- vedi la nota in testa alla classe.
     */
    public function erogato(): ?float
    {
        return $this->differenzaCon('contatore_acqua');
    }

    public function incassoPeriodo(): ?float
    {
        return $this->differenzaCon('incasso_totale');
    }

    public function vendutoPeriodo(): ?float
    {
        return $this->differenzaCon('valore_vendite');
    }

    /**
     * Quanto l'incasso del periodo si discosta dal venduto del periodo.
     *
     * Sono due contatori diversi della stessa macchina e in teoria dicono la
     * stessa cosa: se non coincidono (il 30/10 su Casale erano 602,35 e
     * 588,22) c'e' qualcosa da spiegare prima di mandare il rendiconto --
     * gettoni, resi, un azzeramento. Null se uno dei due periodi non si puo'
     * calcolare.
     */
    public function scarto(): ?float
    {
        $incasso = $this->incassoPeriodo();
        $venduto = $this->vendutoPeriodo();

        if ($incasso === null || $venduto === null) {
            return null;
        }

        return round($incasso - $venduto, 2);
    }

    /**
     * A quale lettura del contatore va cambiato il filtro: l'ultimo cambio
     * registrato piu' l'autonomia della casetta
     * (machine_units.filtro_ogni). Null se non si sa quando e' stato
     * cambiato l'ultima volta o se l'autonomia non e' configurata: meglio
     * nessun avviso che un avviso inventato.
     */
    public function prossimoCambioFiltro(): ?float
    {
        $autonomia = $this->machineUnit?->filtro_ogni;

        if (! $autonomia) {
            return null;
        }

        $ultimoCambio = static::query()
            ->where('machine_unit_id', $this->machine_unit_id)
            ->where('sostituzione_filtro', true)
            ->whereNotNull('contatore_acqua')
            ->whereDate('data', '<=', $this->data)
            ->orderByDesc('data')
            ->orderByDesc('created_at')
            ->value('contatore_acqua');

        if ($ultimoCambio === null) {
            return null;
        }

        return round((float) $ultimoCambio + (float) $autonomia, 2);
    }

    /** Quanta acqua manca al cambio filtro. Negativo: e' già da cambiare. */
    public function mancaAlFiltro(): ?float
    {
        $soglia = $this->prossimoCambioFiltro();

        if ($soglia === null || $this->contatore_acqua === null) {
            return null;
        }

        return round($soglia - (float) $this->contatore_acqua, 2);
    }

    public function unita(): string
    {
        $casetta = $this->machineUnit;

        return $casetta && $casetta->unita_contatore ? $casetta->unita_contatore : self::UNITA_M3;
    }

    public function unitaBreve(): string
    {
        return $this->unita() === self::UNITA_LITRI ? 'l' : 'm³';
    }

    /** Le operazioni spuntate, per il rendiconto e per la riga della tabella. */
    public function operazioniFatte(): array
    {
        return collect(self::OPERAZIONI)
            ->filter(fn (string $etichetta, string $colonna) => (bool) $this->{$colonna})
            ->values()
            ->all();
    }

    /** Quante foto porta questa lettura: zero e' il caso da notare. */
    public function quanteFoto(): int
    {
        return collect([$this->foto_contatore, $this->foto_incasso, $this->foto_vendite])
            ->filter()
            ->count() + count($this->foto_altre ?? []);
    }

    private function differenzaCon(string $colonna): ?float
    {
        $precedente = $this->precedente();

        if ($this->{$colonna} === null || $precedente === null || $precedente->{$colonna} === null) {
            return null;
        }

        return round((float) $this->{$colonna} - (float) $precedente->{$colonna}, 2);
    }
}
