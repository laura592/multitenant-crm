<?php

return [

    /*
    | Ore di straordinario gia' comprese nell'indennita' di trasferta.
    |
    | Regola dell'ufficio (21/09/2026): nel giorno di trasferta la prima ora
    | oltre il contratto non e' straordinario, la paga gia' la trasferta — che
    | il dipendente la faccia o no. Con 8 ore di contratto: 9 ore lavorate
    | danno zero straordinario, 10 ore ne danno una. Vale per tutti.
    */
    'trasferta_ore_incluse' => (float) env('PRESENZE_TRASFERTA_ORE_INCLUSE', 1),

];
