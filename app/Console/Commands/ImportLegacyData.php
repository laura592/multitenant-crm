<?php

namespace App\Console\Commands;

use App\Models\Category;
use App\Models\ComodatoMacchina;
use App\Models\Customer;
use App\Models\InformationRequest;
use App\Models\PaymentMethod;
use App\Models\Product;
use App\Models\ProductCompatibility;
use App\Models\ProductFamily;
use App\Models\ProductOptionGroup;
use App\Models\ProductPrice;
use App\Models\Quote;
use App\Models\QuoteEmail;
use App\Models\QuoteProduct;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Importa i dati dal DB di produzione legacy (nbalexca_app_preventivi.sql,
 * connessione "legacy") nel nuovo schema multi-tenant. Vedi
 * docs/architecture.md §8.1 (piano di migrazione) e §11.2 (catalogo).
 *
 * Catalogo (categories/products/...) -> tenant_id NULL, condiviso con tutti
 * i partner. Tutto il resto (customers/quotes/...) -> tenant master (Alex).
 */
class ImportLegacyData extends Command
{
    protected $signature = 'import:legacy {--force : Importa anche se sono gia presenti dei dati}';

    protected $description = 'Importa i dati dal DB di produzione legacy nel nuovo schema multi-tenant';

    /** @var array<int, string> */
    protected array $categoryMap = [];

    /** @var array<int, string> id legacy -> nome categoria, per dedurre la source dei prodotti */
    protected array $categoryNameMap = [];

    /** @var array<int, string> */
    protected array $productMap = [];

    /** @var array<string, string> */
    protected array $optionGroupMap = [];

    /** @var array<int, string> */
    protected array $customerMap = [];

    /** @var array<int, string> */
    protected array $quoteMap = [];

    /** @var array<int, string> */
    protected array $informationRequestMap = [];

    /** @var array<int, string> */
    protected array $userMap = [];

    protected const QUOTE_STATUS_MAP = [
        'draft' => 'bozza',
        'sent' => 'inviato',
        'accepted' => 'accettato',
        'rejected' => 'rifiutato',
    ];

    public function handle(): int
    {
        if (! $this->option('force') && Customer::count() > 0) {
            $this->error('Sono già presenti dei clienti nel nuovo DB. Usa --force per importare comunque (rischio duplicati).');

            return self::FAILURE;
        }

        $legacy = DB::connection('legacy');

        DB::transaction(function () use ($legacy) {
            $master = $this->resolveMasterTenant();

            $this->importPaymentMethods($legacy);
            $this->importUsers($legacy, $master);
            $this->importCategories($legacy);
            $this->importProducts($legacy);
            $this->importProductPrices($legacy);
            $this->importProductCompatibilities($legacy);
            $this->importCustomers($legacy, $master);
            $this->importQuotes($legacy, $master);
            $this->importQuoteProducts($legacy);
            $this->importQuoteEmails($legacy);
            $this->importInformationRequests($legacy, $master);
            $this->importInformationRequestProducts($legacy);
            $this->importComodatoMacchine($legacy, $master);
        });

        $this->info('Import completato.');

        return self::SUCCESS;
    }

    protected function resolveMasterTenant(): Tenant
    {
        return Tenant::firstOrCreate(
            ['is_master' => true],
            ['name' => 'Alex', 'legal_name' => 'Alex S.r.l.', 'slug' => 'alex', 'is_active' => true]
        );
    }

    protected function importPaymentMethods($legacy): void
    {
        $rows = $legacy->table('payment_methods')->get();

        foreach ($rows as $row) {
            PaymentMethod::create([
                'name' => $row->name,
                'slug' => $row->slug,
                'is_active' => (bool) $row->is_active,
                'sort_order' => $row->sort_order,
                'created_at' => $row->created_at,
                'updated_at' => $row->updated_at,
            ]);
        }

        $this->info("Metodi di pagamento importati: {$rows->count()}");
    }

    protected function importUsers($legacy, Tenant $master): void
    {
        $rows = $legacy->table('users')->get();

        foreach ($rows as $row) {
            $isSuperAdmin = $row->role === 'admin';

            $user = User::create([
                'tenant_id' => $isSuperAdmin ? null : $master->id,
                'is_super_admin' => $isSuperAdmin,
                'name' => $row->name,
                'email' => $row->email,
                'password' => $row->password, // già hashata (bcrypt), compatibile
                'email_verified_at' => $row->email_verified_at,
                'created_at' => $row->created_at,
                'updated_at' => $row->updated_at,
            ]);

            $this->userMap[$row->id] = $user->id;
        }

        $this->info("Utenti importati: {$rows->count()}");
    }

