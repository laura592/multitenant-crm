<?php

namespace App\Models;

use App\Mail\CustomerGestionaleReviewMail;
use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\HasClientLink;
use App\Models\Concerns\LogsAuditTrail;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

class Quote extends Model
{
    use BelongsToTenant, HasClientLink, HasUuids, LogsAuditTrail, SoftDeletes;

    /**
     * Giorni di validita' di un preventivo appena inviato.
     *
     * Non e' una cifra tonda: sui preventivi che una risposta l'hanno
     * avuta i si' arrivano con mediana 5 giorni, il 67% entro una
     * settimana, il 93% entro 45 giorni, e nessuno dopo 69. Oltre il mese
     * e mezzo il silenzio, storicamente, e' un no.
     */
    public const GIORNI_VALIDITA = 45;

    public const STATUS_BOZZA = 'bozza';

    public const STATUS_INVIATO = 'inviato';

    public const STATUS_ACCETTATO = 'accettato';

    public const STATUS_RIFIUTATO = 'rifiutato';

    /**
     * Inviato e mai risposto oltre la data di validita'. Non e' un no del
     * cliente: e' il nostro smettere di contarlo fra le trattative aperte.
     */
    public const STATUS_SCADUTO = 'scaduto';

    protected $casts = [
        'date' => 'date',
        'valid_until' => 'date',
        'discount' => 'decimal:2',
        'extra_discount' => 'decimal:2',
        'subtotal' => 'decimal:2',
        'tax_total' => 'decimal:2',
        'total' => 'decimal:2',
        'rental_monthly_fee' => 'decimal:2',
        'client_first_viewed_at' => 'datetime',
        'client_last_viewed_at' => 'datetime',
    ];

    protected $fillable = [
        'tenant_id',
        'quote_group_id',
        'information_request_id',
        'customer_id',
        'billing_customer_id',
        'number',
        'date',
        'valid_until',
        'status',
        'discount',
        'extra_discount',
        'notes',
        'payment_method',
        'rental_monthly_fee',
        'rental_months',
        'subtotal',
        'tax_total',
        'total',
    ];

    protected $attributes = [
        'discount' => 0,
        'extra_discount' => 0,
        'subtotal' => 0,
        'tax_total' => 0,
        'total' => 0,
    ];

