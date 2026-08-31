<?php

namespace App\Models;

use App\Mail\CustomerGestionaleReviewMail;
use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Mail;

class Quote extends Model
{
    use BelongsToTenant, HasUuids, SoftDeletes;

    protected $casts = [
        'date' => 'date',
        'discount' => 'decimal:2',
        'subtotal' => 'decimal:2',
        'tax_total' => 'decimal:2',
        'total' => 'decimal:2',
        'rental_monthly_fee' => 'decimal:2',
    ];

    protected $fillable = [
        'tenant_id',
        'quote_group_id',
        'information_request_id',
        'customer_id',
        'billing_customer_id',
        'number',
        'date',
        'status',
        'discount',
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
        });

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
     * Numerazione scoped per tenant: un partner non deve vedere "buchi" dovuti
     * ai preventivi di altri tenant (vedi docs/architecture.md §10.5).
     */
    public static function nextNumberForTenant(?string $tenantId): string
    {
        $year = date('Y');
        $prefix = "PRV-{$year}-";

        $last = static::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->whereYear('created_at', $year)
            ->where('number', 'like', "{$prefix}%")
            ->orderByRaw("CAST(SUBSTRING(number, -4) AS UNSIGNED) DESC")
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

        $generalDiscount = (float) ($this->discount ?? 0);
        $discountOnSubtotal = $grandSubtotal * ($generalDiscount / 100);
        $discountOnTax = $grandTaxTotal * ($generalDiscount / 100);

        $subtotal = round($grandSubtotal - $discountOnSubtotal, 2);

        $this->update([
            'subtotal' => $subtotal,
            'tax_total' => round($grandTaxTotal - $discountOnTax, 2),
            'total' => round(($grandSubtotal - $discountOnSubtotal) + ($grandTaxTotal - $discountOnTax), 2),
        ]);
    }
}
