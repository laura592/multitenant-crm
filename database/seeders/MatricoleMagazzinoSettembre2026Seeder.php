<?php

namespace Database\Seeders;

use App\Models\MachineUnit;
use App\Models\Material;
use App\Models\Tenant;
use Illuminate\Database\Seeder;

/**
 * Le macchine ferme in magazzino il 04/09/2026, trascritte dalle foto delle
 * targhette e delle etichette d'imballo mandate dall'ufficio: 16 matricole
 * che in CRM non c'erano (nessuna delle 802 machine_units importate da
 * Eureka le contiene), perche' sono arrivate e non sono ancora state
 * installate da nessuna parte.
 *
 * Entrano tutte come status "in magazzino" e senza cliente: e' esattamente
 * quello che dicono le foto (scatole ancora chiuse su pallet, piu' qualche
 * macchina disimballata in showroom), e chi le installera' cambiera' stato
 * dalla scheda macchina.
 *
 * Occhio a una cosa, guardando le foto: per lo stesso modello ci sono sia
 * l'etichetta del cartone sia la targhetta della macchina, ma NON sono lo
 * stesso pezzo — su A300, A600, SU03 e SU06 i due numeri sono sempre
 * diversi. Sono unita' distinte, e vanno contate due volte.
 *
 * Il modello a catalogo (material_id) si collega solo dove il codice Eureka
 * e' inequivocabile; per AC200, SU06, DC-ONE e i due Ceado in anagrafica non
 * c'e' la macchina intera (solo ricambi), quindi resta vuoto e il modello
 * vive nel model_name. Da abbinare a mano quando l'articolo esistera'.
 *
 * Fuori da DatabaseSeeder di proposito: e' un carico una tantum di dati
 * operativi, si lancia con --class quando serve. Idempotente: firstOrCreate
 * per (tenant, matricola), quindi rilanciarlo non tocca le macchine che nel
 * frattempo sono state installate o modificate.
 */
