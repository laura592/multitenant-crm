<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Il noleggio pagato a battuta invece che a canone fisso (09/10/2026).
 *
 * Su un'attivita' stagionale il canone fisso e' il punto su cui si litiga:
 * nei mesi vuoti il cliente paga lo stesso. Pagando a erogazione il rischio
 * del volume passa ad Alex, che pero' in cambio puo' chiedere un minimo
 * mensile: senza, una stagione andata male non copre nemmeno la macchina.
 *
 * Nullo = canone fisso, come prima.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('noleggi', function (Blueprint $table) {
            $table->decimal('prezzo_battuta', 8, 4)->nullable()->after('canone');
            $table->decimal('minimo_mensile', 10, 2)->nullable()->after('prezzo_battuta');
        });
    }

    public function down(): void
    {
        Schema::table('noleggi', function (Blueprint $table) {
            $table->dropColumn(['prezzo_battuta', 'minimo_mensile']);
        });
    }
};
