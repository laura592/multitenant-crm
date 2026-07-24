<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Quote extends Model
{
    use BelongsToTenant, HasUuids;

    protected $casts = [
        'date' => 'date',
        'discount' => 'decimal:2',
        'subtotal' => 'decimal:2',
        'tax_total' => 'decimal:2',
        'total' => 'decimal:2',
        'rental_monthly_fee' => 'decimal:2',
        'commission_rate_snapshot' => 'decimal:2',
        'commission_amount' => 'decimal:2',
        'commission_invoiced_at' => 'date',
        'commission_due_at' => 'date',
        'commission_paid_at' => 'date',
    ];

    protected $fillable = [
        'tenant_id',
        'quote_group_id',
        'customer_id',
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
        'commission_scenario',
        'commission_rate_snapshot',
        'commission_amount',
        'commission_direction',
        'commission_status',
        'commission_invoice_number',
        'commission_invoiced_at',
        'commission_due_at',
        'commission_paid_at',
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
                $quote->customer?->markApprovedForGestionale();

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

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function quoteGroup(): BelongsTo
    {
        return $this->belongsTo(QuoteGroup::class);
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
        return $this->hasMany(QuoteEmail::class);
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

        $this->update(array_merge([
            'subtotal' => $subtotal,
            'tax_total' => round($grandTaxTotal - $discountOnTax, 2),
            'total' => round(($grandSubtotal - $discountOnSubtotal) + ($grandTaxTotal - $discountOnTax), 2),
        ], $this->commissionAttributes($subtotal)));
    }

    /**
     * Provvigioni partner secondo gli scenari contrattuali A/B/C
     * (docs/architecture.md §4.3). Nessuna provvigione per il tenant master.
     */
    protected function commissionAttributes(float $subtotal): array
    {
        $tenant = $this->relationLoaded('tenant') ? $this->tenant : Tenant::find($this->tenant_id);

        if (! $tenant || $tenant->is_master || ! $this->commission_scenario) {
            return [
                'commission_rate_snapshot' => null,
                'commission_amount' => null,
                'commission_direction' => null,
            ];
        }

        return match ($this->commission_scenario) {
            'A' => [
                'commission_rate_snapshot' => $tenant->scenario_a_commission_percent,
                'commission_amount' => round($subtotal * $tenant->scenario_a_commission_percent / 100, 2),
                'commission_direction' => 'master_to_partner',
            ],
            'B' => [
                'commission_rate_snapshot' => null,
                'commission_amount' => $tenant->scenario_b_installation_fee,
                'commission_direction' => 'partner_to_master',
            ],
            'C' => [
                'commission_rate_snapshot' => null,
                'commission_amount' => $tenant->scenario_c_preinstallation_fee,
                'commission_direction' => 'partner_to_master',
            ],
            default => [
                'commission_rate_snapshot' => null,
                'commission_amount' => null,
                'commission_direction' => null,
            ],
        };
    }
}
