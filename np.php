<?php

// Un noleggio di prova, solo in locale, per guardare la pagina "visualizza".

use App\Models\Customer;
use App\Models\Noleggio;
use App\Models\NoleggioFornitura;

$c = Customer::withoutGlobalScopes()->whereNotNull('company_name')->first();

$n = Noleggio::create([
    'tenant_id' => $c->tenant_id,
    'customer_id' => $c->id,
    'descrizione' => "Franke A600 FM Plus con unità di raffreddamento",
    'listino' => 18500,
    'sconto_acquisto' => 32,
    'costo' => 12580,
    'ammortamento_base' => 'listino',
    'ammortamento_mesi' => 36,
    'mesi' => 60,
    'margine' => 15,
    'detergenti_mese' => 24,
    'ricarico_detergenti' => 30,
    'caffe_mese' => 310,
    'ricarico_caffe' => 0,
    'valore_residuo' => 1500,
    'full_service_percentuale' => 10,
    'base_consumo' => '250 colazioni al giorno',
    'periodicita_fatturazione' => 'mensile anticipata',
    'modalita_pagamento' => 'bonifico bancario',
    'termini_pagamento' => '30 giorni data fattura',
    'stato' => 'bozza',
    'data_inizio' => now()->startOfMonth(),
]);

foreach ([
    [NoleggioFornitura::GRUPPO_CAFFE, 'Caffè Lyrae Dorsoduro', '1 kg', 360, 17.00],
    [NoleggioFornitura::GRUPPO_POLVERI, 'Cioccolato', '1 kg', 48, 17.80],
    [NoleggioFornitura::GRUPPO_DETERGENTI, 'Pastiglie detergenti', 'conf.', 12, 28.00],
    [NoleggioFornitura::GRUPPO_CONSUMABILI, 'Bicchieri 80 cc', '1000 pz', 24, 19.50],
] as [$g, $desc, $unita, $qta, $prezzo]) {
    NoleggioFornitura::create([
        'tenant_id' => $c->tenant_id,
        'noleggio_id' => $n->id,
        'gruppo' => $g,
        'descrizione' => $desc,
        'unita' => $unita,
        'quantita_annua' => $qta,
        'prezzo_unitario' => $prezzo,
    ]);
}

$n->refresh();
echo $n->id.'  '.$n->number.PHP_EOL;
