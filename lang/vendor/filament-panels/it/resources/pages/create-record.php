<?php

/*
 | Traduzione di Filament per la pagina "nuovo record", con una correzione.
 |
 | L'originale del pacchetto (vendor/filament/filament/resources/lang/it/
 | resources/pages/create-record.php) dice 'Nuovo :label', che va bene solo
 | per meta' delle risorse: con un nome femminile usciva "Nuovo offerta
 | caffe'", "Nuovo scadenza", "Nuovo richiesta informazioni", "Nuovo azienda
 | partner"... nove schermate su venticinque.
 |
 | "Crea :label" funziona con qualunque genere e non obbliga a ricordarsi un
 | $title a mano su ogni pagina nuova. Stessa scelta sul breadcrumb.
 |
 | Il resto del file e' identico all'originale: le traduzioni dei pacchetti
 | si sovrascrivono per file intero, non per singola chiave, quindi le altre
 | vanno ricopiate o sparirebbero.
 */

return [

    'title' => 'Crea :label',

    'breadcrumb' => 'Nuovo',

    'form' => [

        'actions' => [

            'cancel' => [
                'label' => 'Annulla',
            ],

            'create' => [
                'label' => 'Salva',
            ],

            'create_another' => [
                'label' => 'Salva & nuovo',
            ],

        ],

    ],

    'notifications' => [

        'created' => [
            'title' => 'Salvato',
        ],

    ],

];
