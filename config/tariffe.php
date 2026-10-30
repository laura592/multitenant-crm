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

    /**
     * I torrefattori: le macchine sono loro, non del bar che le usa.
     *
     * Serve a una cosa sola, la manodopera della disinstallazione: smontare
     * una macchina del torrefattore vuol dire staccarla, imballarla e
     * caricarla per il rientro, ed e' un'ora; una macchina del cliente, che
     * resta dov'e', sono trenta minuti (regola dell'ufficio, 27/10/2026).
     *
     * La chiave e' il codice gestionale, come in 'paganti' qui sotto.
     * Aggiungerne uno e' una riga. Fuori dall'elenco per ora: Spigola (1629)
     * e Danieli (561), che hanno un listino proprio ma non risulta siano
     * torrefazioni — se lo sono, basta metterli qui.
     */
    'torrefattori' => [
        1178, // Martellozzo Lorenzo & C. SAS
        782,  // Goppion Caffe' SPA
        942,  // Hts 1892 SPA
        3129, // Mioni Pezzato & SPA
        580,  // Dersut Caffe' SPA
    ],

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
            'manutenzione_suffisso' => 'GOPPION',
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
            'manutenzione_suffisso' => 'HTS',
            'chiamata' => 'CHIVEHTS',
            'manodopera' => 'OREHTS',
        ],

        // Danieli Management SRL
        561 => [
            'nome' => 'Danieli',
            'manutenzione_suffisso' => 'DAN',
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
        // La manutenzione ordinaria non ha un codice standard: dipende dal
        // MODELLO della macchina (Material::maintenance_code). Qui c'e' solo
        // il suffisso dei paganti con listino proprio, che si appiccica al
        // codice del modello: F3 + GOPPION = F3GOPPION, gia' a catalogo.
        // Se la variante non esiste (F4GOPPION non c'e') si ricade sul
        // codice base, invece di mettere in riga un articolo inventato.
        'lavaggio' => 'LAV2',
        'lavaggio_ulteriore_via' => 'ULTVIA',
        // Gli impianti acqua non si contano a vie: la voce da fatturare e'
        // una sanificazione dell'impianto, a corpo (SANIFICAZIONE = 110,00,
        // "SANIFICAZIONE IMPIANTO ACQUA"). Nessun pagante ha finora un
        // codice proprio, ma la voce sta qui come le altre cosi' che
        // aggiungerlo a un listino sia una riga sola.
        'sanificazione' => 'SANIFICAZIONE',
        // Disinstallazione: due codici allo stesso prezzo (79,50) che
        // dicono due cose diverse, e sbagliarli si vede solo in fattura.
        // DISIN/RITIRO = smontata e portata via; DISIN = staccata e lasciata
        // dal cliente. A catalogo ce ne sono altri che NON vanno usati
        // (109000137/109000138 "DIRITTO DISINSTALLAZIONE" e DISINACQUA a
        // 0,00, DISINMAC a 66,00 ma senza codice Eureka, quindi non
        // inviabile): finivano in riga al posto di questi due.
        'disinstallazione' => 'DISIN',
        'disinstallazione_ritiro' => 'DISIN/RITIRO',
    ],
];