    protected static function booted(): void
    {
        static::creating(function (self $quote) {
            if (! $quote->number) {
                $quote->number = static::nextNumberForTenant($quote->tenant_id);
            }
        });

        // Un'anagrafica nata nell'app non va inviata al gestionale finche' il
        // cliente non approva un'offerta: appena un preventivo passa ad
        // "accettato" segnamo il suo cliente come pronto per l'invio (vedi
        // Customer::readyForGestionaleSync()). L'invio vero resta manuale.
        static::updated(function (self $quote) {
            if ($quote->wasChanged('status') && $quote->status === 'accettato') {
                if ($customer = $quote->customer) {
                    $wasReady = $customer->approved_for_gestionale_at !== null;
                    $customer->markApprovedForGestionale();

                    if (! $wasReady && $customer->approved_for_gestionale_at !== null) {
                        $recipients = $quote->tenant?->notificationRecipients('customer_gestionale_review') ?? [];

                        if ($recipients !== []) {
                            Mail::to($recipients)->send(new CustomerGestionaleReviewMail(
                                $customer,
                                'Nuovo cliente pronto per l\'invio al gestionale (preventivo accettato).',
                            ));
                        }
                    }
                }

                // Lo stato "Scelto" dell'offerta globale era un campo manuale
                // indipendente: se nessuno lo aggiornava a mano restava
                // "Inviato" anche con una soluzione gia' accettata dal
                // cliente. Qui si allinea da solo, senza sovrascrivere uno
                // stato "Scaduto" impostato volutamente a mano.
                if ($quote->quote_group_id && $quote->quoteGroup && $quote->quoteGroup->status !== 'scaduto') {
                    $quote->quoteGroup->update(['status' => 'scelto']);
                }
            }

            // E il contrario: se il cliente ha detto no a TUTTE le soluzioni,
            // l'offerta e' chiusa. Senza questo restava "Inviato" in mezzo a
            // quelle che aspettano ancora una risposta, e per accorgersene
            // bisognava aprirla e guardare i preventivi uno per uno
            // (Laura, 12/11/2026).
            //
            // Solo quando sono tutti rifiutati: finche' ne resta uno in piedi
            // la partita e' aperta.
            if ($quote->wasChanged('status') && $quote->status === 'rifiutato' && $quote->quote_group_id) {
                // Lo stato dell'offerta si filtra nella query invece di
                // leggerlo come proprieta': la relazione torna un Model
                // generico, e PHPStan non ci trova sopra $status.
                $gruppo = QuoteGroup::query()
                    ->whereKey($quote->quote_group_id)
                    ->whereNotIn('status', ['scaduto', 'scelto'])
                    ->first();

                $neRestaUnoInPiedi = static::query()
                    ->where('quote_group_id', $quote->quote_group_id)
                    ->where('status', '!=', 'rifiutato')
                    ->exists();

                if ($gruppo && ! $neRestaUnoInPiedi) {
                    $gruppo->update(['status' => 'rifiutato']);
                }
            }
        });

        // La richiesta informazioni da cui nasce il preventivo ne segue lo
        // stato da sola (in lavorazione, preventivo inviato, accettato...):
        // prima andava cambiata a mano e restava "Nuova", contata tra quelle
        // da gestire, anche a preventivo gia' inviato.
        static::saved(function (self $quote) {
            if ($quote->wasRecentlyCreated || $quote->wasChanged(['status', 'information_request_id'])) {
                $quote->syncInformationRequests();
            }
        });
        static::deleted(fn (self $quote) => $quote->syncInformationRequests());
        static::restored(fn (self $quote) => $quote->syncInformationRequests());

        // Le FK cascadeOnDelete() del DB non scattano piu' su un soft delete
        // (e' un UPDATE, non una DELETE): replichiamo la cascata a mano sui
        // figli diretti (righe preventivo ed email), cosi' spariscono e
        // tornano insieme al preventivo.
        static::deleting(function (self $quote) {
            $quote->quoteProducts->each->delete();
            $quote->emails->each->delete();
        });
    }

    /**
     * Riallinea la richiesta collegata, e quella da cui il preventivo e'
     * stato appena staccato se il collegamento e' cambiato.
     */
    protected function syncInformationRequests(): void
    {
        $requestIds = array_filter(array_unique([
            $this->information_request_id,
            $this->wasChanged('information_request_id') ? $this->getOriginal('information_request_id') : null,
        ]));

        if ($requestIds === []) {
            return;
        }

        InformationRequest::withoutGlobalScope('tenant')
            ->whereKey($requestIds)
            ->get()
            ->each->syncStatusFromQuotes();
    }

    /**
     * Numerazione scoped per tenant: un partner non deve vedere "buchi" dovuti
     * ai preventivi di altri tenant (vedi docs/architecture.md §10.5).
     */
    /**
     * Fino a quando vale un preventivo mandato oggi.
     *
     * Di norma 45 giorni (vedi GIORNI_VALIDITA). Ma se dentro c'e' roba
     * Franke non si puo' promettere un prezzo oltre la fine del listino:
     * dal 1° gennaio Franke aumenta, e un preventivo di dicembre valido a
     * febbraio ci farebbe vendere in perdita o rimangiare la parola
     * (indicazione di Laura, 27/11/2026).
     *
     * La data non e' scritta a mano: si legge dal listino Franke in vigore
     * (price_lists, fornitore Franke). Se per qualche motivo non ce n'e'
     * uno valido oggi, si ripiega sul 31 dicembre dell'anno corrente, che
     * e' come i listini Franke sono sempre stati fatti.
     *
     * NB: al 27/11/2026 TUTTI i listini a sistema finiscono il 31/12 --
     * Dalla Corte, Bianchi, Liebherr, Bravilor compresi. La regola qui
     * guarda solo Franke perche' cosi' e' stata chiesta; allargarla agli
     * altri fornitori e' una riga.
     */
    public function scadenzaDaOggi(): string
    {
        $limite = now()->addDays(self::GIORNI_VALIDITA);
        $fineListino = $this->fineListinoFranke();

        if ($fineListino && $fineListino->lt($limite)) {
            $limite = $fineListino;
        }

        return $limite->toDateString();
    }

