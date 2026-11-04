<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Le righe dei rapportini dicono se il prezzo l'ha messo Eureka o noi
 * (04/11/2026).
 *
 * Dal 02/09/2026 l'import, quando Eureka manda `prezzo 0` su una riga di
 * intervento, ci mette il prezzo di listino. Serviva: prenderlo alla
 * lettera lasciava senza valore proprio le voci fatturabili.
 *
 * L'effetto collaterale però è che un intervento REGALATO sembra fatturato.
 * Il 04/11/2026, quadrando la FT 516 di Dersut contro i suoi documenti FB,
 * sono saltate fuori tre disinstallazioni fatturate a zero — 201,00 € — che
 * nel CRM risultavano da 79,50 e 42,00 €. Dal pannello non c'era modo di
 * accorgersene, perché un prezzo messo da noi e uno fatturato da Eureka
 * erano indistinguibili.
 *
 * Questa colonna li distingue. Non cambia nessun importo: cambia solo il
 * fatto che adesso si sa da dove viene.
 *
 * Le righe già importate restano a `false`, che è onesto quanto il resto:
 * per quelle vecchie nessuno sa dirlo senza rileggere i documenti FB uno per
 * uno. Le nuove nascono marcate, e lo storico si recupera a parte.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('service_report_materials', function (Blueprint $table) {
            $table->boolean('prezzo_stimato')->default(false)->after('line_total_snapshot');
        });
    }

    public function down(): void
    {
        Schema::table('service_report_materials', function (Blueprint $table) {
            $table->dropColumn('prezzo_stimato');
        });
    }
};
