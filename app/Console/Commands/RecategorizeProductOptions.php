<?php

namespace App\Console\Commands;

use App\Models\Product;
use App\Models\ProductCompatibility;
use App\Models\ProductOptionGroup;
use Illuminate\Console\Command;

/**
 * Molte compatibilita' importate dal DB legacy sono finite nel gruppo
 * generico "other" invece che nella categoria giusta (grinder/steam/powder/
 * ecc.), perche' il catalogo originale non le classificava - la macchina
 * risultava quindi con quasi tutte le opzioni ammassate nello step "Altre
 * opzioni" del wizard invece che negli step dedicati (bug segnalato:
 * "non tutto è ben configurato nelle categorie giuste").
 *
 * Rimappa per NOME prodotto (non tocca nulla che non riconosce: qualsiasi
 * opzione non elencata qui resta in "other" e continua a comparire in
 * "Altre opzioni", mai persa silenziosamente). Idempotente: rieseguibile,
 * aggiorna solo righe ancora in "other".
 */
class RecategorizeProductOptions extends Command
{
    protected $signature = 'products:recategorize-options';

    protected $description = 'Riassegna le compatibilità "other" alla categoria (option group) corretta in base al nome prodotto';

    /** nome prodotto (esatto) -> slug del gruppo di destinazione */
    private const MAPPING = [
        // cooling_unit
        'Collegamento acqua e serbatoio interno 4l (a scelta con/senza scolo)' => 'cooling_unit',
        'KE200 EC - Unità di raffreddamento 4l (a sinistra)' => 'cooling_unit',
        'SU03 EC - Unità di raffreddamento 3l (a sinistra)' => 'cooling_unit',
        'SU05 CM - Unità di raffreddamento 5l con cartuccia pulizia integrata' => 'cooling_unit',
        'SU05 EC - Unità di raffreddamento 5l (sinistra o sottobanco)' => 'cooling_unit',
        'SU12 CM - Unità di raffreddamento 12l (sinistra/destra/sottobanco)' => 'cooling_unit',
        'SU12 CM Twin - Unità di raffreddamento 12l (tra 2 macchine)' => 'cooling_unit',
        'SU12 EC - Unità di raffreddamento 12l (sinistra/destra/sottobanco)' => 'cooling_unit',
        'SU12 EC Twin - Unità di raffreddamento 12l (tra 2 macchine)' => 'cooling_unit',
        "Serbatoio dell'acqua interno 4l (con gocciolatoio monitorato)" => 'cooling_unit',
        'UC09 EC - Unità di raffreddamento sotto la macchina 9l' => 'cooling_unit',

        // grinder
        '2° Macinacaffè' => 'grinder',
        '3° Macinacaffè (posizione anteriore sinistra 600g)' => 'grinder',

        // powder
        '2° Dosatore polvere' => 'powder',
        'Contenitore di caffè in grani chiudibile (con sportello anteriore)' => 'powder',
        'Contenitore polvere doppio' => 'powder',
        'Contenitore polvere singolo' => 'powder',
        'Dosatore polvere' => 'powder',
        'Estensione contenitore per caffè in grani' => 'powder',

        // steam
        'Autosteam Pro S3 (al posto della lancia vapore S1)' => 'steam',
        'Autosteam Pro S3 (al posto di Autosteam S2)' => 'steam',
        'Autosteam S2 (al posto della lancia vapore S1)' => 'steam',
        'Erogatore speciale per bricco (al posto della lancia vapore)' => 'steam',
        'Lancia vapore S1' => 'steam',

        // color (estetica/finiture)
        'Custom RAL (XT)' => 'color',
        'Dynamic Custom Color' => 'color',
        'Kit Noce' => 'color',
        'Kit Rovere' => 'color',
        'RAL Personalizzato' => 'color',
        'Total Blackboard' => 'color',
        'Total Custom RAL (Icon)' => 'color',
        'Total RAL Personalizzato' => 'color',
        'Total Version' => 'color',
        'Versione Dynamic' => 'color',

        // license (FrankeCloud/connettività)
        'FrankeCloud: licenza Manage (2 anni, per rivenditori/partner)' => 'license',
        'Kit di connessione IoT 4G (per FrankeCloud, senza licenze)' => 'license',

        // addon (tutto il resto: accessori/funzioni)
        '2° tipo di latte per 1 macchina (SU12, doppia erogazione)' => 'addon',
        'Caldaia Potenziata' => 'addon',
        'DM (Double Media Pump Module) sovrapprezzo per volumi doppi' => 'addon',
        'DualMilk (DMI) - Riduzione miscelazione con 2 erogatori separati' => 'addon',
        'First Shot (preriscaldamento)' => 'addon',
        'Flavor Station FS30 (3 tipi di sciroppo)' => 'addon',
        'Flavor Station FS60 (6 tipi di sciroppo)' => 'addon',
        'Flavor Station FSU30 sottobanco (3 tipi di sciroppo)' => 'addon',
        'Flavor Station FSU60 sottobanco (6 tipi di sciroppo)' => 'addon',
        'Griglia superiore 2 Gruppi' => 'addon',
        'Griglia superiore 3 Gruppi' => 'addon',
        'Guarnizione stampata (al posto dei piedini)' => 'addon',
        'Iced Coffee Module (First Shot incluso)' => 'addon',
        'IndividualMilk (IMI) - 2 tipi di latte completamente separati' => 'addon',
        'Kit Raccordo Idrico' => 'addon',
        'MCS Doppio' => 'addon',
        'MCS Singolo' => 'addon',
        'MU EC - Modulo pompa latte per frigoriferi terzi (1 o 2 tipi latte)' => 'addon',
        'Monitoraggio del latte per SU05 EC (sensore a parete)' => 'addon',
        'Monitoraggio tazze' => 'addon',
        'OM (One Media Pump Module) incl. contenitore 10l e CMB' => 'addon',
        'Piedini 40 mm (4 pezzi)' => 'addon',
        'Piedini 40 mm SU03 (4 pezzi, per accessorio)' => 'addon',
        'Piedini regolabili 70/100 mm (4 pezzi)' => 'addon',
        'Piedini regolabili 70/100 mm SU03 (4 pezzi, per accessorio)' => 'addon',
        'Portafiltro 58mm' => 'addon',
        'Portafiltro 58mm (Zero+)' => 'addon',
        'Premium Feet' => 'addon',
        'Resistenza Caldaia Vapore 2 Gruppi' => 'addon',
        'Resistenza Caldaia Vapore 3 Gruppi' => 'addon',
        'Riconoscimento tazze ottico' => 'addon',
        'Riconoscimento tazze ottico (anziché monitoraggio)' => 'addon',
        'Scaldatazze' => 'addon',
        'Scaldatazze 2 Gruppi' => 'addon',
        'Scaldatazze 3 Gruppi' => 'addon',
        'Scaldatazze A-Line (per circa 120 tazze)' => 'addon',
        'Scarico fondi (due fori necessari)' => 'addon',
        'Self-Serve Package monitoraggio tazze (All-in-one, First Shot)' => 'addon',
        'Self-Serve Package riconoscimento tazze ottico (All-in-one, First Shot)' => 'addon',
        'Spruzzatore 4 fori' => 'addon',
        'iQFlow (controllo elettronico estrazione e flusso)' => 'addon',
    ];

