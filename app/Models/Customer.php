<?php

namespace App\Models;

use App\Mail\CustomerGestionaleReviewMail;
use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\LogsAuditTrail;
use App\Support\PhoneNumber;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Mail;

/**
 * @property-read self|null $billingCustomer
 */
class Customer extends Model
{
    use BelongsToTenant, HasUuids, LogsAuditTrail, SoftDeletes;

    /** Anagrafica gia' presente nel gestionale (importata da li'): non va rimandata. */
    public const SOURCE_GESTIONALE = 'gestionale';

    /** Anagrafica nata nel CRM (es. da un preventivo): va inviata al gestionale solo se il preventivo viene accettato. */
    public const SOURCE_APP = 'app';

    protected $fillable = [
        'tenant_id',
        'billing_customer_id',
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
        'gestionale_review_flagged_at',
        'gestionale_review_note',
        'gestionale_suggested_code',
        'gestionale_suggested_label',
    ];

    protected $casts = [
        'latitude' => 'decimal:7',
        'longitude' => 'decimal:7',
        'emails' => 'array',
        'phones' => 'array',
        'approved_for_gestionale_at' => 'datetime',
        'sent_to_gestionale_at' => 'datetime',
        'website_checked_at' => 'datetime',
        'gestionale_review_flagged_at' => 'datetime',
    ];

    /**
     * Campi con un corrispettivo reale nell'anagrafica Eureka (visti nelle
     * risposte di GET /anagrafica/cerca: rag_sociale_1, partita_iva,
     * codice_fiscale, citta, sigla_prov, email, nr_telefono) — solo questi,
     * se cambiano su un cliente gia' collegato (gestionale_code valorizzato),
     * fanno scattare una segnalazione (vedi EditCustomer::afterSave()).
     * Campi solo-CRM (note, billing_customer_id, posizione GPS...) non
     * contano: non hanno nulla da aggiornare lato Eureka.
     *
     * @var array<string, string>
     */
    public const GESTIONALE_TRACKED_FIELDS = [
        'company_name' => 'Ragione sociale',
        'first_name' => 'Nome',
        'last_name' => 'Cognome',
        'vat_number' => 'P.IVA',
        'tax_code' => 'Codice fiscale',
        'street' => 'Indirizzo',
        'postal_code' => 'CAP',
        'city' => 'Città',
        'province' => 'Provincia',
        'emails' => 'Email',
        'phones' => 'Telefono',
    ];

    protected $attributes = [
        'source' => self::SOURCE_APP,
        'emails' => '[]',
        'phones' => '[]',
    ];

    protected static function booted(): void
    {
        // Niente cascata: un cliente con preventivi/rapportini/macchine
        // ancora collegati non va ne' eliminato ne' lasciato orfano di quei
        // dati, va bloccato finche' chi lo elimina non sistema prima quei
        // collegamenti (vedi anche il controllo gemello lato Filament in
        // CustomerResource, che mostra un messaggio invece di fallire muto).
        static::deleting(function (self $customer) {
            if ($customer->hasBlockingRelatedRecords()) {
                return false;
            }
        });
    }

    /**
     * Vedi Customer::booted(): usato per bloccare la cancellazione (soft
     * delete incluso) finche' il cliente ha ancora dati collegati.
     */
    public function hasBlockingRelatedRecords(): bool
    {
        return $this->quotes()->exists()
            || $this->quoteGroups()->exists()
            || $this->installedMachineUnits()->exists()
            || ServiceReport::where('customer_id', $this->id)->exists();
    }

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
     * @param array<int, string> $changedLabels
     */
    public function flagGestionaleReview(array $changedLabels): void
    {
        $this->update([
            'gestionale_review_flagged_at' => now(),
            'gestionale_review_note' => implode(', ', $changedLabels),
        ]);
    }

    /**
     * Stessa segnalazione di EditCustomer::afterSave(), riutilizzabile da
     * qualunque altro punto dell'app che possa salvare un cliente esistente
     * gia' collegato a Eureka — es. l'editOptionForm del campo cliente nel
     * rapportino (vedi ServiceReportResource), che modifica il record vero
     * ma passa dal meccanismo generico di Filament sul Select, non dalla
     * pagina "Modifica cliente": senza questo, quelle modifiche restavano
     * silenziose (nessuna nota, nessuna email a chi aggiorna Eureka a mano).
     *
     * @param array<int, string> $changedAttributes chiavi del model cambiate (da getChanges())
     */
    public function notifyGestionaleReviewIfLinked(array $changedAttributes): void
    {
        if ($this->gestionale_code === null) {
            return;
        }

        $changed = array_intersect_key(self::GESTIONALE_TRACKED_FIELDS, array_flip($changedAttributes));

        if ($changed === []) {
            return;
        }

        $this->flagGestionaleReview(array_values($changed));

        $recipients = $this->tenant?->notificationRecipients('customer_gestionale_review') ?? [];

        if ($recipients !== []) {
            Mail::to($recipients)->send(new CustomerGestionaleReviewMail(
                $this,
                'Modifica su un cliente già collegato a Eureka: '.implode(', ', $changed).'.',
            ));
        }
    }

    public function dismissGestionaleReview(): void
    {
        $this->update([
            'gestionale_review_flagged_at' => null,
            'gestionale_review_note' => null,
        ]);
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

    public function installedMachineUnits(): HasMany
    {
        return $this->hasMany(MachineUnit::class, 'current_customer_id');
    }

    /**
     * Cliente che paga al posto di questo (es. un gestore che ha messo una
     * macchina in comodato presso questo cliente e si accolla i costi):
     * preventivi/rapportini/lavaggi restano registrati su QUESTO cliente
     * (dove si lavora davvero), ma vanno intestati/inviati al cliente
     * restituito da invoiceRecipient() se impostato.
     */
    public function billingCustomer(): BelongsTo
    {
        return $this->belongsTo(self::class, 'billing_customer_id');
    }

    /**
     * Destinatario effettivo di documenti/fatture per questo cliente: se
     * stesso, a meno che non paghi qualcun altro al posto suo.
     */
    public function invoiceRecipient(): self
    {
        return $this->billingCustomer ?? $this;
    }

    public function lavaggi(): HasMany
    {
        return $this->hasMany(Lavaggio::class);
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
