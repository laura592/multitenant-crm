<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Un lead dal sito non si perde piu' per colpa dell'indirizzo da cui arriva
 * (17/11/2026).
 *
 * LeadIntakeController accetta un `origin_url` fino a 2048 caratteri e lo
 * scrive in due colonne da 255: `customers.consent_source` (preceduto da
 * "form sito — ") e `information_requests.origin_url`. Finche' la gente
 * arrivava dalle pagine normali nessuno se n'e' accorto -- il valore piu'
 * lungo mai salvato era di 217 caratteri.
 *
 * Poi e' arrivata CARROZZERIA AUTOSERVICE RIBANI SRL da un annuncio Google:
 * l'URL con utm_*, gclid e gbraid passa i 400 caratteri, l'insert e' morto
 * con "Data too long for column 'consent_source'", e il sito ha riprovato
 * trenta volte fra le 11:13 e le 11:55 del 12/11 fallendo sempre. Il
 * contatto esisteva solo nella mail di notifica: nel CRM non c'era.
 *
 * Le colonne diventano TEXT invece di accorciare il valore: `consent_source`
 * e' la prova di DOVE il consenso e' stato raccolto, e un consenso provato a
 * meta' non e' una prova. ALTER a mano e non ->change(): su questa versione
 * servirebbe doctrine/dbal, che qui non c'e'.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE customers MODIFY consent_source TEXT NULL');
        DB::statement('ALTER TABLE information_requests MODIFY origin_url TEXT NULL');
    }

    public function down(): void
    {
        // Tornando a 255 i valori lunghi verrebbero troncati dal database:
        // meglio accorciarli qui, consapevolmente, che scoprirli mutilati.
        DB::statement('UPDATE customers SET consent_source = LEFT(consent_source, 255) WHERE CHAR_LENGTH(consent_source) > 255');
        DB::statement('UPDATE information_requests SET origin_url = LEFT(origin_url, 255) WHERE CHAR_LENGTH(origin_url) > 255');

        DB::statement('ALTER TABLE customers MODIFY consent_source VARCHAR(255) NULL');
        DB::statement('ALTER TABLE information_requests MODIFY origin_url VARCHAR(255) NULL');
    }
};