class MatricoleMagazzinoSettembre2026Seeder extends Seeder
{
    /**
     * @var array<int, array{serial: string, model: string, material: ?string, notes: string}>
     */
    private const MACCHINE = [
        // --- Franke, macchine da caffe' ---
        [
            'serial' => '3400000412794',
            'model' => "MACCHINA PER CAFFE' FRANKE A300",
            'material' => 'A300',
            'notes' => 'FCS4070 A300 - opzione FM EC 2G 1P H1 W4. Prod order 8777029, work order 3420116438/11, part 503.0587.597.',
        ],
        [
            'serial' => '3400000403418',
            'model' => "MACCHINA PER CAFFE' FRANKE A300",
            'material' => 'A300',
            'notes' => 'FCS4070 A300 - opzione FM EC 2G 1P H1 W4. Install-No 20131505.',
        ],
        [
            'serial' => '3400000338381',
            'model' => "MACCHINA PER CAFFE' FRANKE A300",
            'material' => 'A300',
            'notes' => 'FCS4070 A300 - opzione NM 1G 2P H1 W4. Prod order 8539629, work order 3420092470/21, part 503.0587.594. Sul cartone: "sistema pagamento".',
        ],
        [
            'serial' => '3400000414098',
            'model' => "MACCHINA PER CAFFE' FRANKE A600FM",
            'material' => 'A600FM',
            'notes' => 'FCS4086 A600 - opzione FM Plus 2G 2P H1 C2 W1 LH, colore Onyx. Prod order 8776675, work order 3420116438/21, part 506.0719.439.',
        ],
        [
            'serial' => '3400000411146',
            'model' => "MACCHINA PER CAFFE' FRANKE A600FM",
            'material' => 'A600FM',
            'notes' => 'FCS4086 A600 - opzione FM Pro 2G 2P H1 C2 W1 LH. Install-No 20276923.',
        ],

        // --- Franke, unita' di raffreddamento e accessori ---
        [
            'serial' => '262905775',
            'model' => 'FRIGO LATTE SU03 EC',
            'material' => 'SU03EC',
            'notes' => 'FCS4081 SU03 opzione EC. Prod order 8777663, work order 3420116438/12, part 550.0642.114 (etichetta imballo 560.0698.802).',
        ],
        [
            'serial' => '260705978',
            'model' => 'FRIGO LATTE SU03',
            'material' => 'SU03EC',
            'notes' => 'FCS4081 SU03, targhetta sulla macchina (classe climatica 5(T), R600a 0,013 kg).',
        ],
        [
            'serial' => '3400000414734',
            'model' => 'FRIGO LATTE FRANKE SU06 EC PLUS LH',
            'material' => null,
            'notes' => 'FCS4088 SU06 opzione EC Plus LH. Prod order 8778906, work order 3420116438/22, part 550.0753.430. Modello non ancora a catalogo Eureka.',
        ],
        [
            'serial' => '3400000411644',
            'model' => 'FRIGO LATTE FRANKE SU06 EC PRO DMI LH',
            'material' => null,
            'notes' => 'FCS4088 SU06 opzione EC Pro DMI LH. Install-No 20273024. Modello non ancora a catalogo Eureka.',
        ],
        [
            'serial' => '3400000409347',
            'model' => 'CUP  WARMER CW300',
            'material' => 'CW300',
            'notes' => 'FCS4094 CW300 scaldatazze. Prod order 8765609, work order 3420115284/13, part 552.0719.474. Riferimento ordine: showroom.',
        ],
        [
            'serial' => '3400000413068',
            'model' => 'SISTEMA DI CONTEGGIO FRANKE AC200',
            'material' => null,
            'notes' => 'FCS4091 AC200. Prod order 8768397, work order 3420115505/91, part 554.0719.476. Riferimento ordine: Test-TMR000152 - ILLY CAFFE\'. Modello non ancora a catalogo Eureka.',
        ],

        // --- Dalla Corte ---
        [
            'serial' => 'C23004414',
            'model' => 'MACCHINA DALLA CORTE MOD. XT BARISTA A/3',
            'material' => 'XTBARISTA',
            'notes' => 'DC-PROXT 3 gruppi normal barista STD, anno 2023, 380-400V trifase, white oak, filtro D54. Codice Dalla Corte MC-DCPROXT-3-N-B-STD, work order 23009126.',
        ],
        [
            'serial' => 'V25000944',
            'model' => "MACCHINA DA CAFFE' DALLA CORTE EVO A/3",
            'material' => 'EVO A/3',
            'notes' => 'EVO2 3 gruppi Nebula Black, 400V. Codice Dalla Corte 1-MC-EVODUE-3-N-400, work order 25002492.',
        ],
        [
            'serial' => 'G24004156',
            'model' => 'MACCHINA CAFFE\' DALLA CORTE DC-ONE',
            'material' => null,
            'notes' => 'DC-ONE, anno 2024, 200-230V 500W. Modello non ancora a catalogo Eureka.',
        ],

        // --- Ceado, macinadosatori ---
        [
            'serial' => 'BHE214008',
            'model' => 'MACINADOSATORE CEADO E37R NERO',
            'material' => null,
            'notes' => 'Ceado E37R nero, codice articolo 50035002. A catalogo Eureka c\'e\' solo il generico "MACINADOSATORE CEADO".',
        ],
        [
            'serial' => 'IHE143061',
            'model' => 'MACINADOSATORE CEADO E37S NERO',
            'material' => null,
            'notes' => 'Ceado E37S nero, codice articolo 50025302. A catalogo Eureka c\'e\' solo il generico "MACINADOSATORE CEADO".',
        ],
    ];

    public function run(): void
    {
        $tenant = Tenant::query()
            ->where('slug', config('tenant-defaults.slug'))
            ->firstOrFail();

        $codici = collect(self::MACCHINE)->pluck('material')->filter()->unique();

        $materiali = Material::withoutGlobalScopes()
            ->whereIn('code', $codici)
            ->pluck('id', 'code');

        $mancanti = $codici->diff($materiali->keys());

        if ($mancanti->isNotEmpty()) {
            // Non e' fatale (la matricola entra lo stesso, senza modello), ma
            // va detto: se un codice Eureka sparisce dall'anagrafica la
            // macchina resta orfana e il rapportino non trova il modello.
            $this->command?->warn('Codici articolo non trovati a catalogo: '.$mancanti->implode(', '));
        }

        $creati = 0;

        foreach (self::MACCHINE as $riga) {
            $unit = MachineUnit::withoutGlobalScopes()->firstOrCreate(
                [
                    'tenant_id' => $tenant->id,
                    'serial_number' => $riga['serial'],
                ],
                [
                    'source' => MachineUnit::SOURCE_MANUALE,
                    'status' => MachineUnit::STATUS_IN_MAGAZZINO,
                    'material_id' => $materiali[$riga['material']] ?? null,
                    'model_name' => $riga['model'],
                    'notes' => $riga['notes'],
                ],
            );

            $creati += (int) $unit->wasRecentlyCreated;
        }

        $this->command?->info("Matricole magazzino: {$creati} create, ".(count(self::MACCHINE) - $creati).' gia\' presenti.');
    }
}
