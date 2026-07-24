<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\LogsAuditTrail;
use App\Support\PhoneNumber;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Customer extends Model
{
    use BelongsToTenant, HasUuids, LogsAuditTrail;

    /** Anagrafica gia' presente nel gestionale (importata da li'): non va rimandata. */
    public const SOURCE_GESTIONALE = 'gestionale';

    /** Anagrafica nata nel CRM (es. da un preventivo): va inviata al gestionale solo se il preventivo viene accettato. */
    public const SOURCE_APP = 'app';

    protected $fillable = [
        'tenant_id',
        'first_name',
        'last_name',
        'company_name',
        'street',
        'postal_code',
        'city',
        'province',
        'latitude',
        'longitude',
        'emails',
        'phones',
        'pec',
        'tax_code',
        'vat_number',
        'sdi',
        'website',
        'website_checked_at',
        'source',
        'gestionale_code',
        'approved_for_gestionale_at',
        'sent_to_gestionale_at',
        'lavaggio_frequency_days',
        'lavaggio_next_due_date',
    ];

    protected $casts = [
        'latitude' => 'decimal:7',
        'longitude' => 'decimal:7',
        'emails' => 'array',
        'phones' => 'array',
        'approved_for_gestionale_at' => 'datetime',
        'sent_to_gestionale_at' => 'datetime',
        'website_checked_at' => 'datetime',
        'lavaggio_next_due_date' => 'date',
    ];

    protected $attributes = [
        'source' => self::SOURCE_APP,
        'emails' => '[]',
        'phones' => '[]',
    ];

    /**
     * L'anagrafica e' pronta per essere inviata al gestionale: nata
     * nell'app (non gia' importata da li') e con almeno un preventivo
     * accettato (vedi Quote::booted()) - finche' non approva un'offerta non
     * vogliamo sporcare il gestionale con anagrafiche di clienti che
     * potrebbero non concretizzarsi mai. L'invio vero e proprio resta
     * un'azione manuale di chi in ufficio lo inserisce nel gestionale
     * (nessuna integrazione automatica per ora).
     */
    public function readyForGestionaleSync(): bool
    {
        return $this->source === self::SOURCE_APP
            && $this->approved_for_gestionale_at !== null
            && $this->sent_to_gestionale_at === null;
    }

    public function markApprovedForGestionale(): void
    {
        if ($this->source === self::SOURCE_APP && $this->approved_for_gestionale_at === null) {
            $this->update(['approved_for_gestionale_at' => now()]);
        }
    }

    public function markSentToGestionale(): void
    {
        $this->update(['sent_to_gestionale_at' => now()]);
    }

    /**
     * @param array<int, string>|null $value
     */
    public function setPhonesAttribute(?array $value): void
    {
        $this->attributes['phones'] = json_encode(collect($value)
            ->map(fn ($number) => PhoneNumber::normalizeItalian($number))
            ->filter()
            ->unique()
            ->values()
            ->all());
    }

    public function primaryEmail(): ?string
    {
        return $this->emails[0] ?? null;
    }

    public function primaryPhone(): ?string
    {
        return $this->phones[0] ?? null;
    }

    public function quotes(): HasMany
    {
        return $this->hasMany(Quote::class);
    }

    public function lavaggi(): HasMany
    {
        return $this->hasMany(Lavaggio::class);
    }

    /**
     * Ricalcola la prossima scadenza lavaggio da questo cliente: ultimo
     * lavaggio registrato (per data, non per data di inserimento) +
     * cadenza in giorni impostata sul cliente. Se manca l'una o l'altra
     * cosa, la scadenza resta quella impostata a mano (o vuota) - la
     * cadenza automatica e' un aiuto, non sostituisce un valore inserito
     * manualmente in assenza di lavaggi registrati.
     */
    public function recalculateLavaggioNextDue(): void
    {
        if (! $this->lavaggio_frequency_days) {
            return;
        }

        $lastData = $this->lavaggi()->max('data');

        if (! $lastData) {
            return;
        }

        $this->update([
            'lavaggio_next_due_date' => \Illuminate\Support\Carbon::parse($lastData)->addDays($this->lavaggio_frequency_days),
        ]);
    }

    public function quoteGroups(): HasMany
    {
        return $this->hasMany(QuoteGroup::class);
    }

    public function informationRequests(): HasMany
    {
        return $this->hasMany(InformationRequest::class);
    }

    /**
     * Ragione sociale prima di tutto (contesto B2B): se c'è anche un
     * referente, viene mostrato tra parentesi come informazione aggiuntiva,
     * non al posto della ragione sociale.
     */
    public function getFullNameAttribute(): string
    {
        $contact = trim("{$this->first_name} {$this->last_name}");

        if ($this->company_name && $contact) {
            return "{$this->company_name} ({$contact})";
        }

        return $this->company_name ?: $contact;
    }

    public function hasPreciseLocation(): bool
    {
        return $this->latitude !== null && $this->longitude !== null;
    }

    public function geocodingAddress(): string
    {
        return collect([
            $this->street,
            trim("{$this->postal_code} {$this->city}"),
            $this->province,
            'Italia',
        ])->filter()->implode(', ');
    }

    /**
     * @return array<int, string>
     */
    public function geocodingAddressCandidates(): array
    {
        return [
            $this->geocodingAddress(),
            collect([
                trim("{$this->postal_code} {$this->city}"),
                $this->province,
                'Italia',
            ])->filter()->implode(', '),
            collect([
                $this->city,
                $this->province,
                'Italia',
            ])->filter()->implode(', '),
        ];
    }

    /**
     * Distanza in km dal punto indicato (formula haversine, raggio Terra
     * 6371 km). Null se il cliente non ha coordinate salvate.
     */
    public function distanceFrom(float $latitude, float $longitude): ?float
    {
        if ($this->latitude === null || $this->longitude === null) {
            return null;
        }

        $earthRadiusKm = 6371;

        $latDelta = deg2rad((float) $this->latitude - $latitude);
        $lngDelta = deg2rad((float) $this->longitude - $longitude);

        $a = sin($latDelta / 2) ** 2
            + cos(deg2rad($latitude)) * cos(deg2rad((float) $this->latitude)) * sin($lngDelta / 2) ** 2;

        return $earthRadiusKm * 2 * atan2(sqrt($a), sqrt(1 - $a));
    }
}
