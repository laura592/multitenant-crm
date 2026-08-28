<?php

namespace Database\Seeders;

use App\Models\Category;
use App\Models\Product;
use App\Models\ProductFamily;
use App\Models\ProductPrice;
use Illuminate\Database\Seeder;

/**
 * Completa a catalogo la famiglia "alloggiamenti conteggio" del listino
 * Franke Classic A Line Italy RRP 2026 (capitolo "Sistemi di conteggio",
 * pagine 25-26 del PDF in Listini).
 *
 * Delle due tabelle del listino — "senza interfaccia" e "incl. interfaccia
 * VIP-1" — a catalogo c'erano solo gli alloggiamenti nudi e le due versioni
 * con gettoniera/cambiamonete gia' montate: mancavano le predisposizioni e
 * tutta la variante senza interfaccia. Chi doveva quotare una gettoniera su
 * un alloggiamento senza VIP-1 non aveva la riga giusta e finiva per usare
 * quella con VIP-1, 355 euro piu' cara.
 *
 * Codici e prezzi presi riga per riga dal listino, non calcolati: gli scarti
 * sono regolari (VIP-1 +355, gettoniera G13 +692, cambiamonete GRY +2290,
 * predisposizioni +157/+905) ma sul preventivo finisce il numero d'ordine
 * Franke, e quello va copiato, non dedotto.
 *
 * NON aggiunge le righe generiche "[variable card readers]" del listino New
 * A Line (1978/1143): li' il numero d'ordine compare solo nella didascalia
 * di una figura, e un codice d'ordine tirato a indovinare finisce su un
 * ordine vero. Da chiedere a Franke.
 *
 * Idempotente: firstOrCreate per sku, e il prezzo si aggiunge solo se quel
 * prodotto non ne ha gia' uno valido per il 2026.
 */
class FrankeAccountingHousingsSeeder extends Seeder
{
    /**
     * @var array<int, array{sku: string, name: string, price: float}>
     */
    private const ALLOGGIAMENTI = [
        // Senza interfaccia (listino pag. 25-26)
        ['sku' => '560.0678.355', 'name' => 'Alloggiamento conteggio SU03 CL (senza interfaccia)', 'price' => 325],
        ['sku' => '560.0543.638', 'name' => 'Alloggiamento conteggio AC200 predisposto per gettoniera (senza interfaccia)', 'price' => 1642],
        ['sku' => '560.0550.061', 'name' => 'Alloggiamento conteggio AC200 con gettoniera G13 (senza interfaccia)', 'price' => 2177],
        ['sku' => '560.0506.523', 'name' => 'Alloggiamento conteggio AC200 predisposto per cambiamonete (senza interfaccia)', 'price' => 2390],
        ['sku' => '560.0550.062', 'name' => 'Alloggiamento conteggio AC200 con cambiamonete CPI Gryphon (senza interfaccia)', 'price' => 3775],
        // Incl. interfaccia VIP-1 (listino pag. 26)
        ['sku' => '560.0678.327', 'name' => 'Alloggiamento conteggio SU03 CL (VIP-1)', 'price' => 680],
        ['sku' => '560.0506.528', 'name' => 'Alloggiamento conteggio AC200 predisposto per gettoniera (VIP-1)', 'price' => 1997],
        ['sku' => '560.0543.634', 'name' => 'Alloggiamento conteggio AC200 predisposto per cambiamonete (VIP-1)', 'price' => 2745],
    ];

    /**
     * Il nome a catalogo diceva solo "Gettoniera G13 (AC200)": sembrava la
     * gettoniera da sola, mentre e' l'alloggiamento con la gettoniera gia'
     * montata — e ora che accanto c'e' anche la versione senza interfaccia
     * (2177) la differenza va letta dal nome.
     *
     * @var array<string, string>
     */
    private const RINOMINE = [
        '560.0543.637' => 'Alloggiamento conteggio AC200 con gettoniera G13 (VIP-1)',
        '560.0514.908' => 'Alloggiamento conteggio AC200 con cambiamonete CPI Gryphon (VIP-1)',
        // E i quattro alloggiamenti nudi che c'erano gia': si chiamavano
        // "standard"/"compatto" (la parola del listino) mentre le altre dieci
        // righe della famiglia dicono la sigla e poi l'interfaccia fra
        // parentesi. Uniformarli non e' cosmesi: e' cosi' che
        // ConfiguraConteggioAction riconosce la famiglia.
        'AC200-STANDARD' => 'Alloggiamento conteggio AC200 (senza interfaccia)',
        'AC200-VIP1' => 'Alloggiamento conteggio AC200 (VIP-1)',
        'AC125-COMPACT' => 'Alloggiamento conteggio AC125 (senza interfaccia)',
        'AC125-VIP1' => 'Alloggiamento conteggio AC125 (VIP-1)',
    ];