    public function handle(): int
    {
        $otherGroup = ProductOptionGroup::where('name', 'other')->first();

        if (! $otherGroup) {
            $this->error('Gruppo "other" non trovato.');

            return self::FAILURE;
        }

        $groupIdsByName = ProductOptionGroup::pluck('id', 'name');
        $totalUpdated = 0;
        $notFound = [];

        foreach (self::MAPPING as $productName => $targetSlug) {
            $targetGroupId = $groupIdsByName[$targetSlug] ?? null;

            if (! $targetGroupId) {
                $this->warn("Gruppo di destinazione sconosciuto: {$targetSlug}");

                continue;
            }

            $productIds = Product::where('name', $productName)->pluck('id');

            if ($productIds->isEmpty()) {
                $notFound[] = $productName;

                continue;
            }

            $updated = ProductCompatibility::where('constraint_type', 'compatible')
                ->where('option_group_id', $otherGroup->id)
                ->whereIn('option_product_id', $productIds)
                ->update(['option_group_id' => $targetGroupId]);

            $totalUpdated += $updated;
        }

        $this->info("Righe riassegnate: {$totalUpdated}");

        if ($notFound) {
            $this->warn('Nomi prodotto non trovati nel catalogo (mappatura da rivedere): '.implode(', ', $notFound));
        }

        $remaining = ProductCompatibility::where('constraint_type', 'compatible')
            ->where('option_group_id', $otherGroup->id)
            ->count();

        $this->info("Compatibilità ancora in 'other' (mai nascoste, visibili in \"Altre opzioni\"): {$remaining}");

        return self::SUCCESS;
    }
}
