<?php

namespace Tests\Unit;

use App\Models\MachineUnit;
use App\Models\ServiceReport;
use App\Models\ServiceReportMaterial;
use App\Support\Gestionale\ConfrontoRapportini;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Estende Tests\TestCase e non PHPUnit\Framework\TestCase: i modelli
 * Eloquent hanno bisogno del resolver di connessione, che esiste solo
 * quando l'applicazione e' avviata. Nessuna query viene comunque eseguita —
 * i rapportini sono costruiti in memoria.
 *
 * Casi che vengono dai dati reali del 02/09/2026, quando dopo l'import 52
 * rapportini su 57 avevano una scheda Eureka lo stesso giorno.
 */
class ConfrontoRapportiniTest extends TestCase
{
    private function rapportino(?string $cliente, ?string $giorno, ?string $matricola, array $materiali = []): ServiceReport
    {
        $r = new ServiceReport;
        $r->customer_id = $cliente;
        $r->intervention_date = $giorno ? Carbon::parse($giorno) : null;

        if ($matricola !== null) {
            $m = new MachineUnit;
            $m->serial_number = $matricola;
            $r->setRelation('machineUnit', $m);
        } else {
            $r->setRelation('machineUnit', null);
        }

        $r->setRelation('materialsUsed', collect(array_map(function (string $id) {
            $m = new ServiceReportMaterial;
            $m->material_id = $id;

            return $m;
        }, $materiali)));

        return $r;
    }

    public function test_stessa_macchina_e_stessi_ricambi_e_lo_stesso_intervento(): void
    {
        $nostro = $this->rapportino('cli-1', '2026-08-06', '1858049', ['guarnizione', 'filtro']);
        $loro = $this->rapportino('cli-1', '2026-08-06', '1858049', ['filtro']);

        $this->assertSame(ConfrontoRapportini::CERTO, ConfrontoRapportini::confidenza($nostro, $loro));
    }

    public function test_stessa_macchina_senza_ricambi_in_comune_resta_probabile(): void
    {
        $nostro = $this->rapportino('cli-1', '2026-08-06', '1858049', ['guarnizione']);
        $loro = $this->rapportino('cli-1', '2026-08-06', '1858049', ['pompa']);

        $this->assertSame(ConfrontoRapportini::PROBABILE, ConfrontoRapportini::confidenza($nostro, $loro));
    }

    /**
     * Il caso Hotel Neps: due macchine dello stesso cliente trattate nella
     * stessa visita. Sono due interventi distinti, non un doppione — ed e'
     * l'errore che l'abbinamento per sola data produceva.
     */
    public function test_macchine_diverse_lo_stesso_giorno_non_sono_un_doppione(): void
    {
        $nostro = $this->rapportino('cli-1', '2026-08-06', '1858049');
        $loro = $this->rapportino('cli-1', '2026-08-06', '1813615');

        $this->assertNull(ConfrontoRapportini::confidenza($nostro, $loro));
    }

    public function test_senza_matricola_resta_da_verificare(): void
    {
        $nostro = $this->rapportino('cli-1', '2026-08-06', null);
        $loro = $this->rapportino('cli-1', '2026-08-06', '1858049');

        $this->assertSame(ConfrontoRapportini::DA_VERIFICARE, ConfrontoRapportini::confidenza($nostro, $loro));
    }

    public function test_clienti_o_giorni_diversi_non_si_confrontano(): void
    {
        $nostro = $this->rapportino('cli-1', '2026-08-06', '1858049');

        $this->assertNull(ConfrontoRapportini::confidenza($nostro, $this->rapportino('cli-2', '2026-08-06', '1858049')));
        $this->assertNull(ConfrontoRapportini::confidenza($nostro, $this->rapportino('cli-1', '2026-08-07', '1858049')));
    }
}
