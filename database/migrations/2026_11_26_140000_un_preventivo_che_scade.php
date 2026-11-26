<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * La data entro cui il preventivo vale ancora.
 *
 * Fino a oggi un preventivo inviato restava "inviato" per sempre: al
 * 26/11/2026 ce n'erano 84 fermi, di cui 24 trattative da oltre novanta
 * giorni per 310.000 EUR, che nei conti sembravano ancora pipeline. Senza
 * una scadenza il silenzio non diventa mai un no, e non c'e' nemmeno un
 * motivo per cui il cliente debba rispondere.
 *
 * I 45 giorni non sono una cifra tonda scelta a caso: sui preventivi che
 * una risposta l'hanno avuta, i si' arrivano con una mediana di 5 giorni,
 * il 67% entro una settimana e il 93% entro 45 giorni. Nello storico non
 * esiste un solo si' arrivato dopo 69 giorni.
 *
 * Ai preventivi gia' inviati la data viene calcolata all'indietro dalla
 * loro data: cosi' l'arretrato e' coerente invece di restare senza
 * scadenza. Lo STATO pero' non viene toccato qui: a spostarlo e'
 * `preventivi:scadi`, che si puo' prima lanciare con --dry-run e
 * guardare. Una migrazione che archivia quaranta trattative mentre passa
 * non e' una cosa da fare di nascosto.
 */
return new class extends Migration
{
    private const GIORNI = 45;

    public function up(): void
    {
        Schema::table('quotes', function (Blueprint $table) {
            $table->date('valid_until')->nullable()->after('date');
        });

        // Solo i preventivi ancora in attesa: su accettati, rifiutati e
        // bozze la validita' non vuol dire niente.
        DB::table('quotes')
            ->where('status', 'inviato')
            ->whereNull('valid_until')
            ->update([
                'valid_until' => DB::raw('DATE_ADD(`date`, INTERVAL '.self::GIORNI.' DAY)'),
            ]);
    }

    public function down(): void
    {
        Schema::table('quotes', function (Blueprint $table) {
            $table->dropColumn('valid_until');
        });
    }
};
