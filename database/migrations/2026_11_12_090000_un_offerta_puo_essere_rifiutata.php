<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Un'offerta può finire con un no.
 *
 * `quote_groups.status` è un enum di quattro valori e "rifiutato" non c'era:
 * quando il cliente diceva no a tutte le soluzioni, l'offerta restava
 * "Inviato" in mezzo a quelle che aspettano ancora una risposta, e per
 * accorgersene bisognava aprirla e guardare i preventivi uno per uno
 * (Laura, 12/11/2026: Mass Bianchet ha rifiutato tutti e tre i suoi).
 *
 * "Scaduto" non era la stessa cosa -- dice che è passato il tempo, non che
 * hanno detto no -- e infatti in produzione non l'ha mai usato nessuno.
 *
 * Un ALTER a mano e non `->change()`: su una colonna enum quello richiede
 * doctrine/dbal, che qui non c'è, e riscriverebbe comunque la definizione
 * per intero.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement(
            "ALTER TABLE quote_groups MODIFY status "
            ."ENUM('bozza','inviato','scelto','rifiutato','scaduto') NOT NULL DEFAULT 'bozza'"
        );
    }

    public function down(): void
    {
        // Le offerte rifiutate tornano "inviato": l'enum non le ammetterebbe
        // piu', e perderle in un valore vuoto sarebbe peggio che riaprirle.
        DB::table('quote_groups')->where('status', 'rifiutato')->update(['status' => 'inviato']);

        DB::statement(
            "ALTER TABLE quote_groups MODIFY status "
            ."ENUM('bozza','inviato','scelto','scaduto') NOT NULL DEFAULT 'bozza'"
        );
    }
};
