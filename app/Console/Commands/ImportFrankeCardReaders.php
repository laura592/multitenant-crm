<?php

namespace App\Console\Commands;

use App\Models\Category;
use App\Models\Product;
use Illuminate\Console\Command;

/**
 * Aggiunge i lettori di carte/gettoniere specifici per marca dal listino
 * "37 Italy RRP EUR Classic A Line ITA_2026_V1.0.pdf" (Sistemi di conteggio,
 * pag. 24-26), non presenti nel catalogo legacy (che aveva solo le versioni
 * generiche AC125/AC200). Idempotente: usa updateOrCreate sullo SKU (numero
 * ordine Franke reale).
 */
class ImportFrankeCardReaders extends Command
{
    protected $signature = 'import:franke-card-readers';

    protected $description = 'Aggiunge i lettori di carte/gettoniere per marca dal listino Franke 2026';

    /**
     * [sku, nome, prezzo]
     */
    protected const READERS = [
        // Sistema con carte prepagate
        ['560.0568.239', 'Coges [COGES ENGINE] con VIP-1 (AC200)', 1978],
        ['560.0597.990', 'Coges [COGES ENGINE] con VIP-1 (AC125)', 1143],
        ['560.0528.801', 'Contidata [Maxx Vend 5] con VIP-1 (AC200)', 1978],
        ['560.0599.322', 'Contidata [Maxx Vend 5] con VIP-1 e G13 (AC200)', 2670],
        ['560.0559.805', 'Contidata [Maxx Vend 5] con VIP-1 e GRY (AC200)', 4268],
        ['560.0533.708', 'Dallmayr [Ultimo P100] con VIP-1 (AC200)', 1978],
        ['560.0598.011', 'Dallmayr [Ultimo P100] con VIP-1 (AC125)', 1143],
        ['560.0568.456', 'Evis [Kiwi] con VIP-1 (AC200)', 1978],
        ['560.0598.012', 'Evis [Kiwi] con VIP-1 (AC125)', 1143],
        ['560.0678.325', 'Evis [Kiwi] con VIP-1 (SU03 CL)', 818],
        ['560.0685.324', 'Evis [GECKO2B2] con VIP-1 (SU03 CL)', 818],
        ['560.0506.405', 'GiroWeb [WinVMC-GW2] con VIP-1 (AC200)', 1978],
        ['560.0511.311', 'GiroWeb [Sirius-GW2] con VIP-1 (AC200)', 1978],
        ['560.0586.353', 'Intercard [vendIn-RHD] con VIP-1 (AC200)', 1978],
        ['560.0678.943', 'Kalisch [smartMDB-TWN4] con VIP-1 (SU03 CL)', 818],
        ['560.0531.489', 'Magna Carta [Legic VM3050] con VIP-1 (AC200)', 1978],
        ['560.0534.042', 'OPC Card System [OPC Mifare] con VIP-1 (AC200)', 1978],
        ['560.0528.923', 'Paycult [Carus] con VIP-1 (AC200)', 1978],
        ['560.0598.027', 'Paycult [Carus] con VIP-1 (AC125)', 1143],
        ['560.0011.718', 'Microtronic Mifare [meiPay-MBH] con VIP-1 (AC200)', 1978],
        ['560.0598.019', 'Microtronic Mifare [meiPay-MBH] con VIP-1 (AC125)', 1143],
        ['560.0678.326', 'Microtronic Mifare [meiPay-MBH] con VIP-1 (SU03 CL)', 818],
        ['560.0511.297', 'Pecusoft [Pecucard] con VIP-1 (AC200)', 1978],
        ['560.0569.953', 'Schmidt Systeme [EPay4500-RH] con VIP-1 (AC200)', 1978],
        ['560.0628.480', 'Schmidt Systeme [EPay4500-RH] con VIP-1 (AC125)', 1143],
        ['560.0533.707', 'Seitz [Smartbox CPU4000] con VIP-1 (AC200)', 1978],
        ['560.0622.853', 'Sirius [Sirius-GW2] con VIP-1 (AC125)', 1143],
        ['560.0534.177', 'Logos Design [Vending CAT V3] con VIP-1 (AC200)', 1978],
        ['560.0511.298', 'Ventopay [Mocca.Vend] con VIP-1 (AC200)', 1978],
        ['560.0598.021', 'Ventopay [Mocca.Vend] con VIP-1 (AC125)', 1143],
        ['560.0677.883', 'Ventopay [moccaV-RH] con VIP-1 (SU03 CL)', 818],
        // Sistemi di carte di credito
        ['560.0533.704', 'Ingenico [iUC180B] con VIP-1 (AC200)', 1978],
        ['560.0598.022', 'Ingenico [iUC180B] con VIP-1 (AC125)', 1143],
        ['560.0578.361', 'Ingenico [iUC180B] con VIP-1 e G13 (AC200)', 2670],
        ['560.0578.362', 'Ingenico [iUC180B] con VIP-1 e GRY (AC200)', 4268],
        ['560.0582.646', 'CPI [MEI ADV5000] con VIP-1 e G13 (AC200)', 2670],
        ['560.0560.268', 'CPI [MEI ADV5000] con VIP-1 e GRY (AC200)', 4268],
        ['560.0625.823', 'Nayax [Onyx] con VIP-1 (AC200)', 1978],
        ['560.0625.601', 'Nayax [Onyx] con VIP-1 (AC125)', 1143],
        ['560.0678.331', 'Nayax [Onyx] con VIP-1 (SU03 CL)', 818],
        ['560.0626.144', 'Nayax [Onyx] con VIP-1 e G13 (AC200)', 2670],
        ['560.0626.216', 'Nayax [Onyx] con VIP-1 e GRY (AC200)', 4268],
        ['560.0578.338', 'Feig [cVENDT] con VIP-1 (AC200)', 1978],
        ['560.0598.013', 'Feig [cVENDT] con VIP-1 (AC125)', 1143],
        ['560.0578.333', 'Feig [cVENDT] con VIP-1 e G13 (AC200)', 2670],
        // Sistema con contanti
        ['560.0543.637', 'Gettoniera G13 (AC200)', 2532],
        ['560.0514.908', 'Cambiamonete CPI Gryphon (AC200)', 4130],
    ];

    public function handle(): int
    {
        $category = Category::firstOrCreate(['tenant_id' => null, 'name' => 'Accessori Franke']);

        $count = 0;

        foreach (self::READERS as [$sku, $name, $price]) {
            $product = Product::updateOrCreate(
                ['sku' => $sku],
                [
                    'tenant_id' => null,
                    'category_id' => $category->id,
                    'type' => Product::TYPE_ACCESSORY,
                    'name' => $name,
                    'source' => Product::SOURCE_FRANKE,
                ]
            );

            $product->prices()->updateOrCreate(
                ['valid_from' => '2026-01-01'],
                ['price' => $price]
            );

            $count++;
        }

        $this->info("Lettori di carte importati/aggiornati: {$count}");

        return self::SUCCESS;
    }
}
