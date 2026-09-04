<?php

namespace Tests\Feature;

use App\Console\Commands\ProponiCodiciManutenzione as Regole;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Le regole che leggono il codice manutenzione dal nome del modello, provate
 * sui nomi veri del catalogo Eureka.
 *
 * L'ordine conta: le marche con codice proprio vanno riconosciute prima
 * della regola generica sui gruppi, altrimenti "DALLA CORTE DC PRO 3 GRUPPI"
 * diventa una F3.
 */
class CodiciManutenzionePropostiTest extends TestCase
{
    /** @return array<string, array{0: ?string, 1: ?string}> */
    public static function modelli(): array
    {
        return [
            // Faema, nelle tre forme in cui il catalogo scrive i gruppi.
            'Faema A/2' => ['FAEMA TEOREMA A/2', 'F2'],
            'Faema A 3 GRUPPI' => ['FAEMA EMBLEMA A 3 GRUPPI', 'F3'],
            'Faema 2 GRUPPI' => ['FAEMA E98 UP 2 GRUPPI', 'F2'],
            'Faema A/4' => ['FAEMA PRESIDENT A/4', 'F4'],
            'Royal come Faema' => ['ROYAL LIRA 2 GRUPPI', 'F2'],

            // Marche col codice proprio: devono vincere sui gruppi.
            'Dalla Corte A/2' => ["MACCHINA CAFFE' DALLA CORTE EVO A/2", 'DC2'],
            'Dalla Corte 3 GRUPPI' => ["MACCHINA DA CAFFE' DALLA CORTE DC PRO 3 GRUPPI", 'DC3'],
            'Cimbali 2' => ['CIMBALI M 39 2 GRUPPI', 'C2'],
            'La Cimbali 3' => ['LA CIMBALI XP1 3 GRUPPI', 'C3'],

            // Franke: la sigla e' il codice, i suffissi non contano.
            'Franke A300' => ["MACCHINA PER CAFFE' FRANKE A300", 'MANA300'],
            'Franke A600FM' => ["MACCHINA PER CAFFE' FRANKE A600FM", 'MANA600'],
            'Franke A400 MS' => ["MACCHINA PR CAFFE' FRANKE A400 MS", 'MANA400'],

            // Superautomatiche: non si contano a gruppi.
            'Faema X15' => ['SUPERAUTOMATICA FAEMA X15 CS21', 'MAX15'],
            'Faema X20' => ['FAEMA X20 CP11H', 'MANX20'],
            'Cimbali S15' => ['CIMBALI S15 SUPER AUTOMATICA', 'MANS15'],

            // Niente da proporre: non sono macchine da caffe'.
            'macinadosatore' => ['MACINADOSATORE CEADO', null],
            'addolcitore' => ['ADDOLCITORE AUTOMATICO LT 8', null],
            'impianto acqua' => ['IMPIANTO ACQUA', null],
            'impianto spina 1 via' => ['IMPIANTO ALLA SPINA 1 VIA', null],

            // Hanno i gruppi ma la marca non la mappiamo: meglio vuoto che
            // un codice sbagliato, che finirebbe in fattura.
            'Casadio' => ['CASADIO UNDICI A/2', null],
            'E61 senza marca' => ['E 61 LEGEND 2 GRUPPI', null],
            'Astoria' => ['ASTORIA 2 GRUPPI', null],

            'vuoto' => ['', null],
            'null' => [null, null],
        ];
    }

    #[DataProvider('modelli')]
    public function test_il_codice_si_legge_dal_nome_del_modello(?string $nome, ?string $atteso): void
    {
        $this->assertSame($atteso, Regole::codicePer($nome), (string) $nome);
    }
}