    protected function importCategories($legacy): void
    {
        $rows = $legacy->table('categories')->get();

        foreach ($rows as $row) {
            $category = Category::create([
                'tenant_id' => null, // condivisa (§4.2)
                'name' => $row->name,
                'created_at' => $row->created_at,
                'updated_at' => $row->updated_at,
            ]);

            $this->categoryMap[$row->id] = $category->id;
            $this->categoryNameMap[$row->id] = $row->name;
        }

        $this->info("Categorie importate: {$rows->count()}");
    }

    /**
     * Il catalogo legacy include piu' marchi (Franke, Dalla Corte, Jura), non
     * solo Franke: la sorgente va dedotta dal nome categoria, non assunta
     * fissa. Ambiguo (es. trattamento acqua) -> null, non un valore a caso.
     */
    protected const CATEGORY_SOURCE_MAP = [
        'Macchine Caffè Franke' => Product::SOURCE_FRANKE,
        'Opzioni Franke' => Product::SOURCE_FRANKE,
        'Accessori Franke' => Product::SOURCE_FRANKE,
        'Macchine NEW A Line' => Product::SOURCE_FRANKE,
        'Opzioni NEW A Line' => Product::SOURCE_FRANKE,
        'Opzioni Alex' => Product::SOURCE_THIRD_PARTY,
        'Macchine Dalla Corte' => Product::SOURCE_THIRD_PARTY,
        'Opzioni Dalla Corte' => Product::SOURCE_THIRD_PARTY,
        'Macinacaffè Dalla Corte' => Product::SOURCE_THIRD_PARTY,
        'Macchine Jura' => Product::SOURCE_THIRD_PARTY,
    ];

    /** Famiglie macchina reali Franke (dal listino ufficiale). */
    protected const FAMILY_CODES = ['SB1200', 'A1000', 'A300', 'A400', 'A600', 'A800', 'S700'];

    /** @var array<string, string> nome famiglia -> id */
    protected array $familyMap = [];

    protected function importProducts($legacy): void
    {
        $rows = $legacy->table('products')->get();

        foreach ($rows as $row) {
            $categoryName = $this->categoryNameMap[$row->category_id] ?? null;
            $type = $row->type === 'base' ? Product::TYPE_MACHINE : Product::TYPE_OPTION;

            $product = Product::create([
                'tenant_id' => null, // catalogo condiviso (§4.2)
                'category_id' => $this->categoryMap[$row->category_id] ?? null,
                'product_family_id' => $type === Product::TYPE_MACHINE
                    ? $this->resolveFamily($row->sku, $row->name)
                    : null,
                'sku' => $row->sku ?: "LEGACY-{$row->id}",
                'type' => $type,
                'name' => $row->name,
                'description' => $row->description,
                'image' => $row->image,
                'source' => self::CATEGORY_SOURCE_MAP[$categoryName] ?? null,
                'created_at' => $row->created_at,
                'updated_at' => $row->updated_at,
            ]);

            $this->productMap[$row->id] = $product->id;
        }

        $this->info("Prodotti importati: {$rows->count()}");
    }

    /**
     * Deduce la famiglia (A300, A600, SB1200, ...) dal codice iniziale dello
     * SKU o del nome, ignorando prefissi tipo "2X-" (doppia macchina).
     */
    protected function resolveFamily(?string $sku, ?string $name): ?string
    {
        $haystack = strtoupper(preg_replace('/^2X-?/i', '', $sku ?? $name ?? ''));

        foreach (self::FAMILY_CODES as $code) {
            if (str_starts_with($haystack, $code)) {
                if (! isset($this->familyMap[$code])) {
                    $family = ProductFamily::create(['tenant_id' => null, 'name' => $code]);
                    $this->familyMap[$code] = $family->id;
                }

                return $this->familyMap[$code];
            }
        }

        return null;
    }

