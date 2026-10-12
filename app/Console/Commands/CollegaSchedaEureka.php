<?php

namespace App\Console\Commands;

use App\Models\ServiceReport;
use App\Models\Tenant;
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

            $candidati = collect($schede)
                ->filter('is_array')
                ->map(fn (array $s) => [
                    'id' => (int) ($s['id_eureka'] ?? $s['id'] ?? 0),
                    'numero' => (int) ($s['numero_doc_t23'] ?? $s['numero'] ?? 0),
                    'data' => $this->data($s['data_documento'] ?? $s['data'] ?? null),
                ])
                ->filter(fn (array $s) => $s['id'] > 0 && ! isset($gia[$s['id']]))
                ->values();

            if ($candidati->count() !== 1) {
                $righe[] = [
                    $rapportino->number,
                    $data->format('d/m/Y'),
                    $matricola,
                    $candidati->isEmpty()
                        ? 'nessuna scheda trovata'
                        : $candidati->count().' candidate: '.$candidati
                            ->map(fn (array $s) => 'n.'.$s['numero'].' del '.$s['data']?->format('d/m/Y'))
                            ->implode(', '),
                ];

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

    private function data(mixed $valore): ?Carbon
    {
        if (! is_string($valore) || trim($valore) === '') {
            return null;
        }

        return rescue(fn () => Carbon::parse($valore)->startOfDay(), null, false);
    }
}
