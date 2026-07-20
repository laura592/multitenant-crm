<?php

namespace Database\Seeders;

use App\Models\Material;
use App\Models\Supplier;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Catalogo raccordi/valvole/tubi John Guest ("Soluzioni ad innesto rapido
 * per uso alimentare e trattamento acqua", 2020) trascritto integralmente
 * dal listino fornitore, per l'ordine materiali usato dai tecnici su
 * impianti idrici e linee birra alla spina. Righe condivise fra tutti i
 * tenant (tenant_id NULL), idempotente (updateOrCreate per codice).
 */
class MaterialSeeder extends Seeder
{
    private const CAT_GRIGIA = 'Raccordi in resina acetalica grigia';

    private const CAT_POLARCLEAN = 'Raccordi PolarClean (coassiali per spillatura birra)';

    private const CAT_BIANCA = 'Raccordi in resina acetalica bianca';

    private const CAT_PP_POLLICI = 'Raccordi in polipropilene bianco (pollici)';

    private const CAT_SUPERSEAL = 'Raccordi Superseal (per tubi in acciaio inox)';

    private const CAT_OTTONE = 'Raccordi in ottone (distribuzione bevande)';

    private const CAT_ADATTATORI = "Adattatori raccordi metrici - raccordi in pollici";

    private const CAT_ACETALICA_METRICI = 'Raccordi in resina acetalica (metrici)';

    private const CAT_NERA_METRICI = 'Raccordi in resina acetalica nera (metrici)';

    private const CAT_PP_METRICI = 'Raccordi in polipropilene bianco (metrici)';

    private const CAT_VALVOLE_INTERCETTAZIONE = 'Valvole di intercettazione';

    private const CAT_VALVOLE_PP = "Valvole di intercettazione e presa d'acqua in polipropilene";

    private const CAT_VALVOLE_NON_RITORNO = 'Valvole di non ritorno';

    private const CAT_TUBI_LLDPE = 'Tubi LLDPE';

    private const CAT_ACCESSORI = 'Accessori';

    public function run(): void
    {
        $rows = collect()
            ->merge($this->resinaGrigia())
            ->merge($this->polarClean())
            ->merge($this->resinaBianca())
            ->merge($this->polipropilenePollici())
            ->merge($this->superseal())
            ->merge($this->ottone())
            ->merge($this->adattatoriMetriciPollici())
            ->merge($this->resinaAcetalicaMetrici())
            ->merge($this->resinaNeraMetrici())
            ->merge($this->polipropileneMetrici())
            ->merge($this->valvoleIntercettazione())
            ->merge($this->valvolePolipropilene())
            ->merge($this->valvoleNonRitorno())
            ->merge($this->tubiLldpe())
            ->merge($this->accessori());

        $supplierId = Supplier::where('name', 'John Guest')->value('id');

        DB::transaction(function () use ($rows, $supplierId) {
            foreach ($rows as $row) {
                $code = $row['code'];
                unset($row['code']);
                Material::updateOrCreate(['code' => $code, 'tenant_id' => null], $row + ['supplier_id' => $supplierId]);
            }
        });

        $this->command?->info("Materiali John Guest popolati: {$rows->count()} codici.");
    }

    /**
     * Espande un gruppo di righe che condividono categoria/tipo/variante/tipo
     * filetto in record completi. $kind decide come interpretare i valori
     * posizionali di ogni riga (oltre al codice):
     * - tube_thread: [codice, tubo Ø, filetto]
     * - tube_tube:   [codice, tubo Ø 1, tubo Ø 2]
     * - tube_only:   [codice, tubo Ø]
     * - barb_only:   [codice, codolo Ø]
     * - barb_thread: [codice, codolo Ø, filetto]
     * - barb_tube:   [codice, codolo Ø, tubo Ø]
     * - tube_barb:   [codice, tubo Ø, codolo Ø]
     * - code_only:   [codice] (dimensione libera in nota)
     * Il quarto elemento opzionale di ogni riga sovrascrive la nota di gruppo.
     */
    private function expand(
        string $category,
        string $type,
        ?string $variant,
        ?string $threadType,
        string $kind,
        array $rows,
        ?string $notes = null
    ): array {
        return collect($rows)->map(function (array $row) use ($category, $type, $variant, $threadType, $kind, $notes) {
            [$code, $v1, $v2, $rowNotes] = array_pad($row, 4, null);

            $base = [
                'code' => $code,
                'category' => $category,
                'type' => $type,
                'variant' => $variant,
                'thread_type' => $threadType,
                'notes' => $rowNotes ?? $notes,
            ];

            return match ($kind) {
                'tube_thread' => $base + ['tube_diameter' => $v1, 'thread_size' => $v2],
                'tube_tube' => $base + ['tube_diameter' => $v1, 'tube_diameter_2' => $v2],
                'tube_only' => $base + ['tube_diameter' => $v1],
                'barb_only' => $base + ['barb_diameter' => $v1],
                'barb_thread' => $base + ['barb_diameter' => $v1, 'thread_size' => $v2],
                'barb_tube' => $base + ['barb_diameter' => $v1, 'tube_diameter' => $v2],
                'tube_barb' => $base + ['tube_diameter' => $v1, 'barb_diameter' => $v2],
                'code_only' => $base,
                default => throw new \InvalidArgumentException("Kind sconosciuto: {$kind}"),
            };
        })->all();
    }

    private function resinaGrigia(): array
    {
        $cat = self::CAT_GRIGIA;

        return collect()
            ->merge($this->expand($cat, 'Terminale diritto', 'Filettatura conica', 'BSPT', 'tube_thread', [
                ['PM010401S', '5/32', '1/8'], ['PM010402S', '5/32', '1/4'],
                ['PI010601S', '3/16', '1/8'], ['PI010602S', '3/16', '1/4'],
                ['PI010801S', '1/4', '1/8'], ['PI010802S', '1/4', '1/4'],
                ['PM010801S', '5/16', '1/8'], ['PM010802S', '5/16', '1/4'], ['PM010803S', '5/16', '3/8'],
                ['PI011202S', '3/8', '1/4'], ['PI011203S', '3/8', '3/8'],
                ['PI011603S', '1/2', '3/8'], ['PI011604S', '1/2', '1/2'],
            ]))
            ->merge($this->expand($cat, 'Terminale diritto', 'Filettatura cilindrica', 'BSP', 'tube_thread', [
                ['PI010812S', '1/4', '1/4'], ['PI011212S', '3/8', '1/4'], ['PI011213S', '3/8', '3/8'], ['PI011613S', '1/2', '3/8'],
            ], 'Per utilizzo su connessioni lamate.'))
            ->merge($this->expand($cat, 'Terminale diritto', 'Filettatura cilindrica', 'BSP', 'tube_thread', [
                ['NC128/I12', '3/8', '1/4'],
            ]))
            ->merge($this->expand($cat, 'Terminale diritto', 'Filettatura cilindrica', 'BSP', 'tube_thread', [
                ['NCPI011211S', '3/8', '1/8', '3/8" x 1/8" senza OR alla base della filettatura.'],
                ['NCPI011212S', '3/8', '1/4', '3/8" x 1/4" con OR più grande alla base della filettatura per connessioni con lamature profonde.'],
            ]))
            ->merge($this->expand($cat, 'Terminale diritto', 'Filettatura NPTF', 'NPTF', 'tube_thread', [
                ['PM010421S', '5/32', '1/8'], ['PM010422S', '5/32', '1/4'],
                ['PI010621S', '3/16', '1/8'],
                ['PI010821S', '1/4', '1/8'], ['PI010822S', '1/4', '1/4'], ['PI010823S', '1/4', '3/8'],
                ['PM010821S', '5/16', '1/8'], ['PM010822S', '5/16', '1/4'], ['PM010823S', '5/16', '3/8'],
                ['PI011221S', '3/8', '1/8'], ['PI011222S', '3/8', '1/4'], ['PI011223S', '3/8', '3/8'],
                ['PI011623S', '1/2', '3/8'], ['PI011624S', '1/2', '1/2'],
                ['PI012026S', '5/8', '3/4'],
            ]))
            ->merge($this->expand($cat, 'Terminale diritto', 'Filettatura Whitworth', 'BSW', 'tube_thread', [
                ['PI0106E5S', '3/16', '1/2-24'], ['PI0108E5S', '1/4', '1/2-24'],
                ['PM0108E5S', '5/16', '1/2-24'], ['PM0108E6S', '5/16', '9/16-24'],
                ['PI0112E5S', '3/8', '1/2-24'], ['PI0112E6S', '3/8', '9/16-24'],
            ]))
            ->merge($this->expand($cat, 'Terminale diritto', "Tipo American \"Flare\"", 'MFL', 'tube_thread', [
                ['PI0108F4S', '1/4', '1/4'], ['PI0112F4S', '3/8', '1/4'], ['PI0112F5S', '3/8', '5/16'],
                ['PI0112F6S', '3/8', '3/8'], ['PI0112F8S', '3/8', '1/2'], ['PI0116F8S', '1/2', '1/2'],
                ['PM0108C5S', '5/16', '1/2-16 UN'], ['PI0112C5S', '3/8', '1/2-16 UN'],
            ]))
            ->merge($this->expand($cat, 'Intermedio a gomito', null, null, 'tube_only', [
                ['PM0304S', '5/32'], ['PI0306S', '3/16'], ['PI0308S', '1/4'], ['PM0308S', '5/16'], ['PI0312S', '3/8'], ['PI0316S', '1/2'],
            ]))
            ->merge($this->expand($cat, 'Intermedio diritto', null, null, 'tube_only', [
                ['PM0404S', '5/32'], ['PI0406S', '3/16'], ['PI0408S', '1/4'], ['PM0408S', '5/16'], ['PI0412S', '3/8'], ['PI0416S', '1/2'],
            ]))
            ->merge($this->expand($cat, 'Riduzione intermedia diritta', null, null, 'tube_tube', [
                ['PI200806S', '1/4', '3/16'], ['PM200804S', '5/16', '5/32'],
                ['PI201006S', '5/16', '3/16'], ['PI201008S', '5/16', '1/4'],
                ['PI201206S', '3/8', '3/16'], ['PI201208S', '3/8', '1/4'], ['PI201210S', '3/8', '5/16'],
                ['PI201608S', '1/2', '1/4'], ['PI201610S', '1/2', '5/16'], ['PI201612S', '1/2', '3/8'],
            ]))
            ->merge($this->expand($cat, 'Riduzione intermedia a gomito', null, null, 'tube_tube', [
                ['PM210804S', '5/16', '5/32'],
                ['PI211006S', '5/16', '3/16'], ['PI211008S', '5/16', '1/4'],
                ['PI211206S', '3/8', '3/16'], ['PI211208S', '3/8', '1/4'], ['PI211210S', '3/8', '5/16'],
                ['PI211610S', '1/2', '5/16'], ['PI211612S', '1/2', '3/8'],
            ]))
            ->merge($this->expand($cat, 'Gomito filettato', null, 'NPTF', 'tube_thread', [
                ['PI480821S', '1/4', '1/8'], ['PI480822S', '1/4', '1/4'], ['PI480823S', '1/4', '3/8'],
                ['PI481022S', '5/16', '1/4'], ['PI481023S', '5/16', '3/8'],
                ['PI481222S', '3/8', '1/4'], ['PI481223S', '3/8', '3/8'],
                ['PI482024S', '5/8', '1/2'],
            ]))
            ->merge($this->expand($cat, 'Intermedio a T', null, null, 'tube_only', [
                ['PM0204S', '5/32'], ['PI0206S', '3/16'], ['PI0208S', '1/4'], ['PM0208S', '5/16'], ['PI0212S', '3/8'], ['PI0216S', '1/2'],
            ]))
            ->merge($this->expand($cat, 'Riduzione a T', null, null, 'tube_tube', [
                ['PI301208S', '3/8', '1/4', 'Tubo laterale 3/8, tubo centrale 1/4.'],
                ['PI301612S', '1/2', '3/8', 'Tubo laterale 1/2, tubo centrale 3/8.'],
            ]))
            ->merge($this->expand($cat, 'Gomito con codolo', null, null, 'barb_tube', [
                ['PM220404S', '5/32', '5/32'], ['PI220606S', '3/16', '3/16'],
                ['PI220808S', '1/4', '1/4'], ['PM220808S', '5/16', '5/16'],
                ['PI221206S', '3/8', '3/16'], ['PI221208S', '3/8', '1/4'], ['PI221210S', '3/8', '5/16'], ['PI221212S', '3/8', '3/8'],
                ['PI221616S', '1/2', '1/2'],
            ]))
            ->merge($this->expand($cat, 'Terminale diritto femmina', 'Filettatura cilindrica - Estremità piatta', 'BSP', 'tube_thread', [
                ['PI451014FS', '5/16', '1/2'], ['PI451015FS', '5/16', '5/8'],
                ['PI451213S', '3/8', '3/8'], ['PI451214FS', '3/8', '1/2'], ['PI451215FS', '3/8', '5/8'],
                ['PI451613S', '1/2', '3/8'], ['PI451615FS', '1/2', '5/8'],
            ]))
            ->merge($this->expand($cat, 'Terminale diritto femmina', 'Filettatura cilindrica - Estremità a cono', 'BSP', 'tube_thread', [
                ['PI451014CS', '5/16', '1/2'], ['PI451015CS', '5/16', '5/8'],
                ['PI451214CS', '3/8', '1/2'], ['PI451215CS', '3/8', '5/8'],
                ['PI451614CS', '1/2', '1/2'], ['PI451615CS', '1/2', '5/8'], ['PI451616CS', '1/2', '3/4'],
            ]))
            ->merge($this->expand($cat, 'Terminale diritto femmina', 'Filettatura cilindrica - Estremità a cono ridotta', 'BSP', 'tube_thread', [
                ['NCPI451214CS', '3/8', '1/2'],
            ], "Tipicamente utilizzato sul lato birra e sul lato CO2 delle testate per fusti. Il cono interno è compatibile con le valvole di non ritorno a becco d'anatra."))
            ->merge($this->expand($cat, 'Terminale diritto femmina', 'Filettatura UNS', 'UNS', 'tube_thread', [
                ['PM4508C5S', '5/16', '1/2-16'], ['PI4512C5S', '3/8', '1/2-16'], ['PI4516C5S', '1/2', '1/2-16'],
            ]))
            ->merge($this->expand($cat, 'Terminale diritto femmina', 'Filettatura NPTF', 'NPTF', 'tube_thread', [
                ['PI450822S', '1/4', '1/4'], ['PI451222S', '3/8', '1/4'],
            ]))
            ->merge($this->expand($cat, 'Terminale diritto femmina', 'Filettatura British Whitworth', 'BSW', 'tube_thread', [
                ['PM4508E5S', '5/16', '1/2-24'], ['PI4512E6S', '3/8', '9/16-24'],
            ]))
            ->merge($this->expand($cat, 'Terminale diritto femmina', 'Filettatura FFL', 'FFL', 'tube_thread', [
                ['PM4508F4S', '5/16', '1/4'], ['PI4512F4S', '3/8', '1/4'], ['PI4512F6S', '3/8', '3/8'],
            ], '1/4 FFL equivale a 7/16 UNF; 3/8 FFL equivale a 5/8 UNF.'))
            ->merge($this->expand($cat, 'Raccordo testata fusto', null, 'BSP', 'tube_thread', [
                ['PI561214CS', '3/8', '1/2'],
            ]))
            ->merge($this->expand($cat, 'Raccordo testata fusto + V.N.R.', null, 'BSP', 'tube_thread', [
                ['PI561214CS-NRV2', '3/8', '1/2'],
            ]))
            ->merge($this->expand($cat, 'Terminale diritto femmina', 'Filettatura cilindrica', 'BSP', 'tube_thread', [
                ['CI320814S', '1/4', '1/2'], ['CI320816S', '1/4', '3/4'],
                ['CI321214S', '3/8', '1/2'], ['CI321216S', '3/8', '3/4'],
            ], 'Non adatto per aria.'))
            ->merge($this->expand($cat, 'Terminale diritto femmina', 'Filettatura cilindrica - Estremità piatta', 'BSP', 'tube_thread', [
                ['CI320816FS', '1/4', '3/4'], ['CI321216FS', '3/8', '3/4'],
            ], 'Non adatto per aria.'))
            ->merge($this->expand($cat, 'Terminale diritto femmina', 'Filettatura UNS', 'UNS', 'tube_thread', [
                ['CI3208U7S', '1/4', '7/16-24'], ['CI3210U7S', '5/16', '7/16-24'], ['CI3212U7S', '3/8', '7/16-24'],
            ], 'Non adatto per aria.'))
            ->merge($this->expand($cat, 'Intermedio a 3 vie', null, null, 'tube_tube', [
                ['PI491612S', '1/2', '3/8'], ['PI491616S', '1/2', '1/2'], ['PI491212S-R', '3/8', '3/8'],
            ], 'Tubo Ø ingresso - tubo Ø uscita.'))
            ->merge($this->expand($cat, 'Intermedio a Y', null, null, 'tube_only', [
                ['PI2308S', '1/4'], ['PM2308S', '5/16'], ['PI2312S', '3/8'], ['PI2316S', '1/2'],
            ]))
            ->merge($this->expand($cat, 'Intermedio a Y ridotto', null, null, 'tube_tube', [
                ['PI241210S', '3/8', '5/16'],
            ], 'Tubo Ø ingresso - tubo Ø uscita.'))
            ->merge($this->expand($cat, 'Passaparete', null, null, 'tube_only', [
                ['PM1204S', '5/32'], ['PI1206S', '3/16'], ['PI1208S', '1/4'], ['PM1208S', '5/16'], ['PI1212S', '3/8'], ['PI1216S', '1/2'],
            ]))
            ->merge($this->expand($cat, 'Passaparete ridotto', null, null, 'tube_tube', [
                ['PI121208S', '3/8', '1/4'], ['NC2429', '1/4"', '6mm'],
            ]))
            ->merge($this->expand($cat, 'Passaparete', 'Con ghiera in plastica', null, 'tube_only', [
                ['NCPI1208S-P', '1/4'], ['NCPI1212S-P', '3/8'], ['NCPI1216S-P', '1/2'],
            ], 'Le ghiere sono in resina acetalica nera. Disponibili solo per quantità scatola.'))
            ->merge($this->expand($cat, 'Terminale con codolo', 'Filettatura conica', 'BSPT', 'barb_thread', [
                ['PI050601S', '3/16', '1/8'],
                ['PI050801S', '1/4', '1/8'], ['PI050802S', '1/4', '1/4'],
                ['PM050801S', '5/16', '1/8'], ['PM050802S', '5/16', '1/4'], ['PM050803S', '5/16', '3/8'],
                ['PI051202S', '3/8', '1/4'], ['PI051203S', '3/8', '3/8'],
                ['PI051603S', '1/2', '3/8'], ['PI051604S', '1/2', '1/2'],
            ]))
            ->merge($this->expand($cat, 'Terminale con codolo', 'Filettatura cilindrica', 'BSP', 'barb_thread', [
                ['PM050812S', '5/16', '1/4'], ['PI051212S', '3/8', '1/4'], ['PI051213S', '3/8', '3/8'],
            ]))
            ->merge($this->expand($cat, 'Terminale con codolo', 'Filettatura NPTF', 'NPTF', 'barb_thread', [
                ['PM050421S', '5/32', '1/8'], ['PM050422S', '5/32', '1/4'],
                ['PI050621S', '3/16', '1/8'],
                ['PI050821S', '1/4', '1/8'], ['PI050822S', '1/4', '1/4'], ['PI050823S', '1/4', '3/8'],
                ['PM050821S', '5/16', '1/8'], ['PM050822S', '5/16', '1/4'], ['PM050823S', '5/16', '3/8'],
                ['PI051222S', '3/8', '1/4'], ['PI051223S', '3/8', '3/8'],
                ['PI051623S', '1/2', '3/8'], ['PI051624S', '1/2', '1/2'],
            ]))
            ->merge($this->expand($cat, 'Terminale con codolo', 'Filettatura Whitworth', 'BSW', 'barb_thread', [
                ['PM0508E6S', '5/16', '9/16-24'], ['PI0512E5S', '3/8', '9/16-24'], ['PI0512E6S', '3/8', '9/16-24'],
            ]))
            ->merge($this->expand($cat, 'Codolo portagomma', null, null, 'barb_tube', [
                ['PI250806S', '1/4', '3/16'], ['PI250808S', '1/4', '1/4'], ['PI250810S', '1/4', '5/16'],
                ['PI251006S', '5/16', '3/16'], ['PI251008S', '5/16', '1/4'], ['PM250808S', '5/16', '5/16'], ['PI251012S', '5/16', '3/8'],
                ['PI251208S', '3/8', '1/4'], ['PI251210S', '3/8', '5/16'], ['PI251212S', '3/8', '3/8'], ['PI251216S', '3/8', '1/2'],
                ['PI251612S', '1/2', '3/8'], ['PI251616S', '1/2', '1/2'],
            ]))
            ->merge($this->expand($cat, 'Codolo portagomma', 'Versione allungata', null, 'barb_tube', [
                ['PI251012SL', '5/16', '3/8'], ['PI251212SL', '3/8', '3/8'],
            ]))
            ->merge($this->expand($cat, 'Codolo portagomma a gomito', null, null, 'barb_tube', [
                ['PI290808S', '1/4', '1/4'], ['PI290810S', '1/4', '5/16'],
                ['PI291008S', '5/16', '1/4'], ['PM290808S', '5/16', '5/16'],
                ['PI291208S', '3/8', '1/4'], ['PI291210S', '3/8', '5/16'],
            ]))
            ->merge($this->expand($cat, 'Codolo per valvole erogazione', null, null, 'code_only', [
                ['NC730-02', null, null, 'Codolo Ø 3/8.'],
            ]))
            ->merge($this->expand($cat, 'Gomito per valvole erogazione', null, null, 'tube_only', [
                ['NC356-02', '3/8'],
            ]))
            ->merge($this->expand($cat, 'Intermedio a croce', null, null, 'tube_only', [
                ['PI4712S', '3/8'],
            ]))
            ->merge($this->expand($cat, 'Riduzione', null, null, 'barb_tube', [
                ['PI060605S', '3/16', '5/32'],
                ['PI061006S', '5/16', '3/16'], ['PI061008S', '5/16', '1/4'],
                ['PI061206S', '3/8', '3/16'], ['PI061208S', '3/8', '1/4'], ['PI061210S', '3/8', '5/16'],
                ['PI061610S', '1/2', '5/16'], ['PI061612S', '1/2', '3/8'],
            ]))
            ->merge($this->expand($cat, 'Riduzione piccola-grande', null, null, 'tube_barb', [
                ['PI131012S', '3/8', '5/16'], ['PI131216S', '1/2', '3/8'],
            ]))
            ->merge($this->expand($cat, 'Piega a 40°', null, null, 'tube_tube', [
                ['NC641', '1/2', '5/16'],
            ]))
            ->merge($this->expand($cat, 'Curva a U', null, null, 'tube_only', [
                ['PIUB12S', '3/8'], ['PIUB16S', '1/2'],
            ]))
            ->merge($this->expand($cat, 'Terminale cieco', null, null, 'tube_only', [
                ['PI4608S', '1/4'], ['PI4612S', '3/8'],
            ]))
            ->merge($this->expand($cat, 'Tappo', null, null, 'barb_only', [
                ['PM0804S', '5/32'], ['PI0806S', '3/16'], ['PI0808S', '1/4'], ['PM0808S', '5/16'], ['PI0812S', '3/8'], ['PI0816S', '1/2'],
            ]))
            ->merge($this->expand($cat, 'Intermedio a T con codolo', null, null, 'tube_only', [
                ['NC2304', '1/4'],
            ]))
            ->merge($this->expand($cat, 'Collettore per il raffreddamento', null, null, 'tube_tube', [
                ['NC2183', '15mm', '3/8"'],
            ], 'Ø tubi laterali - Ø tubi centrali. Per impianti di ricircolo acqua a 15mm.'))
            ->all();
    }

    private function polarClean(): array
    {
        $cat = self::CAT_POLARCLEAN;

        return collect()
            ->merge($this->expand($cat, 'Intermedio diritto coassiale', null, null, 'tube_only', [
                ['NC2617', '3/8'],
            ], 'Diametro tubo interno.'))
            ->merge($this->expand($cat, 'Gomito coassiale', null, null, 'tube_only', [
                ['NC2618', '3/8'],
            ], 'Diametro tubo interno.'))
            ->merge($this->expand($cat, 'Gomito coassiale ridotto', null, null, 'tube_tube', [
                ['NC2635', '3/8', '8'],
            ], 'Tubo interno OD - tubo interno OD (colletto rosso).'))
            ->merge($this->expand($cat, 'Raccordo coassiale adattatore fusto', '1/2 BSP', 'Filettatura cilindrica', 'tube_tube', [
                ['NC2648', '3/8', '1/2', 'Dado Ø 1/2. Usare gomito con codice PI221616.'],
                ['NC2782', '3/8', '1/2', 'Dado Ø 5/8.'],
            ]))
            ->merge($this->expand($cat, 'T coassiale', null, null, 'tube_tube', [
                ['NC909', '3/8', '3/8'],
            ], 'Tubo birra Ø - passante linea refrigerante Ø.'))
            ->merge($this->expand($cat, 'Derivazione coassiale', null, null, 'tube_tube', [
                ['NC2546', '3/8', '1/2'],
            ], 'Tubo birra Ø - passante linea refrigerante Ø.'))
            ->all();
    }

    private function resinaBianca(): array
    {
        $cat = self::CAT_BIANCA;

        return collect()
            ->merge($this->expand($cat, 'Intermedio diritto', null, null, 'tube_only', [
                ['CI0408W', '1/4'], ['CI0412W', '3/8'],
            ]))
            ->merge($this->expand($cat, 'Terminale diritto', null, 'NPTF', 'tube_thread', [
                ['CI010821W', '1/4', '1/8'], ['CI010822W', '1/4', '1/4'], ['CI010823W', '1/4', '3/8'], ['CI011222W', '3/8', '1/4'],
            ]))
            ->merge($this->expand($cat, 'Intermedio a gomito', null, null, 'tube_only', [
                ['CI0308W', '1/4'], ['CI0312W', '3/8'],
                ['CI0308WB', '1/4', null, 'Colletto blu.'], ['CI0312WB', '3/8', null, 'Colletto blu.'], ['CI0312WR', '3/8', null, 'Colletto rosso.'],
            ]))
            ->merge($this->expand($cat, 'Gomito con codolo', null, null, 'barb_tube', [
                ['CI220808W', '1/4', '1/4'], ['CI221208W', '3/8', '1/4'], ['CI221212W', '3/8', '3/8'],
                ['CI220808WB', '1/4', '1/4', 'Colletto blu.'], ['CI221212WB', '3/8', '3/8', 'Colletto blu.'],
                ['CI220808WR', '1/4', '1/4', 'Colletto rosso.'], ['CI221212WR', '3/8', '3/8', 'Colletto rosso.'],
            ]))
            ->merge($this->expand($cat, 'Gomito filettato', null, 'NPTF', 'tube_thread', [
                ['CI480821W', '1/4', '1/8'], ['CI480822W', '1/4', '1/4'], ['CI480823W', '1/4', '3/8'], ['CI481222W', '3/8', '1/4'],
                ['CI480821WB', '1/4', '1/8', 'Colletto blu.'],
            ]))
            ->merge($this->expand($cat, 'Intermedio a T', null, null, 'tube_only', [
                ['CI0208W', '1/4'], ['CI0212W', '3/8'], ['CI0208WB', '1/4', null, 'Colletto blu.'],
            ]))
            ->merge($this->expand($cat, 'T con codolo', null, null, 'tube_tube', [
                ['CI580808W', '1/4', '1/4'],
            ], 'Tubo/codolo/tubo, tutte le vie da 1/4.'))
            ->merge($this->expand($cat, 'T con codolo laterale', null, null, 'tube_tube', [
                ['CI530808W', '1/4', '1/4'],
            ], 'Tutte le vie da 1/4.'))
            ->merge($this->expand($cat, 'Passaparete', null, null, 'tube_only', [
                ['CI1208W', '1/4'],
            ]))
            ->merge($this->expand($cat, 'Terminale diritto femmina', 'Filettatura NPTF', 'NPTF', 'tube_thread', [
                ['CI451222W', '3/8', '1/4'],
            ]))
            ->merge($this->expand($cat, 'Terminale con codolo', 'Filettatura NPTF', 'NPTF', 'barb_thread', [
                ['CI050821W', '1/4', '1/8'], ['CI050822W', '1/4', '1/4'], ['CI051222W', '3/8', '1/4'], ['CI051223W', '3/8', '3/8'],
            ]))
            ->merge($this->expand($cat, 'Terminale diritto femmina', 'Filettatura cilindrica', 'BSP', 'tube_thread', [
                ['CI450814FW', '1/4', '1/2'],
            ]))
            ->merge($this->expand($cat, 'Codolo portagomma a gomito', null, null, 'barb_tube', [
                ['CI291208W', '3/8', '1/4'],
            ]))
            ->merge($this->expand($cat, 'Terminale diritto femmina', null, 'UNS', 'tube_thread', [
                ['CI3212U7FW', '3/8', '7/16'],
            ]))
            ->merge($this->expand($cat, 'Riduzione a portagomma', null, null, 'barb_tube', [
                ['CI061208W', '3/8', '1/4'],
            ]))
            ->merge($this->expand($cat, 'Raccordo portagomma', 'Superseal', null, 'tube_tube', [
                ['CI270808W', '1/4', '1/4'],
            ], 'Superseal Ø - tubo ID.'))
            ->all();
    }

    private function polipropilenePollici(): array
    {
        $cat = self::CAT_PP_POLLICI;

        return collect()
            ->merge($this->expand($cat, 'Terminale diritto', null, 'NPTF', 'tube_thread', [
                ['PP010821W', '1/4', '1/8'], ['PP010822W', '1/4', '1/4'], ['PP010823W', '1/4', '3/8'], ['PP010824W', '1/4', '1/2'],
                ['PP011222W', '3/8', '1/4'], ['PP011223W', '3/8', '3/8'], ['PP011224W', '3/8', '1/2'],
                ['PP011623W', '1/2', '3/8'], ['PP011624W', '1/2', '1/2'],
            ]))
            ->merge($this->expand($cat, 'Intermedio diritto', null, null, 'tube_only', [
                ['PP0408W', '1/4'], ['PPM0408W', '5/16'], ['PP0412W', '3/8'], ['PP0416W', '1/2'],
            ]))
            ->merge($this->expand($cat, 'Riduzione intermedia diritta', null, null, 'tube_tube', [
                ['PP201208W', '3/8', '1/4'], ['PP201612W', '1/2', '3/8'],
            ]))
            ->merge($this->expand($cat, 'Intermedio a T', null, null, 'tube_only', [
                ['PP0208W', '1/4'], ['PPM0208W', '5/16'], ['PP0212W', '3/8'], ['PP0216W', '1/2'],
                ['PP0208W-B', '1/4', null, 'Colletto blu.'],
            ]))
            ->merge($this->expand($cat, 'Riduzione a T', null, null, 'tube_tube', [
                ['PP30080812W', '1/4', '1/4'], ['PP30120812W', '3/8', '1/4'], ['PP30121208W', '3/8', '3/8'], ['PP301612W', '1/2', '1/2'],
            ], 'Tubo Ø laterale - tubo Ø laterale, centrale 3/8 (vedi scheda per dettaglio a 3 vie).'))
            ->merge($this->expand($cat, 'T con codolo laterale', null, null, 'tube_tube', [
                ['PP531212W', '3/8', '3/8'],
            ], 'Tutte le vie da 3/8.'))
            ->merge($this->expand($cat, 'Intermedio a gomito', null, null, 'tube_only', [
                ['PP0308W', '1/4'], ['PPM0308W', '5/16'], ['PP0312W', '3/8'], ['PP0316W', '1/2'],
                ['PP0308W-B', '1/4', null, 'Colletto blu.'],
            ]))
            ->merge($this->expand($cat, 'Gomito ridotto', null, null, 'tube_tube', [
                ['PP211008W', '5/16', '1/4'], ['PP211208W', '3/8', '1/4'], ['PP211612W', '1/2', '3/8'],
            ]))
            ->merge($this->expand($cat, 'Gomito con codolo', null, null, 'barb_tube', [
                ['PP220808W', '1/4', '1/4'], ['PPM220808W', '5/16', '5/16'],
                ['PP221208W', '3/8', '1/4'], ['PP221212W', '3/8', '3/8'], ['PP221616W', '1/2', '1/2'],
                ['PP221212W-B', '3/8', '3/8', 'Colletto blu.'],
            ]))
            ->merge($this->expand($cat, 'Gomito filettato', null, 'NPTF', 'tube_thread', [
                ['PP480821W', '1/4', '1/8'], ['PP480822W', '1/4', '1/4'], ['PP480823W', '1/4', '3/8'],
                ['PP481222W', '3/8', '1/4'], ['PP481223W', '3/8', '3/8'], ['PP481623W', '1/2', '3/8'],
            ]))
            ->merge($this->expand($cat, 'Passaparete', null, null, 'tube_only', [
                ['PP1208W', '1/4'], ['PP1212W', '3/8'], ['PP1216W', '1/2'],
            ]))
            ->merge($this->expand($cat, 'Passaparete ridotto', null, null, 'tube_tube', [
                ['PP121208W', '3/8', '1/4'],
            ]))
            ->merge($this->expand($cat, 'Riduzione a 3 vie', null, null, 'tube_tube', [
                ['PP491208W', '3/8', '1/4'],
            ], 'Tubo Ø ingresso - tubo Ø uscita.'))
            ->merge($this->expand($cat, 'Intermedio a Y', 'Resistente ai raggi UV', null, 'tube_only', [
                ['PP2308E', '1/4'],
            ]))
            ->merge($this->expand($cat, 'Intermedio a Y', null, null, 'tube_only', [
                ['PP2312W', '3/8'],
            ]))
            ->merge($this->expand($cat, 'Terminale diritto femmina', null, 'NPTF', 'tube_thread', [
                ['PP450821W', '1/4', '1/8'], ['PP450822W', '1/4', '1/4'], ['PP451222W', '3/8', '1/4'], ['PP451223W', '3/8', '3/8'],
            ]))
            ->merge($this->expand($cat, 'Terminale diritto femmina', null, 'UNS', 'tube_thread', [
                ['PP3208U7W', '1/4', '7/16-24'], ['PP3212U7W', '3/8', '7/16-24'],
            ]))
            ->merge($this->expand($cat, 'Terminale con codolo', null, 'NPTF', 'barb_thread', [
                ['PP050821W', '1/4', '1/8'], ['PP050822W', '1/4', '1/4'],
                ['PP051222W', '3/8', '1/4'], ['PP051223W', '3/8', '3/8'], ['PP051623W', '1/2', '3/8'], ['PP051624W', '1/2', '1/2'],
            ]))
            ->merge($this->expand($cat, 'Tappo', null, null, 'barb_only', [
                ['PP0808W', '1/4'], ['PPM0808W', '5/16'], ['PP0812W', '3/8'], ['PP0816W', '1/2'],
            ]))
            ->merge($this->expand($cat, 'Codolo portagomma', null, null, 'barb_tube', [
                ['PP251212W', '3/8', '3/8'], ['PP251216W', '3/8', '1/2'], ['PP251612W', '1/2', '3/8'], ['PP251616W', '1/2', '1/2'],
            ]))
            ->merge($this->expand($cat, 'Y ridotto', null, null, 'tube_tube', [
                ['PP241208W', '1/4', '3/8'],
            ]))
            ->merge($this->expand($cat, 'Riduzione', null, null, 'barb_tube', [
                ['PP061208W', '3/8', '1/4'], ['PP061210W', '3/8', '5/16'], ['PP061612W', '1/2', '3/8'],
                ['PP062008W', '5/8', '1/4'], ['PP062012W', '5/8', '3/8'], ['PP062016W', '5/8', '1/2'],
            ]))
            ->merge($this->expand($cat, 'Terminale cieco', null, null, 'tube_only', [
                ['PP4608W', '1/4'],
            ]))
            ->all();
    }

    private function superseal(): array
    {
        $cat = self::CAT_SUPERSEAL;

        return collect()
            ->merge($this->expand($cat, 'Terminale diritto', 'Filettatura conica', 'BSPT', 'tube_thread', [
                ['SM010802S', '5/16', '1/4'],
            ]))
            ->merge($this->expand($cat, 'Terminale diritto', 'Filettatura cilindrica', 'BSP', 'tube_thread', [
                ['SM010812S', '5/16', '1/4'],
            ]))
            ->merge($this->expand($cat, 'Terminale diritto', 'Filettatura American Flare', 'MFL', 'tube_thread', [
                ['SM0108F4S', '5/16', '1/4'],
            ]))
            ->merge($this->expand($cat, 'Terminale diritto', 'Filettatura Whitworth', 'BSW', 'tube_thread', [
                ['SI0112E6S', '3/8', '9/16-24'],
            ]))
            ->merge($this->expand($cat, 'Intermedio diritto', 'Superseal x Speedfit', null, 'tube_tube', [
                ['SM410808E', '5/16', '5/16'],
                ['SM040808S', '5/16', '5/16'], ['SI041012S', '5/16', '3/8'], ['SI041016S', '5/16', '1/2'],
                ['SI041210S', '3/8', '5/16'], ['SI041212S', '3/8', '3/8'], ['SI041216S', '3/8', '1/2'],
            ], 'Superseal Ø - Speedfit Ø.'))
            ->merge($this->expand($cat, 'Intermedio diritto', 'Superseal x Speedfit metrico', null, 'tube_tube', [
                ['SM040608E', '6mm', '8mm'],
            ], 'Superseal Ø - Speedfit Ø.'))
            ->merge($this->expand($cat, 'Intermedio a gomito', 'Superseal x Speedfit', null, 'tube_tube', [
                ['SM400808S', '5/16', '5/16'], ['SI401210S', '3/8', '5/16'], ['SI401212S', '3/8', '3/8'],
                ['SI030812S', '1/4', '3/8'], ['SM030808S', '5/16', '5/16'], ['SI031012S', '5/16', '3/8'],
                ['SI031210S', '3/8', '5/16'], ['SI031212S', '3/8', '3/8'],
            ], 'Superseal Ø - Speedfit Ø.'))
            ->merge($this->expand($cat, 'Curva intermedia', 'Superseal x Speedfit', null, 'tube_tube', [
                ['SM420808S', '5/16', '5/16'], ['SI421012S', '5/16', '3/8'], ['SI421210S', '3/8', '5/16'], ['SI421212S', '3/8', '3/8'],
            ], 'Superseal Ø - Speedfit Ø.'))
            ->merge($this->expand($cat, 'Raccordo a portagomma', null, null, 'tube_tube', [
                ['SI270808S', '1/4', '1/4'], ['SI271008S', '5/16', '1/4'], ['SI271208S', '3/8', '1/4'],
            ], 'Superseal Ø - tubo Ø interno.'))
            ->merge($this->expand($cat, 'Intermedio diritto', null, null, 'tube_tube', [
                ['NC2301', '1/2"', '15mm'],
            ], 'Superseal Ø - Speedfit Ø.'))
            ->merge($this->expand($cat, 'Passaparete', 'Superseal x Speedfit', null, 'tube_tube', [
                ['SM120808S', '5/16', '5/16'],
            ], 'Superseal Ø - Speedfit Ø.'))
            ->merge($this->expand($cat, 'Chiave per Superseal', null, null, 'code_only', [
                ['SPAN1'],
            ]))
            ->all();
    }

    private function ottone(): array
    {
        $cat = self::CAT_OTTONE;

        return collect()
            ->merge($this->expand($cat, 'Terminale femmina in ottone', null, 'FFL', 'tube_thread', [
                ['MI4508F4S', '1/4', '1/4'], ['MI4512F4S', '3/8', '1/4'], ['MI4512F6S', '3/8', '3/8'],
            ]))
            ->merge($this->expand($cat, 'Adattatore femmina in ottone', 'Filettatura NF', 'GH', 'tube_thread', [
                ['NC2098', '1/4', '3/4'],
            ]))
            ->merge($this->expand($cat, 'Ghiera per elettrovalvole', null, 'BSPP', 'tube_thread', [
                ['NC2145', '1/4', '3/4'], ['NC2249', '3/8', '3/4'],
            ], "Con il gomito con codolo di pag. 11 (PI221616) si realizza una connessione a 90°."))
            ->all();
    }

    private function adattatoriMetriciPollici(): array
    {
        $cat = self::CAT_ADATTATORI;

        return collect()
            ->merge($this->expand($cat, 'Intermedio diritto', null, null, 'tube_tube', [
                ['NC462', '15mm', '1/2"'],
                ['NC2511', '15mm', '3/8"'],
            ]))
            ->merge($this->expand($cat, 'Riduzione a codolo', null, null, 'barb_tube', [
                ['NC2164', '15mm', '3/8"'], ['NC2173', '1/2"', '15mm'], ['NC716', '3/8"', '10mm'], ['NC2586', '1/4"', '6mm'],
            ]))
            ->merge($this->expand($cat, 'Codolo portagomma', null, null, 'barb_tube', [
                ['NC932', '15mm', '1/2"'],
            ], 'Codolo Ø interno - tubo Ø.'))
            ->merge($this->expand($cat, 'Raccordo portagomma', null, null, 'barb_tube', [
                ['NC448', '15mm', '1/2"'],
            ], 'Codolo Ø interno - tubo Ø.'))
            ->merge($this->expand($cat, 'Intermedio a T ridotto', null, null, 'tube_tube', [
                ['NC869', '15mm', '3/8"'],
            ], 'Tubo Ø laterale - tubo Ø centrale.'))
            ->merge($this->expand($cat, 'Codolo doppio', null, null, 'barb_tube', [
                ['NC478', '15mm', '3/8"'],
            ], 'Codolo Ø - codolo Ø.'))
            ->all();
    }

    private function resinaAcetalicaMetrici(): array
    {
        $cat = self::CAT_ACETALICA_METRICI;

        return collect()
            ->merge($this->expand($cat, 'Terminale diritto femmina', 'Filettatura cilindrica - Estremità piatta', 'BSP', 'tube_thread', [
                ['CM451213FS', '12', '3/8'], ['CM451214FS', '12', '1/2'], ['CM451513FS', '15', '3/8'],
            ]))
            ->merge($this->expand($cat, 'Terminale per rubinetti', null, 'UNS', 'tube_thread', [
                ['CM3210U7E', '10', '7/16-24'],
            ]))
            ->merge($this->expand($cat, 'Terminale diritto femmina', 'Filettatura cilindrica - Estremità piatta', 'BSP', 'tube_thread', [
                ['CM320816FE', '8', '3/4'],
            ]))
            ->merge($this->expand($cat, 'Terminale cieco', null, null, 'tube_only', [
                ['CM4612W', '12'],
            ]))
            ->merge($this->expand($cat, 'Passaparete', null, null, 'tube_only', [
                ['CM1212W-X', '12'],
            ]))
            ->merge($this->expand($cat, 'Intermedio a Y', null, null, 'tube_only', [
                ['CM2312W', '12'], ['CM2315W', '15'],
            ]))
            ->merge($this->expand($cat, 'Terminale diritto', null, 'BSP', 'tube_thread', [
                ['CM011514S', '15', '1/2'], ['CM012216S', '22', '3/4'],
            ]))
            ->merge($this->expand($cat, 'Riduzione diritta', null, null, 'tube_tube', [
                ['CM201510S', '15', '10'],
            ]))
            ->merge($this->expand($cat, 'Intermedio a T', null, null, 'tube_only', [
                ['CM0212W-X', '12'],
            ]))
            ->all();
    }

    private function resinaNeraMetrici(): array
    {
        $cat = self::CAT_NERA_METRICI;

        return collect()
            ->merge($this->expand($cat, 'Terminale diritto', 'Filettatura cilindrica', 'BSP', 'tube_thread', [
                ['PM010411E', '4', '1/8'], ['PM010412E', '4', '1/4'],
                ['PM010511E', '5', '1/8'], ['PM010512E', '5', '1/4'],
                ['PM010611E', '6', '1/8'], ['PM010612E', '6', '1/4'],
                ['PM010811E', '8', '1/8'], ['PM010812E', '8', '1/4'], ['PM010813E', '8', '3/8'],
                ['PM011012E', '10', '1/4'], ['PM011013E', '10', '3/8'], ['PM011014E', '10', '1/2'],
                ['PM011213E', '12', '3/8'], ['PM011214E', '12', '1/2'],
                ['PM011513E', '15', '3/8', 'Novità.'], ['PM011514E', '15', '1/2'], ['PM011516E', '15', '3/4', 'Senza guarnizione alla base del filetto.'],
                ['PM011814E', '18', '1/2'], ['PM012216E', '22', '3/4'],
            ]))
            ->merge($this->expand($cat, 'Terminale diritto', 'Filettatura conica', 'BSPT', 'tube_thread', [
                ['PM010401E', '4', '1/8'], ['PM010402E', '4', '1/4'],
                ['PM010501E', '5', '1/8'], ['PM010502E', '5', '1/4'],
                ['PM010601E', '6', '1/8'], ['PM010602E', '6', '1/4'],
                ['PM010801E', '8', '1/8'], ['PM010802E', '8', '1/4'], ['PM010803E', '8', '3/8'], ['PM010804E', '8', '1/2'],
                ['PM011002E', '10', '1/4'], ['PM011003E', '10', '3/8'], ['PM011004E', '10', '1/2'],
                ['PM011203E', '12', '3/8'], ['PM011204E', '12', '1/2'],
            ]))
            ->merge($this->expand($cat, 'Terminale diritto', 'Filettatura NPTF', 'NPTF', 'tube_thread', [
                ['PM010622E', '6', '1/4'],
            ]))
            ->merge($this->expand($cat, 'Intermedio diritto', null, null, 'tube_only', [
                ['PM0404E', '4'], ['PM0405E', '5'], ['PM0406E', '6'], ['PM0408E', '8'],
                ['PM0410E', '10'], ['PM0412E', '12'], ['PM0415E', '15'], ['PM0418E', '18'], ['PM0422E', '22'],
            ]))
            ->merge($this->expand($cat, 'Intermedio a gomito', null, null, 'tube_only', [
                ['PM0304E', '4'], ['PM0305E', '5'], ['PM0306E', '6'], ['PM0308E', '8'],
                ['PM0310E', '10'], ['PM0312E', '12'], ['PM0315E', '15'], ['PM0318E', '18'], ['PM0322E', '22'],
            ]))
            ->merge($this->expand($cat, 'Riduzione intermedia diritta', null, null, 'tube_tube', [
                ['PM200604E', '6', '4'], ['PM200804E', '8', '4'], ['PM200806E', '8', '6'],
                ['PM201004E', '10', '4'], ['PM201006E', '10', '6'], ['PM201008E', '10', '8'],
                ['PM201208E', '12', '8'], ['PM201210E', '12', '10'],
            ]))
            ->merge($this->expand($cat, 'Riduzione intermedia a gomito', null, null, 'tube_tube', [
                ['PM210604E', '6', '4'], ['PM210804E', '8', '4'], ['PM210806E', '8', '6'],
                ['PM211004E', '10', '4'], ['PM211006E', '10', '6'], ['PM211008E', '10', '8'],
                ['PM211208E', '12', '8'], ['PM211210E', '12', '10'],
            ]))
            ->merge($this->expand($cat, 'Gomito filettato', null, 'NPTF', 'tube_thread', [
                ['PM480621E', '6', '1/8'], ['PM480622E', '6', '1/4'], ['PM480623E', '6', '3/8'],
            ]))
            ->merge($this->expand($cat, 'Gomito con codolo', null, null, 'tube_tube', [
                ['PM220404E', '4', '4'], ['PM220505E', '5', '5'], ['PM220606E', '6', '6'], ['PM220808E', '8', '8'],
                ['PM221010E', '10', '10'], ['PM221212E', '12', '12'], ['PM221515E', '15', '15'], ['PM221818E', '18', '18'], ['PM222222E', '22', '22'],
            ], 'Tubo Ø - codolo Ø.'))
            ->merge($this->expand($cat, 'Intermedio a T', null, null, 'tube_only', [
                ['PM0204E', '4'], ['PM0205E', '5'], ['PM0206E', '6'], ['PM0208E', '8'],
                ['PM0210E', '10'], ['PM0212E', '12'], ['PM0215E', '15'], ['PM0218E', '18'], ['PM0222E', '22'],
            ]))
            ->merge($this->expand($cat, 'Riduzione a T', null, null, 'tube_tube', [
                ['PM3006AE', '4', '6'], ['PM3018AE', '18', '15'], ['PM3022AE', '22', '15'],
            ], 'Tubo Ø laterale - tubo Ø centrale.'))
            ->merge($this->expand($cat, 'Passaparete', 'Con ghiera in plastica', null, 'tube_only', [
                ['NC2499-P', '4'], ['NC2478-P', '6'], ['NC2500-P', '8'], ['NC2501-P', '10'], ['NC2502-P', '12'],
            ], 'Ghiere in resina acetalica nera. Disponibili solo per quantità scatola.'))
            ->merge($this->expand($cat, 'Passaparete', null, null, 'tube_only', [
                ['PM1204E', '4'], ['PM1205E', '5'], ['PM1206E', '6'], ['PM1208E', '8'], ['PM1210E', '10'], ['PM1212E', '12'],
            ]))
            ->merge($this->expand($cat, 'Riduzione', null, null, 'barb_tube', [
                ['PM060504E', '5', '4'], ['PM060604E', '6', '4'], ['PM060605E', '6', '5'],
                ['PM060804E', '8', '4'], ['PM060805E', '8', '5'], ['PM060806E', '8', '6'],
                ['PM061006E', '10', '6'], ['PM061008E', '10', '8'],
                ['PM061208E', '12', '8'], ['PM061210E', '12', '10'],
                ['PM061510E', '15', '10'], ['PM061512E', '15', '12'],
                ['PM061815E', '18', '15'], ['PM062215E', '22', '15'], ['PM062218E', '22', '18'],
            ]))
            ->merge($this->expand($cat, 'Riduzione piccola-grande', null, null, 'tube_barb', [
                ['PM130405E', '5', '4'],
            ]))
            ->merge($this->expand($cat, 'Terminale con codolo', 'Filettatura cilindrica', 'BSP', 'barb_thread', [
                ['PM050411E', '4', '1/8'], ['PM050412E', '4', '1/4'],
                ['PM050511E', '5', '1/8'], ['PM050512E', '5', '1/4'],
                ['PM050611E', '6', '1/8'], ['PM050612E', '6', '1/4'],
                ['PM050811E', '8', '1/8'], ['PM050812E', '8', '1/4'], ['PM050813E', '8', '3/8'],
                ['PM051012E', '10', '1/4'], ['PM051013E', '10', '3/8'], ['PM051014E', '10', '1/2'],
                ['PM051213E', '12', '3/8'], ['PM051214E', '12', '1/2'],
                ['PM051513E', '15', '3/8'], ['PM051514E', '15', '1/2'],
                ['PM051814E', '18', '1/2'],
                ['PM052214E', '22', '1/2'], ['PM052216E', '22', '3/4'],
            ]))
            ->merge($this->expand($cat, 'Terminale con codolo', 'Filettatura conica', 'BSPT', 'barb_thread', [
                ['PM050401E', '4', '1/8'], ['PM050402E', '4', '1/4'],
                ['PM050501E', '5', '1/8'], ['PM050502E', '5', '1/4'],
                ['PM050601E', '6', '1/8'], ['PM050602E', '6', '1/4'],
                ['PM050801E', '8', '1/8'], ['PM050802E', '8', '1/4'], ['PM050803E', '8', '3/8'],
                ['PM051002E', '10', '1/4'], ['PM051003E', '10', '3/8'], ['PM051004E', '10', '1/2'],
                ['PM051203E', '12', '3/8'], ['PM051204E', '12', '1/2'],
            ]))
            ->merge($this->expand($cat, 'Intermedio a Y', null, null, 'tube_only', [
                ['RM2306E', '6'], ['RM2308E', '8'], ['PM2312E', '12'], ['PM2315E', '15'],
            ], 'Per il nuovo Pitone Gemellato Approvato BDA per l\'acqua di raffreddamento, mandata e ritorno.'))
            ->merge($this->expand($cat, 'Tappo', null, null, 'barb_only', [
                ['PM0804R', '4'], ['PM0805R', '5'], ['PM0806R', '6'], ['PM0808R', '8'],
                ['PM0810R', '10'], ['PM0812R', '12'], ['PM0815E', '15'], ['PM0818E', '18'], ['PM0822E', '22'],
            ], 'Taglie 4-12mm rosse, 15-22mm nere. Taglia 8mm disponibile anche in nero (cod. PM0808E).'))
            ->merge($this->expand($cat, 'Terminale diritto femmina', null, 'BSP', 'tube_thread', [
                ['PM450411E', '4', '1/8', 'Con guarnizione alla base del filetto.'],
                ['PM450611E', '6', '1/8'],
                ['PM450612E', '6', '1/4', 'Con guarnizione alla base del filetto.'],
                ['PM450812E', '8', '1/4'], ['PM450813E', '8', '3/8'],
                ['PM451013E', '10', '3/8'], ['PM451015FE', '10', '5/8', 'Colore grigio.'],
                ['PM451215FE', '12', '5/8'],
            ]))
            ->merge($this->expand($cat, 'Gomito ad angolo', null, null, 'tube_tube', [
                ['NC657', '12', '8'],
            ]))
            ->merge($this->expand($cat, 'Raccordo a "U"', null, null, 'tube_only', [
                ['PMUB15E', '15'],
            ]))
            ->merge($this->expand($cat, 'Codolo portagomma', null, null, 'barb_tube', [
                ['PM250604E', '6', '4'], ['PM250806E', '8', '6'], ['PM251008E', '10', '8'],
            ]))
            ->all();
    }

    private function polipropileneMetrici(): array
    {
        $cat = self::CAT_PP_METRICI;

        return collect()
            ->merge($this->expand($cat, 'Intermedio diritto', null, null, 'tube_only', [
                ['PPM0408W', '8'], ['PPM0412W', '12'],
            ]))
            ->merge($this->expand($cat, 'Gomito con codolo', null, null, 'barb_tube', [
                ['PPM220808W', '8', '8'], ['PPM221212W', '12', '12'],
            ], 'Stem Ø - tubo Ø.'))
            ->merge($this->expand($cat, 'Tappo', null, null, 'barb_only', [
                ['PPM0808W', '8'],
            ]))
            ->merge($this->expand($cat, 'Intermedio diritto ridotto', null, null, 'tube_tube', [
                ['PPM201512W', '15', '12'],
            ]))
            ->merge($this->expand($cat, 'Intermedio a T', null, null, 'tube_only', [
                ['PPM0208W', '8'], ['PPM0212W', '12'],
            ]))
            ->merge($this->expand($cat, 'Intermedio a Y', null, null, 'tube_only', [
                ['PPM2312W', '12'],
            ]))
            ->merge($this->expand($cat, 'Intermedio a gomito', null, null, 'tube_only', [
                ['PPM0308W', '8'], ['PPM0312W', '12'],
            ]))
            ->merge($this->expand($cat, 'Riduzione', null, null, 'barb_tube', [
                ['PPM061512W', '15', '12'],
            ]))
            ->all();
    }

    private function valvoleIntercettazione(): array
    {
        $cat = self::CAT_VALVOLE_INTERCETTAZIONE;

        return collect()
            ->merge($this->expand($cat, "Valvola d'intercettazione a T", null, null, 'tube_tube', [
                ['ASV3', '15mm', '1/4"'], ['ASV4', '15mm', '3/8"'],
            ], 'Tubo Ø - derivazione tubo Ø.'))
            ->merge($this->expand($cat, "Valvola d'intercettazione a T", null, 'BSP', 'tube_tube', [
                ['ASV7', '3/8', '3/8'], ['ASV8', '1/2', '1/2'], ['ASV9', '3/8', '3/8'], ['ASV10', '1/2', '1/2'],
                ['ASV11', '3/8', '3/8', 'Derivazione tubo Ø 5/16. Novità.'],
            ], 'Filetto BSP - filetto BSP, derivazione tubo Ø 3/8 salvo indicazione.'))
            ->merge($this->expand($cat, "Valvola d'intercettazione a 90°", null, 'BSP', 'tube_thread', [
                ['PISVBTC1214', '3/8', '1/2'],
            ], 'Solo per uso con liquidi.'))
            ->merge($this->expand($cat, 'Valvola di intercettazione', 'Manopola corta - resina acetalica grigia', null, 'tube_only', [
                ['PISV0412CS', '3/8'],
            ]))
            ->merge($this->expand($cat, 'Valvola di intercettazione', 'Manopola lunga - resina acetalica grigia', null, 'tube_only', [
                ['PISV0412S', '3/8'], ['PISV0416S', '1/2'],
            ]))
            ->merge($this->expand($cat, 'Valvola di intercettazione', null, null, 'tube_only', [
                ['NC2555', '3/8'],
            ], 'Doppio O-ring. Per uso domestico, acqua potabile e aria.'))
            ->merge($this->expand($cat, 'Valvola di intercettazione', 'Con staffa - manopola corta', null, 'tube_only', [
                ['PISV04KIT-SH', '3/8'],
            ], 'Fornita non assemblata.'))
            ->merge($this->expand($cat, 'Valvola di intercettazione', 'Con staffa - manopola lunga', null, 'tube_only', [
                ['PISV04KIT', '3/8'],
            ], 'Fornita non assemblata.'))
            ->all();
    }

    private function valvolePolipropilene(): array
    {
        $cat = self::CAT_VALVOLE_PP;

        return collect()
            ->merge($this->expand($cat, 'Valvola filettata femmina', null, 'NPTF', 'tube_thread', [
                ['PPSV451223E-X', '3/8', '3/8'],
            ]))
            ->merge($this->expand($cat, "Valvola per presa d'acqua", null, null, 'tube_thread', [
                ['ASVPP1', null, '9/16 UNEF M x 9/16 UNEF F', 'Tubo Ø 1/4. Disponibile anche in ottone senza piombo (suffisso LF).'],
                ['ASVPP2', null, '9/16 UNEF M x 9/16 UNEF F', 'Tubo Ø 3/8. Disponibile anche in ottone senza piombo (suffisso LF).'],
                ['ASVPP5', null, '1/2 BSP M x 1/2 BSP F', 'Tubo Ø 1/4. Disponibile anche in ottone senza piombo (suffisso LF).'],
                ['ASVPP6', null, '1/2 BSP M x 1/2 BSP F', 'Tubo Ø 3/8. Disponibile anche in ottone senza piombo (suffisso LF).'],
            ]))
            ->merge($this->expand($cat, 'Valvola intermedia', null, null, 'tube_only', [
                ['PPSV040808W', '1/4'], ['PPSV041212W', '3/8'],
                ['PPMSV040606W', '6mm'], ['PPMSV040808W', '8mm'], ['PPMSV041010W', '10mm'], ['PPMSV041212W', '12mm'],
            ]))
            ->merge($this->expand($cat, 'Valvola filettata femmina', null, 'NPTF', 'tube_thread', [
                ['PPSV500822W', '1/4', '1/4'], ['PPSV501222W', '3/8', '1/4'],
            ]))
            ->merge($this->expand($cat, 'Valvola filettata maschio', null, 'NPTF', 'tube_thread', [
                ['PPSV010822W', '1/4', '1/4'], ['PPSV011223W', '3/8', '3/8'],
            ]))
            ->merge($this->expand($cat, 'Staffa di montaggio', null, null, 'code_only', [
                ['SVMC-06', null, null, 'Per tubi 6mm e 1/4".'],
                ['SVMC-10', null, null, 'Per tubi 10mm e 3/8".'],
            ]))
            ->merge($this->expand($cat, "Valvola presa d'acqua", null, null, 'tube_thread', [
                ['PASVPP2', '3/8', '9/16 UNEF', 'Senza adattatore.'],
                ['PASVPP5', '1/4', '1/2 BSP M / 1/2 BSP F', 'Con adattatore.'],
                ['PASVPP6', '3/8', '1/2 BSP M / 1/2 BSP F', 'Con adattatore.'],
            ]))
            ->all();
    }

    private function valvoleNonRitorno(): array
    {
        $cat = self::CAT_VALVOLE_NON_RITORNO;

        return collect()
            ->merge($this->expand($cat, 'Valvola di non ritorno', null, null, 'tube_only', [
                ['1/4SCV', '1/4'], ['5/16SCV', '5/16'], ['3/8SCV', '3/8'],
            ], 'Solo per uso con liquidi.'))
            ->merge($this->expand($cat, 'Valvola di non ritorno', 'Nera', null, 'tube_only', [
                ['6SCV', '6mm'], ['10SCV', '10mm'], ['12SCV', '12mm'],
            ], 'Solo per uso con liquidi.'))
            ->merge($this->expand($cat, 'Valvola di non ritorno', 'Bianca', null, 'tube_only', [
                ['NC2718', '1/4'],
            ], 'Pressione di apertura 5psi. Solo per uso con liquidi.'))
            ->merge($this->expand($cat, 'Valvola di non ritorno doppia', 'A T', null, 'tube_only', [
                ['15DCV', '15mm'],
            ], 'Solo per uso con liquidi. Può essere usata per uso domestico con acqua calda e fredda.'))
            ->merge($this->expand($cat, 'Valvola di non ritorno doppia', 'In linea', null, 'tube_only', [
                ['15DCSV', '15mm'],
            ], 'Solo per uso con liquidi.'))
            ->merge($this->expand($cat, 'Valvola di intercettazione', null, null, 'tube_only', [
                ['15SV', '15mm'],
            ], 'Solo per uso con liquidi.'))
            ->merge($this->expand($cat, 'Valvola di intercettazione', 'A T', null, 'tube_only', [
                ['1/2ISV', '1/2"'],
            ], 'Solo per uso con liquidi.'))
            ->all();
    }

    private function tubiLldpe(): array
    {
        $cat = self::CAT_TUBI_LLDPE;
        $type = 'Tubo LLDPE (rotolo)';

        $rows = [
            ['PE-08-BI-0500F-', '1/4" (6,35mm)', '1/6" (4,32mm)', '500 FT (152,4 m)', '1.00" (25mm)'],
            ['PE-08-BI-1000F-', '1/4" (6,35mm)', '1/6" (4,32mm)', '1000 FT (304,8 m)', '1.00" (25mm)'],
            ['PE-10-CI-0500F-', '5/16" (8mm)', '3/16" (4,75mm)', '500 FT (152,4 m)', '1.13" (30mm)'],
            ['PE-12-EI-0500F-', '3/8" (9,52mm)', '1/4" (6,35mm)', '500 FT (152,4 m)', '1.25" (32mm)'],
            ['PE-16-GI-0250F-', '1/2" (12,70mm)', '3/8" (9,52mm)', '250 FT (76,2 m)', '2.50" (63mm)'],
            ['PE-04025-0100M-', '4mm', '2.5mm', '100 m', '25mm'],
            ['PE-0604-0100M-', '6mm', '4mm', '100 m', '25mm'],
            ['PE-0806-0100M-', '8mm', '6mm', '100 m', '30mm'],
            ['PE-1007-100M-', '10mm', '7mm', '100 m', '32mm'],
            ['PE-1209-100M-', '12mm', '9mm', '100 m', '63mm'],
            ['PE-15115-0100M-', '15mm', '11.5mm', '100 m', '100mm'],
        ];

        $colorNote = 'Colori standard: N-Naturale, B-Blu, E-Nero, R-Rosso, W-Bianco (codice suffisso). Colori speciali su richiesta: Arancio (O), Verde (G), Giallo (Y). Il codice va completato col suffisso colore (es. -N). Diametro 15mm solo in blu/rosso/verde; diametro 8mm solo in neutro/blu/nero/rosso/bianco.';

        return collect($rows)->map(fn ($r) => [
            'code' => $r[0],
            'category' => $cat,
            'type' => $type,
            'variant' => null,
            'tube_diameter' => $r[1],
            'tube_diameter_2' => $r[2],
            'thread_size' => null,
            'thread_type' => null,
            'barb_diameter' => null,
            'notes' => "Lunghezza rotolo: {$r[3]}. Raggio minimo di curvatura: {$r[4]}. {$colorNote}",
        ])->all();
    }

    private function accessori(): array
    {
        $cat = self::CAT_ACCESSORI;

        return collect()
            ->merge($this->expand($cat, 'Copricolletto', null, null, 'tube_only', [
                ['PM1904S', '5/32'], ['PI1906S', '3/16'], ['PI1908S', '1/4'], ['PM1908S', '5/16'], ['PI1912S', '3/8'], ['PI1916S', '1/2'],
                ['PM1904E', '4'], ['PM1905E', '5'], ['PM1906E', '6'], ['PM1908E', '8'], ['PM1910E', '10'],
                ['PM1912E', '12'], ['PM1915E', '15'], ['PM1918E', '18'], ['PM1922E', '22'],
            ], 'Colori disponibili (suffisso): E-Nero, Y-Giallo, B-Blu, R-Rosso, S-Grigio, G-Verde. Diametri 15/18/22mm solo in nero, bianco, rosso o blu.'))
            ->merge($this->expand($cat, 'Curva piegatubo', null, null, 'tube_only', [
                ['PM2608S', '8mm / 5/16'], ['PM2610S', '10mm / 3/8'], ['PM2612S', '12mm / 1/2'],
            ], 'Adatto al supporto del tubo per evitare attorcigliamenti.'))
            ->merge($this->expand($cat, 'Inserto tubo', null, null, 'tube_tube', [
                ['TSI250S', '3/8', '1/4'], ['TSI312S', '3/8', '5/16'], ['TSI375S', '1/2', '3/8'],
            ], 'Tubo Ø - tubo Ø interno.'))
            ->merge($this->expand($cat, 'Inserto tubo', null, null, 'tube_tube', [
                ['TSM10N', '10', '7'], ['TSM1209S', '12', '9'], ['TSM15N', '15', '11.5'],
            ], 'Tubo Ø - tubo Ø interno.'))
            ->merge($this->expand($cat, 'Tappo', null, null, 'barb_only', [
                ['PP0808W', '1/4'], ['PPM0808W', '5/16'], ['PP0812W', '3/8'], ['PP0816W', '1/2'],
            ], 'Bianco (polipropilene).'))
            ->merge($this->expand($cat, 'Adattatore', null, null, 'tube_only', [
                ['NC688', 'F3/4" - F1/4"'],
            ]))
            ->merge($this->expand($cat, 'Molla antistrozzamento', null, null, 'tube_only', [
                ['NC2447', '3/8"'], ['NC2448', '1/2"'],
            ], 'Compatibili con i raccordi da 3/8" e 1/2". Evitano l\'insorgere di problemi causati da un eccessivo carico laterale sul tubo controllando il raggio di curvatura.'))
            ->merge($this->expand($cat, 'Set chiavi', null, null, 'code_only', [
                ['ICLT/2', null, null, 'Dimensione 3/16" - 1/2".'],
            ]))
            ->merge($this->expand($cat, 'Pinza tagliatubi', null, null, 'code_only', [
                ['JG-TS', null, null, 'Adatto per tubi fino a Ø 22mm.'],
            ]))
            ->merge($this->expand($cat, 'Clip blocca pinzetta', null, null, 'tube_only', [
                ['PIC1808R', '1/4'], ['PMC1808R', '5/16'], ['PIC1812R', '3/8'], ['PIC1816R', '1/2'], ['PMC1815R', '15mm'],
            ], 'Blocca la pinzetta in posizione, evitando rimozioni accidentali del tubo.'))
            ->merge($this->expand($cat, 'Taglia tubi', null, null, 'code_only', [
                ['TS NIP BLADES', null, null, 'Adatto per tubi fino a 12mm.'],
            ]))
            ->merge($this->expand($cat, 'Attrezzo di smontaggio', 'PolarClean', null, 'code_only', [
                ['NC2654'],
            ]))
            ->merge($this->expand($cat, 'Codolo con clip di sicurezza', 'PolarClean', null, 'tube_only', [
                ['NC2742', '18mm'],
            ]))
            ->merge($this->expand($cat, 'Conchiglia isolante adattatore fusto coassiale', 'PolarClean', null, 'code_only', [
                ['NJG-281'],
            ]))
            ->merge($this->expand($cat, 'Conchiglia isolante gomito coassiale', 'PolarClean', null, 'code_only', [
                ['NJG-280'],
            ]))
            ->all();
    }
}
