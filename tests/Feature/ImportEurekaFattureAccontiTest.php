<?php

namespace Tests\Feature;

use App\Models\EurekaFattura;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Le fatture di acconto e le righe che le detraggono si riconoscono solo dal
 * TESTO della riga documento, scritto a mano da persone diverse in anni
 * diversi. Nei dati reali convivono almeno sei forme:
 *
 *   A DETRARRE FATTURA DI ACCONTO NR. 178/25
 *   A DETRARRE FATTURA DI ACCONTO NR . 178/25     (spazio prima del punto)
 *   A DETRARRE FATTURA DI ACCTO NR 33/24          (abbreviato)
 *   A DETRARRE FT ACCONTO NR. 16/25
 *   A DETRARRE FATTURA DI ACCONTO N. 44
 *
 * Due volte un pattern troppo rigido ha marcato come mai saldati acconti che
 * invece lo erano: la lista passo' da 23 casi a 10 e l'importo da 143.000 a
 * 59.000 euro. Questo test fissa le varianti viste sul campo.
 */
class ImportEurekaFattureAccontiTest extends TestCase
{
    use RefreshDatabase;

    private int $idDocumento = 0;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
    }

    public function test_riconosce_le_detrazioni_scritte_in_tutte_le_forme(): void
    {
        $this->fingiEureka(
            fatture: [
                $this->fattura(1, '178', 3068, '2025-05-16'),
                $this->fattura(2, '179', 3068, '2025-05-16'),
                $this->fattura(3, '180', 3068, '2025-05-16'),
                $this->fattura(4, '181', 3068, '2025-05-16'),
                $this->fattura(5, '182', 3068, '2025-05-16'),
                $this->fattura(6, '999', 3068, '2025-05-16'),
            ],
            righe: [
                $this->rigaDocumento('178', '2025-05-16', 3068, 'FATTURA DI ACCONTO PARI AL 50%', 'FAD'),
                $this->rigaDocumento('179', '2025-05-16', 3068, 'FATTURA DI ACCONTO PARI AL 50%', 'FAD'),
                $this->rigaDocumento('180', '2025-05-16', 3068, 'FATTURA DI ACCONTO PARI AL 50%', 'FA'),
                $this->rigaDocumento('181', '2025-05-16', 3068, 'FATTURA DI ACCONTO PARI AL 50%', 'FAD'),
                $this->rigaDocumento('182', '2025-05-16', 3068, 'FATTURA DI ACCONTO PARI AL 50%', 'FAD'),
                $this->rigaDocumento('999', '2025-05-16', 3068, 'FATTURA DI ACCONTO PARI AL 50%', 'FAD'),

                $this->rigaDocumento('500', '2025-06-16', 3068, 'A DETRARRE FATTURA DI ACCONTO NR. 178/25 DEL 16/05/25'),
                $this->rigaDocumento('501', '2025-06-16', 3068, 'A DETRARRE FATTURA DI ACCONTO NR . 179/25 DEL 16/05/25'),
                $this->rigaDocumento('502', '2025-06-16', 3068, 'A DETRARRE FATTURA DI ACCTO NR 180/25 DEL 16/05/25'),
                $this->rigaDocumento('503', '2025-06-16', 3068, 'A DETRARRE FT ACCONTO NR. 181/25 DEL 16/05/25'),
                // Il verbo scritto male: e' cosi' negli archivi veri.
                $this->rigaDocumento('504', '2025-06-16', 3068, 'A DETARRRE FATTURA DI ACCTO NR. 182/25 DEL 16/05/25'),
            ],
        );

        $this->artisan('eureka:import-fatture', ['--tenant' => 'alex'])->assertExitCode(0);

        $aperti = EurekaFattura::where('e_acconto', true)->pluck('numero_doc')->all();

        $this->assertSame(['999'], $aperti, 'solo l\'acconto senza detrazione deve restare aperto');
    }

    /**
     * Il verbo "detrarre" negli archivi e' scritto in due modi, "A DETRARRE"
     * e "A DETARRRE", e cercare la forma corretta perdeva le righe col
     * refuso: l'acconto 29/2024 di HOTEL MARCO POLO da 5.856 euro risultava
     * mai saldato mentre la fattura 67 del mese dopo lo detrae per intero.
     *
     * Da qui la scelta di cercare il NOME del documento (ACCONTO, ACCTO),
     * che nelle 190 righe reali non manca mai, e di riconoscere il verbo
     * dopo, sul testo, dove un refuso si assorbe.
     */
    public function test_il_verbo_detrarre_scritto_male_conta_lo_stesso(): void
    {
        $this->fingiEureka(
            fatture: [
                $this->fattura(1, '29', 3033, '2024-02-21'),
                $this->fattura(2, '67', 3033, '2024-03-26'),
            ],
            righe: [
                $this->rigaDocumento('29', '2024-02-21', 3033, "ACCONTO PARI AL 40 % PER MACCHINA PER CAFFE'", 'FAD'),
                $this->rigaDocumento('67', '2024-03-26', 3033, 'A DETARRRE FATTURA DI ACCTO NR. 29/24 DEL 21/02/24'),
            ],
        );

        $this->artisan('eureka:import-fatture', ['--tenant' => 'alex'])->assertExitCode(0);

        $this->assertSame([], EurekaFattura::where('e_acconto', true)->pluck('numero_doc')->all());
        $this->assertSame('29', EurekaFattura::where('numero_doc', '67')->value('detrae_acconto_numero'));
    }

    /**
     * "A DETRARRE FATTURA DI ACCONTO" senza il numero: la detrazione c'e'
     * stata, ma non si sa quale acconto chiuda.
     *
     * Dichiararlo saldato sarebbe una bugia quanto dichiararlo aperto —
     * resta in elenco, marcato come da verificare a mano. Succede davvero:
     * HOTEL CAMBRIDGE ha due righe cosi' e il suo acconto 483/2023 finiva
     * fra i "mai saldati" come se nessuno avesse mai detratto niente.
     */
    public function test_una_detrazione_senza_numero_resta_un_caso_da_verificare(): void
    {
        $this->fingiEureka(
            fatture: [
                $this->fattura(1, '483', 855, '2023-11-22'),
                $this->fattura(2, '129', 855, '2024-05-17'),
                // Acconto di un ALTRO cliente: la riga ambigua di Cambridge
                // non deve toccarlo.
                $this->fattura(3, '77', 900, '2023-11-22'),
            ],
            righe: [
                $this->rigaDocumento('483', '2023-11-22', 855, 'FATTURA DI ACCONTO PARI AL 50%', 'FAD'),
                $this->rigaDocumento('129', '2024-05-17', 855, 'A DETRARRE FATTURA DI ACCONTO'),
                $this->rigaDocumento('77', '2023-11-22', 900, 'FATTURA DI ACCONTO PARI AL 50%', 'FAD'),
            ],
        );

        $this->artisan('eureka:import-fatture', ['--tenant' => 'alex'])->assertExitCode(0);

        $cambridge = EurekaFattura::where('numero_doc', '483')->first();
        $this->assertTrue($cambridge->e_acconto, 'senza il numero non si puo\' dire che sia saldato');
        $this->assertTrue($cambridge->detrazione_ambigua, 'ma qualcosa lo ha detratto: va guardato a mano');

        $altro = EurekaFattura::where('numero_doc', '77')->first();
        $this->assertTrue($altro->e_acconto);
        $this->assertFalse($altro->detrazione_ambigua, 'la riga ambigua vale solo per il suo cliente');
    }

    /**
     * "A DETRARRE FATTURA DI ACCONTO NR 01/26" e la fattura numero "1" sono
     * lo stesso documento: chi scrive la riga mette lo zero davanti, la
     * lista contabile no. Confrontando le stringhe cosi' come sono, due dei
     * dieci acconti in elenco sui dati reali risultavano mai saldati mentre
     * la loro fattura di saldo esisteva (HOTEL EUROPA 1/2026 e HOTEL AL SOLE
     * 3/2026, verificati il 2026-09-02).
     */
    public function test_lo_zero_davanti_al_numero_non_fa_sembrare_aperto_un_acconto_saldato(): void
    {
        $this->fingiEureka(
            fatture: [
                $this->fattura(1, '1', 3032, '2026-02-10'),
                $this->fattura(2, '52', 3032, '2026-04-18'),
            ],
            righe: [
                $this->rigaDocumento('1', '2026-02-10', 3032, 'FATTURA DI ACCONTO PARI AL 50%', 'FAD'),
                $this->rigaDocumento('52', '2026-04-18', 3032, 'A DETRARRE FATTURA DI ACCONTO NR 01/26'),
            ],
        );

        $this->artisan('eureka:import-fatture', ['--tenant' => 'alex'])->assertExitCode(0);

        $this->assertSame([], EurekaFattura::where('e_acconto', true)->pluck('numero_doc')->all());
    }

    /**
     * La stessa dicitura "a detrarre" viene ricopiata sulla BOLLA che
     * precede la fattura, e le bolle hanno una numerazione propria: la bolla
     * 249 non e' la fattura 249.
     *
     * Contarla come detrazione faceva due danni. Marcava la fattura 249, che
     * con quell'acconto non c'entra nulla (visto sui dati reali su due
     * documenti). E soprattutto, quando la fattura di saldo non e' MAI stata
     * emessa — cioe' il caso che questa analisi esiste per trovare — la
     * bolla da sola bastava a dichiarare l'acconto saldato.
     */
    public function test_una_bolla_non_vale_come_fattura_di_saldo(): void
    {
        $this->fingiEureka(
            fatture: [
                $this->fattura(1, '425', 3135, '2025-03-04'),
                $this->fattura(2, '249', 3135, '2025-09-30'),
            ],
            righe: [
                $this->rigaDocumento('425', '2025-03-04', 3135, 'FATTURA DI ACCONTO PARI AL 50%', 'FAD'),
                $this->rigaDocumento('249', '2025-11-20', 3135, 'A DETRARRE FATTURA DI ACCONTO NR 425/25', 'BC'),
            ],
        );

        $this->artisan('eureka:import-fatture', ['--tenant' => 'alex'])->assertExitCode(0);

        $this->assertSame(
            ['425'],
            EurekaFattura::where('e_acconto', true)->pluck('numero_doc')->all(),
            'la bolla non emette nulla: l\'acconto resta da saldare'
        );
        $this->assertNull(
            EurekaFattura::where('numero_doc', '249')->value('detrae_acconto_numero'),
            'la fattura 249 non deve ereditare la detrazione della bolla 249'
        );
    }

    /**
     * La numerazione riparte da 1 ogni anno: l'acconto 41 del 2026 e
     * l'acconto 41 del 2023 dello stesso cliente sono due documenti diversi.
     * Senza l'anno nella chiave, la detrazione del piu' recente chiudeva
     * anche il piu' vecchio — cioe' proprio quello che, essendo fermo da
     * anni, e' il caso piu' probabile di saldo mai fatturato.
     */
    public function test_la_detrazione_di_un_anno_non_chiude_l_acconto_omonimo_di_un_altro(): void
    {
        $this->fingiEureka(
            fatture: [
                $this->fattura(1, '41', 3318, '2023-05-09'),
                $this->fattura(2, '41', 3318, '2026-04-02'),
                $this->fattura(3, '183', 3318, '2026-05-20'),
            ],
            righe: [
                $this->rigaDocumento('41', '2023-05-09', 3318, 'FATTURA DI ACCONTO PARI AL 50%', 'FAD'),
                $this->rigaDocumento('41', '2026-04-02', 3318, 'FATTURA DI ACCONTO PARI AL 50%', 'FAD'),
                $this->rigaDocumento('183', '2026-05-20', 3318, 'A DETRARRE FATTURA DI ACCONTO NR. 41/26'),
            ],
        );

        $this->artisan('eureka:import-fatture', ['--tenant' => 'alex'])->assertExitCode(0);

        $aperti = EurekaFattura::where('e_acconto', true)->get();

        $this->assertCount(1, $aperti);
        $this->assertSame('2023', $aperti->first()->data_doc->format('Y'));
    }

    /**
     * Se una delle due liste non arriva, l'altra non deve autorizzare la
     * cancellazione di quella mancante: prima le due risposte finivano in un
     * array unico e una sola DELETE, quindi un 500 sulle fatture clienti
     * cancellava TUTTE le fatture clienti e riscriveva solo i fornitori,
     * senza un messaggio d'errore.
     */
    public function test_un_lato_in_errore_non_cancella_l_altro(): void
    {
        $tenant = $this->tenant();

        $preesistente = EurekaFattura::create([
            'tenant_id' => $tenant->id, 'tipo' => EurekaFattura::TIPO_CLIENTE,
            'id_eureka' => 4242, 'gestionale_code' => 3068, 'numero_doc' => '77',
            'data_doc' => '2025-01-15', 'totale_doc' => 1500,
        ]);

        Http::fake([
            '*fatture/clienti*' => Http::response('errore interno', 500),
            '*fatture/fornitori*' => Http::response([$this->fattura(9, '5', 900, '2026-01-20')], 200),
            '*cerca-in-righe*' => Http::response([], 200),
        ]);

        $this->artisan('eureka:import-fatture', ['--tenant' => 'alex'])->assertExitCode(1);

        $this->assertTrue(
            EurekaFattura::whereKey($preesistente->id)->exists(),
            'le fatture clienti gia\' in archivio non si toccano se Eureka non ne ha mandate di nuove'
        );
        $this->assertSame(1, EurekaFattura::where('tipo', EurekaFattura::TIPO_FORNITORE)->count());
    }

    private function tenant(): Tenant
    {
        return Tenant::create([
            'name' => 'Alex', 'slug' => 'alex', 'is_master' => true,
            'notify_customer_gestionale_emails' => ['ufficio@alexcaffe.it'],
        ]);
    }

    /** @return array<string, mixed> */
    private function fattura(int $id, string $numero, int $codice, string $data): array
    {
        return [
            'id' => $id, 'numero_doc' => $numero, 'data_doc' => $data.'T00:00:00.000+02:00',
            'totale_doc' => 1000, 'imponibile' => 819.67, 'codice' => $codice,
            'rag_sociale' => 'Cliente '.$codice, 'pagamento' => 'B001', 'causale' => '101',
            'partita_iva' => null, 'id_b10_origine' => 0,
        ];
    }

    /**
     * Ogni riga sta su un documento diverso, quindi ogni riga ha un id_doc
     * diverso: dandolo fisso, due documenti con la stessa dicitura standard
     * ("FATTURA DI ACCONTO PARI AL 50%") si fondevano in uno e il test
     * misurava un mondo che non esiste.
     *
     * @return array<string, mixed>
     */
    private function rigaDocumento(string $numero, string $data, int $codice, string $testo, string $tipo = 'FT'): array
    {
        return [
            'id_doc' => ++$this->idDocumento, 'tipo_doc' => $tipo, 'numero' => $numero,
            'data' => $data.'T00:00:00.000+02:00', 'id_f15' => $codice,
            'rag_sociale' => 'Cliente '.$codice, 'descrizione_riga' => $testo,
        ];
    }

    /**
     * Le righe si passano in UNA lista sola e la fake le filtra come fa
     * Eureka: match "contiene", case-insensitive. Tenerle divise per
     * ricerca falsava il test — nella realta' una riga che dice "ACCTO"
     * torna dalla ricerca ACCTO e non da quella ACCONTO, ed e' proprio da
     * quella differenza che nasceva il caso HOTEL MARCO POLO.
     *
     * La ricerca si fa una finestra d'anno alla volta, quindi la stessa fake
     * risponde piu' volte: i risultati sono chiavi, non conteggi, e
     * ripeterli non cambia l'esito.
     *
     * @param  array<int, array<string, mixed>>  $fatture
     * @param  array<int, array<string, mixed>>  $righe
     */
    private function fingiEureka(array $fatture, array $righe): void
    {
        $this->tenant();

        $contengono = fn (string $testo) => array_values(array_filter(
            $righe,
            fn (array $r) => str_contains(mb_strtoupper($r['descrizione_riga']), $testo),
        ));

        Http::fake([
            '*fatture/clienti*' => Http::response($fatture, 200),
            '*fatture/fornitori*' => Http::response([], 200),
            '*cerca-in-righe*testo=ACCONTO*' => Http::response($contengono('ACCONTO'), 200),
            '*cerca-in-righe*testo=ACCTO*' => Http::response($contengono('ACCTO'), 200),
        ]);
    }
}
