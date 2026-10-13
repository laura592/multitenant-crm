<?php

namespace App\Console\Commands;

use App\Models\ServiceReport;
use App\Models\Tenant;
use App\Support\Gestionale\DocumentiMacchina;
use App\Support\EurekaClient;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * Cerca su Eureka la scheda di un rapportino nato nel CRM, e la collega.
 *
 * I rapportini scritti a mano si collegano alla loro scheda Eureka quando
 * l'import notturno la ripesca, e l'import cerca per CLIENTE: se il cliente
 * del CRM non ha un codice gestionale, quel rapportino resta scollegato per
 * sempre. Nell'elenco dice "In gestionale" (stato messo a mano) e "Non
 * inviato" (nessuna scheda): due informazioni che si contraddicono, ed e' da
 * li' che e' partita la richiesta (12/10/2026).
 *
 * Qui si cerca per MATRICOLA (GET /schedelavoro/?matricola=, ricerca LIKE),
 * che il cliente senza codice non la ferma, in una finestra di giorni
 * attorno alla data dell'intervento. Si collega solo quando il candidato e'
 * uno: con zero o piu' di uno si stampa cosa si e' trovato e decide una
 * persona.
 *
 * Non scrive niente su Eureka.
 */
class CollegaSchedaEureka extends Command
{
    protected $signature = 'rapportini:collega-scheda
                            {--tenant=alex : slug del tenant}
                            {--numero=* : uno o piu\' numeri di rapportino; vuoto = tutti quelli scollegati}
                            {--giorni=10 : quanti giorni prima e dopo la data dell\'intervento cercare}
                            {--dry-run : mostra cosa collegherebbe senza scrivere}';

    protected $description = 'Cerca su Eureka la scheda di un rapportino (per matricola) e la collega al rapportino del CRM';

    public function handle(EurekaClient $eureka): int
    {
        $tenant = Tenant::query()->where('slug', $this->option('tenant'))->firstOrFail();
        $giorni = max(0, (int) $this->option('giorni'));

        $rapportini = ServiceReport::query()
            ->withoutGlobalScopes()
            ->where('tenant_id', $tenant->id)
            ->whereNull('deleted_at')
            ->whereNull('eureka_service_report_id')
            ->whereNotNull('machine_serial_number')
            ->where('machine_serial_number', '!=', '')
            ->when($this->option('numero'), fn ($q, $numeri) => $q->whereIn('number', $numeri))
            ->with('customer')
            ->orderBy('intervention_date')
            ->get();

        if ($rapportini->isEmpty()) {
            $this->info('Nessun rapportino scollegato con una matricola da cercare.');

            return self::SUCCESS;
        }

        // Le schede gia' in mano al CRM: una scheda collegata a un altro
        // rapportino non e' un candidato.
        $gia = ServiceReport::query()
            ->withoutGlobalScopes()
            ->where('tenant_id', $tenant->id)
            ->whereNotNull('eureka_service_report_id')
            ->pluck('number', 'eureka_service_report_id');

        $collegati = 0;
        $righe = [];

        foreach ($rapportini as $rapportino) {
            $matricola = trim((string) $rapportino->machine_serial_number);
            $data = $rapportino->intervention_date;

            // Le matricole inventate dal CRM non si cercano: "0000" dentro
            // una ricerca LIKE prende qualunque numero e tirava su tredici
            // schede di clienti diversi, proponendo doppioni inesistenti
            // (visto il 13/10/2026 su RT-2026-0914).
            if (! DocumentiMacchina::cercabile($matricola)) {
                $righe[] = [$rapportino->number, $data->format('d/m/Y'), $matricola, 'matricola non cercabile su Eureka'];

                continue;
            }

            try {
                $schede = $eureka->searchServiceReports([
                    'matricola' => $matricola,
                    'data_da' => $data->copy()->subDays($giorni)->toDateString().'T00:00:00',
                    'data_a' => $data->copy()->addDays($giorni)->toDateString().'T23:59:59',
                ]);
            } catch (\Throwable $e) {
                $this->error('Eureka non risponde: '.$e->getMessage());
                $this->line('Mi fermo qui: '.$collegati.' rapportini collegati finora.');

                return self::FAILURE;
            }

            $trovate = collect($schede)
                ->filter(fn ($s) => is_array($s))
                ->map(fn (array $s) => [
                    'id' => (int) ($s['id_eureka'] ?? $s['id'] ?? 0),
                    'numero' => (int) ($s['numero_doc_t23'] ?? $s['numero'] ?? 0),
                    'data' => $this->data($s['data_documento'] ?? $s['data'] ?? null),
                ])
                ->filter(fn (array $s) => $s['id'] > 0)
                ->values();

            // Una scheda che un altro rapportino tiene gia' non e' "niente
            // trovato": e' la gemella, e quei due rapportini sono lo stesso
            // intervento scritto due volte. Dirlo cambia la risposta — il
            // caso di RT-2026-0775, dove "nessuna scheda trovata" nascondeva
            // che SL-728/2026 era di RT-2026-0821.
            $occupate = $trovate->filter(fn (array $s) => isset($gia[$s['id']]));
            $candidati = $trovate->reject(fn (array $s) => isset($gia[$s['id']]))->values();

            if ($candidati->count() !== 1) {
                $righe[] = [
                    $rapportino->number,
                    $data->format('d/m/Y'),
                    $matricola,
                    match (true) {
                        // Una sola scheda occupata: e' la gemella. Piu' di
                        // una vuol dire che la ricerca ha preso troppo, e
                        // allora non si propone niente.
                        $occupate->count() === 1 => 'doppione: la scheda e\' di '.$gia[$occupate->first()['id']],
                        $occupate->count() > 1 => $occupate->count().' schede diverse la nominano: da guardare a mano',
                        $candidati->isEmpty() => 'nessuna scheda trovata',
                        default => $candidati->count().' candidate: '.$candidati
                            ->map(fn (array $s) => 'n.'.$s['numero'].' del '.$s['data']?->format('d/m/Y'))
                            ->implode(', '),
                    },
                ];

                if ($occupate->count() === 1 && ! $this->option('dry-run')) {
                    $this->proponiDoppione($rapportino, (string) $gia[$occupate->first()['id']]);
                }

                continue;
            }

            $scheda = $candidati->first();
            $numeroDoc = $scheda['numero'] > 0
                ? 'SL-'.$scheda['numero'].'/'.($scheda['data']?->year ?: $data->year)
                : 'SL-EK-'.$scheda['id'];

            $righe[] = [$rapportino->number, $data->format('d/m/Y'), $matricola, 'collegato a '.$numeroDoc];

            if (! $this->option('dry-run')) {
                // forceFill + saveQuietly: non e' una modifica del
                // rapportino ma il ritrovamento del suo documento, e i
                // rapportini su Eureka sono comunque bloccati (isLocked).
                $rapportino->forceFill([
                    'eureka_service_report_id' => $scheda['id'],
                    'gestionale_number' => $numeroDoc,
                    'gestionale_document_date' => $scheda['data']?->toDateString(),
                ])->saveQuietly();

                $gia[$scheda['id']] = $rapportino->number;
            }

            $collegati++;
        }

        $this->table(['Rapportino', 'Data', 'Matricola', 'Esito'], $righe);

        $this->line($this->option('dry-run')
            ? '--dry-run: '.$collegati.' da collegare, niente scritto.'
            : $collegati.' rapportini collegati alla loro scheda Eureka.');

        return self::SUCCESS;
    }

    /**
     * Segna il doppione invece di unirlo: unire cancella un rapportino, e
     * quella e' una decisione di una persona. La proposta compare nella
     * schermata di confronto, dove si conferma con "Sono lo stesso
     * intervento" (vedi ServiceReport::confermaDuplicato).
     */
    private function proponiDoppione(ServiceReport $rapportino, string $numeroGemella): void
    {
        $gemella = ServiceReport::query()->withoutGlobalScopes()
            ->where('tenant_id', $rapportino->tenant_id)
            ->where('number', $numeroGemella)
            ->first();

        if (! $gemella || $rapportino->duplicato_suggerito_id !== null) {
            return;
        }

        $rapportino->forceFill([
            'duplicato_suggerito_id' => $gemella->id,
            'duplicato_suggerito_motivo' => 'stessa matricola e stessa scheda Eureka ('.$gemella->gestionale_number.')',
        ])->saveQuietly();

        $this->line('   proposto come doppione di '.$gemella->number.' ('.$gemella->gestionale_number.')');
    }

    private function data(mixed $valore): ?Carbon
    {
        if (! is_string($valore) || trim($valore) === '') {
            return null;
        }

        return rescue(fn () => Carbon::parse($valore)->startOfDay(), null, false);
    }
}