    /** Se il preventivo contiene articoli a listino Franke. */
    public function haArticoliFranke(): bool
    {
        return $this->quoteProducts()
            ->whereHas('product', function (Builder $query) {
                $query->where('source', 'franke_ufficiale')
                    ->orWhereHas('brand', fn (Builder $b) => $b->where('name', 'like', '%franke%'));
            })
            ->exists();
    }

    /**
     * L'ultimo giorno del listino Franke in vigore, se il preventivo ha
     * articoli Franke dentro. Null negli altri casi.
     */
    private function fineListinoFranke(): ?CarbonInterface
    {
        if (! $this->haArticoliFranke()) {
            return null;
        }

        $oggi = now()->toDateString();

        $fine = DB::table('price_lists')
            ->join('suppliers', 'suppliers.id', '=', 'price_lists.supplier_id')
            ->where('suppliers.name', 'like', '%franke%')
            ->whereNotNull('price_lists.valid_to')
            ->where(fn ($q) => $q->whereNull('price_lists.valid_from')->orWhere('price_lists.valid_from', '<=', $oggi))
            ->where('price_lists.valid_to', '>=', $oggi)
            ->min('price_lists.valid_to');

        return $fine
            ? Carbon::parse($fine)->endOfDay()
            : now()->endOfYear();
    }