    /**
     * Il conteggio "SU03 CL" non e' un alloggiamento a se': sta DENTRO
     * l'unita' di raffreddamento SU03 EC, e il listino lo dice in nota —
     * "Prezzo in aggiunta al prezzo dell'unita' di raffreddamento SU03 EC,
     * il sistema di conteggio e' parte dell'unita' di raffreddamento".
     * Quei 325/680/818 sono quindi supplementi sui 1.170 della SU03 EC: a
     * catalogo non lo diceva niente, e un preventivo con la sola riga di
     * conteggio sarebbe sbagliato di 1.170 euro.
     */
    public const FAMIGLIA = 'Sistemi di conteggio';

    private const NOTA_SU03 = 'Supplemento sull\'unita\' di raffreddamento SU03 EC (1.170 €): il sistema di conteggio e\' integrato nella SU03 EC, non e\' un alloggiamento separato.';

    /** @var array<int, string> */
    private const SKU_SU03 = [
        '560.0678.355', '560.0678.327', '560.0678.325', '560.0685.324',
        '560.0678.943', '560.0678.326', '560.0678.331', '560.0677.883',
    ];

    public function run(): void
    {
        $category = Category::query()->where('name', 'Accessori Franke')->first();

        foreach (self::ALLOGGIAMENTI as $riga) {
            $product = Product::withoutGlobalScopes()->firstOrCreate(
                ['sku' => $riga['sku']],
                [
                    // tenant_id null: catalogo condiviso, come gli altri
                    // articoli Franke (vedi SharedAcrossTenants).
                    'tenant_id' => null,
                    'category_id' => $category?->id,
                    'type' => Product::TYPE_ACCESSORY,
                    'name' => $riga['name'],
                    'source' => Product::SOURCE_FRANKE,
                ],
            );

            $haPrezzo2026 = $product->prices()
                ->where('price', $riga['price'])
                ->whereDate('valid_from', '2026-01-01')
                ->exists();

            if (! $haPrezzo2026) {
                ProductPrice::create([
                    'product_id' => $product->id,
                    'price' => $riga['price'],
                    'valid_from' => '2026-01-01',
                ]);
            }
        }

        foreach (self::RINOMINE as $sku => $name) {
            Product::withoutGlobalScopes()->where('sku', $sku)->update(['name' => $name]);
        }

        Product::withoutGlobalScopes()
            ->whereIn('sku', self::SKU_SU03)
            ->whereNull('description')
            ->update(['description' => self::NOTA_SU03]);

        $this->rinominaPerLettore();
        $this->raggruppaInFamiglia();
    }

    /**
     * I sistemi di conteggio diventano una famiglia come le macchine, cosi'
     * si raggiungono dal primo passo di "Configura macchina": senza, ci si
     * arrivava solo configurando una macchina nuova, e un cliente che vuole
     * aggiungere il pagamento a una macchina che ha gia' restava fuori.
     */
    private function raggruppaInFamiglia(): void
    {
        $famiglia = ProductFamily::firstOrCreate(['name' => self::FAMIGLIA]);

        Product::withoutGlobalScopes()
            ->where('name', 'like', 'Alloggiamento conteggio%')
            ->whereNull('product_family_id')
            ->update(['product_family_id' => $famiglia->id]);
    }

    /**
     * "Microtronic Mifare [meiPay-MBH] con VIP-1 (AC125)" non diceva la cosa
     * piu' importante: che quel prodotto E' l'alloggiamento AC125, non un
     * pezzo da aggiungere a un AC125 comprato a parte. Domanda arrivata
     * dall'utente in questa forma esatta ("+ ac125?"), e il rischio e'
     * concreto: mettere in preventivo due volte lo stesso alloggiamento.
     *
     * Diventano "Alloggiamento conteggio AC125 per lettore Microtronic
     * Mifare [meiPay-MBH] (VIP-1)", cioe' la stessa forma delle altre
     * quattordici righe della famiglia.
     */
    private function rinominaPerLettore(): void
    {
        $contanti = ['G13' => 'con gettoniera G13', 'GRY' => 'con cambiamonete CPI Gryphon'];

        Product::withoutGlobalScopes()
            ->where('sku', 'like', '560.%')
            // Non '%con VIP-1 (%': le righe con gettoniera o cambiamonete
            // hanno "con VIP-1 e G13 (AC200)", e restavano fuori.
            ->where('name', 'like', '%con VIP-1%')
            ->get()
            ->each(function (Product $product) use ($contanti) {
                if (! preg_match('/^(.+) con VIP-1(?: e (G13|GRY))? \((AC200|AC125|SU03 CL)\)$/u', $product->name, $m)) {
                    return;
                }

                [, $lettore, $contante, $alloggiamento] = $m + [3 => ''];

                $product->update([
                    'name' => trim(implode(' ', array_filter([
                        "Alloggiamento conteggio {$alloggiamento}",
                        "per lettore {$lettore}",
                        $contante ? $contanti[$contante] : null,
                        '(VIP-1)',
                    ]))),
                ]);
            });
    }
}
