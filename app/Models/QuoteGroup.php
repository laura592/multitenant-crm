<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\HasClientLink;
use App\Models\Concerns\LogsAuditTrail;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class QuoteGroup extends Model
{
    use BelongsToTenant, HasClientLink, HasUuids, LogsAuditTrail, SoftDeletes;

    protected $fillable = [
        'tenant_id',
        'customer_id',
        'number',
        'status',
        'sent_at',
        'notes',
    ];

    protected $casts = [
        'sent_at' => 'datetime',
        'client_first_viewed_at' => 'datetime',
        'client_last_viewed_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $group) {
            if (! $group->number) {
                $group->number = static::nextNumberForTenant($group->tenant_id);
            }
        });

        // Solo le email seguono il gruppo nel soft delete: i preventivi
        // restano indipendenti, come gia' oggi con quote_group_id
        // nullOnDelete() (il gruppo puo' sparire senza portarsi via le
        // offerte gia' fatte al cliente).
        static::deleting(function (self $group) {
            $group->emails->each->delete();
        });
    }

    public static function nextNumberForTenant(?string $tenantId): string
    {
        $year = date('Y');
        $prefix = "OFF-{$year}-";

        $last = static::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->whereYear('created_at', $year)
            ->where('number', 'like', "{$prefix}%")
            ->orderByRaw('CAST(SUBSTRING(number, -4) AS UNSIGNED) DESC')
            ->first();

        $next = 1;
        if ($last && preg_match('/-(\d+)$/', $last->number, $matches)) {
            $next = (int) $matches[1] + 1;
        }

        return $prefix.str_pad((string) $next, 4, '0', STR_PAD_LEFT);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function quotes(): HasMany
    {
        return $this->hasMany(Quote::class);
    }

    /**
     * Gli invii, dal piu' recente: ->latest() come su Quote::emails().
     *
     * L'ordine sta sulla relazione e non su chi la legge perche' la scheda
     * "Vedi" stampa gli invii passando per la relazione (le RepeatableEntry
     * dell'infolist leggono il record, non una query a parte): ordinarli
     * altrove non avrebbe avuto effetto sulle righe stampate.
     */
    public function emails(): HasMany
    {
        return $this->hasMany(QuoteGroupEmail::class)->latest();
    }

    public function responses(): HasMany
    {
        return $this->hasMany(QuoteResponse::class)->latest();
    }

    public function chosenQuote(): ?Quote
    {
        return $this->quotes()->where('status', 'accettato')->first();
    }

    /**
     * Le volte in cui abbiamo chiesto una risposta su questa trattativa.
     *
     * Specchio di Quote::contattiDellaTrattativa(), guardato dall'altro
     * capo: li' si parte dal preventivo e si risale al gruppo, qui si parte
     * dal gruppo e si scende alle alternative.
     *
     * I contatti scritti su una singola alternativa contano come contatti
     * dell'offerta: un preventivo nasce spesso da solo, lo si telefona, e
     * solo dopo lo si collega all'offerta insieme alle altre proposte
     * ("Collega preventivo esistente"). Guardando solo quote_group_id
     * quella telefonata sparirebbe dalla scheda dove la si va a cercare.
     *
     * @return Collection<int, QuoteContatto>
     */
    public function contattiDellaTrattativa(): Collection
    {
        return QuoteContatto::query()
            ->with(['user', 'quote'])
            ->where(function ($query) {
                $query->where('quote_group_id', $this->id)
                    // Sottoquery e non un pluck: le alternative possono
                    // essere sei, e un giro in piu' sul database per
                    // ricavare degli id che il database ha gia' non serve.
                    ->orWhereIn('quote_id', $this->quotes()->select('id'));
            })
            ->orderByDesc('data')
            ->get();
    }
}
