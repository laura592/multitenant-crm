<?php

namespace Database\Seeders;

use App\Models\Category;
use App\Models\Product;
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
    }
}