    protected function importProductPrices($legacy): void
    {
        $rows = $legacy->table('product_prices')->get();
        $count = 0;

        foreach ($rows as $row) {
            if (! isset($this->productMap[$row->product_id])) {
                continue;
            }

            ProductPrice::create([
                'product_id' => $this->productMap[$row->product_id],
                'price' => $row->price,
                'valid_from' => $row->valid_from,
                'valid_to' => $row->valid_to,
                'created_at' => $row->created_at,
                'updated_at' => $row->updated_at,
            ]);
            $count++;
        }

        $this->info("Prezzi prodotto importati: {$count}");
    }

    protected function importProductCompatibilities($legacy): void
    {
        $rows = $legacy->table('product_compatibilities')->get();
        $count = 0;

        foreach ($rows as $row) {
            if (! isset($this->productMap[$row->base_product_id], $this->productMap[$row->option_product_id])) {
                continue;
            }

            ProductCompatibility::create([
                'base_product_id' => $this->productMap[$row->base_product_id],
                'option_product_id' => $this->productMap[$row->option_product_id],
                'option_group_id' => $this->resolveOptionGroup($row->option_group),
                'constraint_type' => $row->is_required ? ProductCompatibility::CONSTRAINT_REQUIRED : ProductCompatibility::CONSTRAINT_COMPATIBLE,
                'sort_order' => $row->sort_order,
                'created_at' => $row->created_at,
                'updated_at' => $row->updated_at,
            ]);
            $count++;
        }

        $this->info("Compatibilità prodotto importate: {$count}");
    }

    /**
     * Nello schema legacy option_group era una stringa libera. Ogni valore
     * distinto diventa un ProductOptionGroup condiviso (default: selezione
     * multipla, l'admin potrà affinare a "single" i gruppi realmente
     * esclusivi tramite l'interfaccia, §11.2).
     */
    protected function resolveOptionGroup(?string $key): string
    {
        $key = $key ?: 'other';

        if (! isset($this->optionGroupMap[$key])) {
            $group = ProductOptionGroup::create([
                'tenant_id' => null,
                'name' => $key,
                'label' => Str::headline($key),
                'selection_type' => ProductOptionGroup::SELECTION_MULTIPLE,
            ]);
            $this->optionGroupMap[$key] = $group->id;
        }

        return $this->optionGroupMap[$key];
    }

    protected function importCustomers($legacy, Tenant $master): void
    {
        $rows = $legacy->table('customers')->get();

        foreach ($rows as $row) {
            $customer = Customer::create([
                'tenant_id' => $master->id,
                'first_name' => $row->first_name,
                'last_name' => $row->last_name,
                'company_name' => $row->company_name,
                'street' => $row->street,
                'postal_code' => $row->postal_code,
                'city' => $row->city,
                'province' => $row->province,
                'email' => $row->email,
                'mobile' => $row->mobile,
                'tax_code' => $row->tax_code,
                'vat_number' => $row->vat_number,
                'sdi' => $row->sdi,
                'created_at' => $row->created_at,
                'updated_at' => $row->updated_at,
            ]);

            $this->customerMap[$row->id] = $customer->id;
        }

        $this->info("Clienti importati: {$rows->count()}");
    }

    protected function importQuotes($legacy, Tenant $master): void
    {
        $rows = $legacy->table('quotes')->get();
        $count = 0;

        foreach ($rows as $row) {
            if (! isset($this->customerMap[$row->customers_id])) {
                continue;
            }

            $quote = Quote::create([
                'tenant_id' => $master->id,
                'customer_id' => $this->customerMap[$row->customers_id],
                'number' => $row->number,
                'date' => $row->date,
                'status' => self::QUOTE_STATUS_MAP[$row->status] ?? $row->status,
                'discount' => $row->discount,
                'notes' => $row->notes,
                'payment_method' => $row->payment_method,
                'subtotal' => $row->subtotal ?? 0,
                'tax_total' => $row->tax_total ?? 0,
                'total' => $row->total,
                'created_at' => $row->created_at,
                'updated_at' => $row->updated_at,
            ]);

            $this->quoteMap[$row->id] = $quote->id;
            $count++;
        }

        $this->info("Preventivi importati: {$count}");
    }

