<?php

return [

    /*
    | Ore di straordinario comprese nell'indennita' di trasferta.
    |
    | Dal 01/10/2026 e' zero: la trasferta non tocca lo straordinario. Chi va
    | in trasferta e sfora il contratto prende l'indennita' E lo straordinario,
    | dalla prima ora (Laura, 02/10/2026).
    |
    | Fino al 30/09/2026 valeva un'ora: la prima oltre il contratto la pagava
    | gia' la trasferta. I mesi chiusi vanno lasciati come sono stati pagati —
    | settembre 2026 era gia' in busta paga — e il conto non e' storicizzato,
    | si rifa' ogni volta dai cartellini. Senza la data di stacco, ristampare
    | settembre lo avrebbe ricalcolato con la regola nuova: 10 ore invece di 5.
    |
    | Per cambiare di nuovo la regola si sposta la data e si rinominano i due
    | valori, senza toccare il calcolo.
    */
    'trasferta_ore_incluse' => (float) env('PRESENZE_TRASFERTA_ORE_INCLUSE', 0),

    'trasferta_ore_incluse_prima' => (float) env('PRESENZE_TRASFERTA_ORE_INCLUSE_PRIMA', 1),

    'trasferta_regola_nuova_dal' => env('PRESENZE_TRASFERTA_REGOLA_NUOVA_DAL', '2026-10-01'),

];