    public static function nextNumberForTenant(?string $tenantId): string
    {
        $year = date('Y');
        $prefix = "PRV-{$year}-";

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

    /** I noleggi operativi nati da questo preventivo. */
    public function noleggi(): HasMany
    {
        return $this->hasMany(Noleggio::class);
    }

    /**
     * Chi paga QUESTO preventivo.
     *
     * Nullo = il pagante abituale del cliente, che e' il comportamento
     * storico. Valorizzato = scelta fatta su questo documento, e vince.
     */
    public function billingCustomer(): BelongsTo
    {
        return $this->belongsTo(Customer::class, 'billing_customer_id');
    }

    /**
     * Il destinatario della fatturazione per questo preventivo.
     * Vedi ServiceReport::invoiceRecipient(): stessa precedenza.
     */
    public function invoiceRecipient(): ?Customer
    {
        return $this->billingCustomer ?? $this->customer?->invoiceRecipient();
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function quoteGroup(): BelongsTo
    {
        return $this->belongsTo(QuoteGroup::class);
    }

    /**
     * La richiesta informazioni da cui e' nato questo preventivo, quando c'e':
     * il collegamento serve a leggere lo stato "preventivo inviato" dal lato
     * richiesta senza doverlo copiare a mano (vedi la migration).
     */
    public function informationRequest(): BelongsTo
    {
        return $this->belongsTo(InformationRequest::class);
    }

    public function paymentMethodRelation(): BelongsTo
    {
        return $this->belongsTo(PaymentMethod::class, 'payment_method', 'slug');
    }

    public function products(): BelongsToMany
    {
        return $this->belongsToMany(Product::class, 'quote_products')
            ->withPivot('price', 'quantity', 'discount', 'tax', 'total')
            ->withTimestamps();
    }

    public function quoteProducts(): HasMany
    {
        return $this->hasMany(QuoteProduct::class);
    }

    /**
     * Solo le righe "macchina" (non le opzioni figlie): usata dall'infolist
     * per elencare le righe preventivo raggruppate, con le opzioni annidate
     * sotto ciascuna tramite QuoteProduct::options().
     */
    public function baseQuoteProducts(): HasMany
    {
        return $this->hasMany(QuoteProduct::class)->whereNull('parent_quote_product_id');
    }

    public function emails(): HasMany
    {
        return $this->hasMany(QuoteEmail::class)->latest();
    }

    /**
     * Le risposte del cliente dal link nella mail: quelle date su questo
     * preventivo e, se fa parte di un'offerta globale, anche quelle date
     * sull'offerta intera (domande, richiamate, rifiuto di tutte le
     * soluzioni) - dal preventivo si vede cosi' tutta la conversazione.
     *
     * @return Collection<int, QuoteResponse>
     */
    public function clientResponses(): Collection
    {
        return QuoteResponse::withoutGlobalScope('tenant')
            ->where(function ($query) {
                $query->where('quote_id', $this->id);

                if ($this->quote_group_id) {
                    $query->orWhere(fn ($q) => $q->where('quote_group_id', $this->quote_group_id)->whereNull('quote_id'));
                }
            })
            ->latest()
            ->get();
    }

    /**
     * Accettato o rifiutato: il cliente ha gia' deciso, dalla pagina restano
     * solo domande e richiamate.
     */
    public function isDecided(): bool
    {
        return in_array($this->status, ['accettato', 'rifiutato'], true);
    }

    /**
     * In attesa di una risposta: inviato e non ancora deciso.
     *
     * Lo scaduto NON e' in attesa -- l'abbiamo tolto noi dalle trattative
     * aperte -- ma resta comunque accettabile dal link del cliente, che
     * infatti `isDecided()` continua a dire "non deciso". Un si' in
     * ritardo e' pur sempre un si': si incassa, non si rimanda al
     * mittente.
     */
    public function inAttesa(): bool
    {
        return $this->status === self::STATUS_INVIATO;
    }

    /**
     * La data entro cui questo preventivo vale, anche se in tabella non
     * c'e': per i preventivi inviati prima del 26/11/2026 la colonna e'
     * stata riempita all'indietro, ma una bozza salvata e mai inviata non
     * ce l'ha e la si calcola al volo.
     */
    public function scadeIl(): CarbonInterface
    {
        if ($this->valid_until) {
            return $this->valid_until;
        }

        // quotes.date e' NOT NULL: una data c'e' sempre, quindi una
        // scadenza si sa sempre calcolare.
        return $this->date->copy()->addDays(self::GIORNI_VALIDITA);
    }

    /**
     * Scaduto nei fatti: la data e' passata, a prescindere da come sia
     * scritto lo stato. Serve per intercettare i preventivi che il comando
     * notturno non ha ancora marcato.
     */
    public function oltreLaValidita(): bool
    {
        return $this->scadeIl()->isPast();
    }

    /**
     * Da quanti giorni aspettiamo una risposta. Si conta dalla data del
     * preventivo, non da `created_at`: i preventivi caricati a mano nel
     * luglio 2026 sono stati creati tutti nello stesso istante, e
     * `created_at` direbbe che hanno tutti la stessa eta'.
     */
    public function giorniDiSilenzio(): ?int
    {
        if (! $this->inAttesa()) {
            return null;
        }

        return (int) $this->date->diffInDays(now());
    }

    /**
     * Ricalcola e aggiorna il totale del preventivo.
     * Il totale di ogni riga è l'imponibile (netto sconto, senza IVA);
     * l'IVA viene calcolata solo nel riepilogo finale.
     */
    public function updateTotal(): void
    {
        $grandSubtotal = 0;
        $grandTaxTotal = 0;

        foreach ($this->quoteProducts as $product) {
            $quantity = (float) ($product->quantity ?? 0);
            $price = (float) ($product->price ?? 0);
            $discount = (float) ($product->discount ?? 0);
            $tax = (float) ($product->tax ?? 0);

            $subtotal = $quantity * $price;
            $discountAmount = $subtotal * ($discount / 100);
            $taxableAmount = $subtotal - $discountAmount;
            $taxAmount = $taxableAmount * ($tax / 100);

            $product->update(['total' => round($taxableAmount, 2)]);

            $grandSubtotal += $taxableAmount;
            $grandTaxTotal += $taxAmount;
        }

        /*
         | I due sconti sono a CASCATA, non sommati: l'extra si applica su
         | quanto resta dopo il generale, come nei listini fornitore "30+5".
         | Su 1.000 € con 30% + 5% restano 665 €, non 650 €.
         |
         | Entrambi valgono anche sull'IVA, altrimenti l'imposta resterebbe
         | calcolata su un imponibile che nessuno paga piu'.
         */
        $residuo = (1 - ((float) ($this->discount ?? 0) / 100))
            * (1 - ((float) ($this->extra_discount ?? 0) / 100));

        $subtotal = $grandSubtotal * $residuo;
        $taxTotal = $grandTaxTotal * $residuo;

        $this->update([
            'subtotal' => round($subtotal, 2),
            'tax_total' => round($taxTotal, 2),
            'total' => round($subtotal + $taxTotal, 2),
        ]);
    }
}