    protected function importQuoteProducts($legacy): void
    {
        $rows = $legacy->table('quote_products')->get();
        $count = 0;

        foreach ($rows as $row) {
            if (! isset($this->quoteMap[$row->quote_id], $this->productMap[$row->product_id])) {
                continue;
            }

            QuoteProduct::create([
                'quote_id' => $this->quoteMap[$row->quote_id],
                'product_id' => $this->productMap[$row->product_id],
                'parent_quote_product_id' => null, // nessuna gerarchia nello schema legacy
                'quantity' => $row->quantity,
                'price' => $row->price,
                'discount' => $row->discount,
                'tax' => $row->tax,
                'total' => $row->total,
                'created_at' => $row->created_at,
                'updated_at' => $row->updated_at,
            ]);
            $count++;
        }

        $this->info("Righe preventivo importate: {$count}");
    }

    protected function importQuoteEmails($legacy): void
    {
        $rows = $legacy->table('quote_emails')->get();
        $count = 0;

        foreach ($rows as $row) {
            if (! isset($this->quoteMap[$row->quote_id])) {
                continue;
            }

            QuoteEmail::create([
                'quote_id' => $this->quoteMap[$row->quote_id],
                'user_id' => $this->userMap[$row->user_id] ?? null,
                'recipient_email' => $row->recipient_email,
                'cc_email' => $row->cc_email,
                'subject' => $row->subject,
                'message' => $row->message,
                'status' => $row->status,
                'error_message' => $row->error_message,
                'created_at' => $row->created_at,
                'updated_at' => $row->updated_at,
            ]);
            $count++;
        }

        $this->info("Email preventivo importate: {$count}");
    }

    protected function importInformationRequests($legacy, Tenant $master): void
    {
        $rows = $legacy->table('information_requests')->get();
        $count = 0;

        foreach ($rows as $row) {
            if (! isset($this->customerMap[$row->customers_id])) {
                continue;
            }

            $request = InformationRequest::create([
                'tenant_id' => $master->id,
                'customer_id' => $this->customerMap[$row->customers_id],
                'number' => $row->number,
                'request_details' => $row->request_details,
                'status' => $row->status,
                'handled_by' => $row->handled_by,
                'handled_by_user_id' => $this->userMap[$row->handled_by_user_id] ?? null,
                'created_at' => $row->created_at,
                'updated_at' => $row->updated_at,
            ]);

            $this->informationRequestMap[$row->id] = $request->id;
            $count++;
        }

        $this->info("Richieste informazioni importate: {$count}");
    }

    protected function importInformationRequestProducts($legacy): void
    {
        $rows = $legacy->table('information_request_product')->get();
        $count = 0;

        foreach ($rows as $row) {
            if (! isset($this->informationRequestMap[$row->information_request_id], $this->productMap[$row->product_id])) {
                continue;
            }

            DB::table('information_request_product')->insert([
                'id' => (string) Str::orderedUuid(),
                'information_request_id' => $this->informationRequestMap[$row->information_request_id],
                'product_id' => $this->productMap[$row->product_id],
                'created_at' => $row->created_at,
                'updated_at' => $row->updated_at,
            ]);
            $count++;
        }

        $this->info("Prodotti su richieste informazioni importati: {$count}");
    }

    protected function importComodatoMacchine($legacy, Tenant $master): void
    {
        $rows = $legacy->table('comodato_macchine')->get();

        foreach ($rows as $row) {
            ComodatoMacchina::create([
                'tenant_id' => $master->id,
                'customer_id' => null, // non presente nello schema legacy
                'nome_macchina' => $row->nome_macchina,
                'costo_macchina' => $row->costo_macchina,
                'costo_attrezzatura' => $row->costo_attrezzatura,
                'anni_ammortamento' => $row->anni_ammortamento,
                'prezzo_annuale_consumabili' => $row->prezzo_annuale_consumabili,
                'costi_manutenzione_annui' => $row->costi_manutenzione_annui,
                'costo_caffe_per_battitura' => $row->costo_caffe_per_battitura,
                'erogazioni_annuali_minime' => $row->erogazioni_annuali_minime,
                'erogazioni_previste_annue' => $row->erogazioni_previste_annue,
                'canone_fisso_annuale' => $row->canone_fisso_annuale,
                'margine_percentuale' => $row->margine_percentuale,
                'note' => $row->note,
                'created_at' => $row->created_at,
                'updated_at' => $row->updated_at,
            ]);
        }

        $this->info("Comodato macchine importati: {$rows->count()}");
    }
}
