<?php

/*
 * Override della traduzione italiana di filament-shield.
 *
 * Il pacchetto lascia in inglese le tre schede ("Resources", "Pages",
 * "Widgets") e traduce il resto con le maiuscole dell'inglese ("Seleziona
 * Tutto", "Nome Guard"). Qui restano solo le chiavi che cambiamo: Laravel
 * fonde questo file con quello del pacchetto, il resto arriva da li'.
 *
 * Le etichette dei singoli permessi (Vedere l'elenco, Cestinare, ...) non
 * stanno qui: il pacchetto le tiene commentate e le costruisce da
 * Str::headline, quindi le decide RoleResource::ETICHETTE.
 */

return [
    'resources' => 'Risorse',
    'pages' => 'Pagine',
    'widgets' => 'Riquadri',

    'section' => 'Cosa può fare',

    'field.guard_name' => 'Guard',
    'field.select_all.name' => 'Dai tutto',
    'field.select_all.message' => 'Spunta ogni permesso che compare in questa pagina.',

    'column.guard_name' => 'Guard',
];
