<?php

/**
 * Codici tariffa per pagante.
 *
 * Alcuni clienti che pagano per conto di altri (Martellozzo, Goppion, Spigola…)
 * hanno un proprio listino per chiamata, manodopera e lavaggio: sono codici
 * gia' presenti a catalogo, che pero' vanno scelti a mano ad ogni rapportino e
 * quindi finiscono quasi sempre sostituiti da quelli standard.
 *
 * Qui la scelta e' automatica: le scorciatoie del rapportino ("Chiamata",
 * "Manodopera", "Lavaggio eseguito") leggono questa tabella partendo dal
 * pagante del cliente e, se manca la voce, ricadono sui codici standard.
 *
 * La chiave e' il codice gestionale del pagante, che non cambia mai (il nome
 * si', ed esistono omonimie: Goppion Caffe' e Magazzino Goppion).
 *
 * IMPORTANTE: vale solo per i rapportini nuovi. Quelli gia' salvati, compresi
 * i 200+ importati da Eureka, non vengono toccati.
 */
return [

    'paganti' => [

        // Martellozzo Lorenzo & C. SAS — 32 clienti
        1178 => [
            'nome' => 'Martellozzo',
            'chiamata' => 'CHIMART',
            'chiamata_festiva' => 'CHIFEMART',
            'manodopera' => 'OREMART',
            'manodopera_festiva' => 'OREFEMART',
            // A catalogo ci sono due codici quasi uguali: LAVMART (28,00,
            // "LAVAGGIO 2 VIE MARTELLOZZO") e LAV2MART (27,50, descritto solo
            // come "LAVAGGIO 2 VIE"). Quello giusto e' LAV2MART, corretto
            // dall'ufficio il 2026-09-03: ribalta la nota del 2026-08-31, che
            // aveva scelto LAVMART perche' e' quello col nome del pagante.
            //
            // Il nome inganna, lo storico no: 293 righe LAV2MART dal 2023 al
            // 01/09/2026 contro 17 LAVMART sporadiche. Prima di rimetterlo
            // "a posto" guardando la descrizione a catalogo, ricontrolla li'.
            'lavaggio' => 'LAV2MART',
            'lavaggio_ulteriore_via' => 'ULTVIAMART',
        ],

        // Goppion Caffe' SPA — 16 clienti, vale per tutti
        782 => [
            'nome' => 'Goppion',
            'chiamata' => 'CHIORDGOP',
            'manodopera' => 'OREGOPPION',
        ],

        // Spigola SRL — 11 clienti. La chiamata e' sempre CHIVE, anche per i
        // clienti senza citta' in anagrafica: non dipende da dove sono.
        1629 => [
            'nome' => 'Spigola',
            'chiamata' => 'CHIVE',
            'manodopera' => 'ORESPIGOLA',
        ],

        // Hts 1892 SPA — 7 clienti
        942 => [
            'nome' => 'HTS',
            'chiamata' => 'CHIVEHTS',
            'manodopera' => 'OREHTS',
        ],

        // Danieli Management SRL
        561 => [
            'nome' => 'Danieli',
            'chiamata' => 'CHIDAN',
            'manodopera' => 'OREDAN',
        ],

        // Mioni Pezzato & SPA
        3129 => [
            'nome' => 'Mioni Pezzato',
            'chiamata' => 'CHIMIONI',
            'manodopera' => 'OREMIONI',
        ],
    ],

    /**
     * Codici usati quando il pagante non ha un listino suo (o non c'e' un
     * pagante): sono quelli di sempre. La chiamata resta legata alla citta',
     * perche' li' dipende davvero da dove si va: CHIVE per Venezia centro
     * storico, raggiungibile solo via acqua, CHIORD altrove.
     */
    'standard' => [
        'chiamata' => 'CHIORD',
        'chiamata_venezia' => 'CHIVE',
        'chiamata_festiva' => 'CHIFE',
        'manodopera' => 'ORE',
        'manodopera_festiva' => 'OREFEST',
        'lavaggio' => 'LAV2',
        'lavaggio_ulteriore_via' => 'ULTVIA',
    ],
];
