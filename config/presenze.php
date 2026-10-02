<?php

return [

    /*
    | Ore di straordinario gia' comprese nell'indennita' di trasferta.
    |
    | Oggi zero: la trasferta non tocca lo straordinario. Chi va in trasferta e
    | sfora il contratto prende l'indennita' E lo straordinario, dalla prima ora
    | (Laura, 02/10/2026). Fino al giorno prima la trasferta si mangiava la
    | prima ora.
    |
    | Il meccanismo resta: alzare questo numero rimette le ore "comprese", e
    | GiornataLavorativa le tiene fuori sia dalle ordinarie sia dallo
    | straordinario. Attenzione, il conto non e' storicizzato: cambiando il
    | valore cambiano anche i riepiloghi dei mesi passati, se ristampati.
    */
    'trasferta_ore_incluse' => (float) env('PRESENZE_TRASFERTA_ORE_INCLUSE', 0),

];
