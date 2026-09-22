<?php

namespace App\Console\Commands;

use App\Models\MachineUnit;
use App\Models\MachineUnitPlacement;
use App\Models\Tenant;
use App\Support\Gestionale\EurekaClient;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * Le posizioni create dall'import di Eureka (nota "Importata da Eureka ...
 * bolla n. X") con la bolla e la data di un ALTRO cliente: la matricola
 * B20359 risultava al Majer S. Agostino "dal 14/01/2026, bolla n. 247",
 * che e' la consegna alla Grigliata; la sua era la n. 189 del 31/01/2024
 * (22/09/2026).
 *
 * Per ogni posizione importata si rilegge su Eureka la bolla di quel
 * cliente per quella matricola, e se data o numero non tornano si
 * correggono. Le posizioni registrate a mano ("Sposta") non si toccano.
 * Solo letture verso Eureka.
 */
class CorreggiDateMacchineImportate extends Command
{
    protected $signature = 'macchine:correggi-date-importate
                            {--tenant=alex : slug del tenant}
                            {--dry-run : mostra cosa cambierebbe, senza scrivere}';

    protected $description = 'Rimette data e numero della bolla giusti sulle posizioni delle macchine importate da Eureka';

    public function handle(): int
    {
        $tenant = Tenant::query()->where('slug', $this->option('tenant'))->firstOrFail();

        $posizioni = MachineUnitPlacement::query()
            ->withoutGlobalScopes()
            ->where('tenant_id', $tenant->id)
            ->where('notes', 'like', 'Importata da Eureka%')
            ->whereHas('customer', fn ($q) => $q->withoutGlobalScopes()->whereNotNull('gestionale_code'))
            ->with(['customer' => fn ($q) => $q->withoutGlobalScopes(), 'machineUnit' => fn ($q) => $q->withoutGlobalScopes()])
            ->get()
            ->filter(fn (MachineUnitPlacement $p) => $p->machineUnit && ! $p->machineUnit->trashed())
            // Le matricole finte ("000000") sono presso decine di clienti con
            // bolle diverse: confrontarle non vuol dire niente.
            ->reject(fn (MachineUnitPlacement $p) => (bool) preg_match('/^[-\s]*0+$/', (string) $p->machineUnit->serial_number));

        if ($posizioni->isEmpty()) {
            $this->info('Nessuna posizione importata da controllare.');

            return self::SUCCESS;
        }

        $clienti = $posizioni->pluck('customer')->unique('id');
        $risposte = (new EurekaClient($tenant))->pooledGet(
            '/show/q/art_installati',
            $clienti->mapWithKeys(fn ($c) => [$c->id => ['q' => (int) $c->gestionale_code]])->all(),
        );

        $modifiche = [];
        $saltate = [];

        foreach ($posizioni as $p) {
            $chiave = MachineUnit::chiaveMatricola($p->machineUnit->serial_number);
            $riga = collect((array) ($risposte[$p->customer_id] ?? []))
                ->first(fn ($r) => is_array($r) && MachineUnit::chiaveMatricola((string) ($r['matricola'] ?? '')) === $chiave);

            if (! $riga || empty($riga['data_documento'])) {
                continue;
            }

            $data = Carbon::parse($riga['data_documento'])->startOfDay();
            $bolla = (int) ($riga['numero_doc_t23'] ?? 0);
            $notaGiusta = $bolla > 0
                ? (preg_match('/bolla n\. \d+/', (string) $p->notes)
                    ? preg_replace('/bolla n\. \d+/', "bolla n. {$bolla}", (string) $p->notes)
                    : rtrim((string) $p->notes).", bolla n. {$bolla}")
                : $p->notes;

            if ($p->placed_at->isSameDay($data) && $notaGiusta === $p->notes) {
                continue;
            }

            // Una posizione chiusa prima della data vera della sua bolla non
            // si aggiusta spostando l'inizio: lo storico va guardato a mano.
            if ($p->removed_at && $p->removed_at->lt($data)) {
                $saltate[] = [$p->machineUnit->serial_number, $p->customer->company_name, $p->placed_at->format('d/m/Y'), $data->format('d/m/Y'), 'chiusa il '.$p->removed_at->format('d/m/Y')];

                continue;
            }

            $modifiche[] = [$p, $data, $notaGiusta, $bolla];
        }

        if ($saltate !== []) {
            $this->warn('Da guardare a mano (la posizione risulta chiusa prima della data della sua bolla):');
            $this->table(['Matricola', 'Cliente', 'Dal ora', 'Bolla del', 'Perché'], $saltate);
        }

        if ($modifiche === []) {
            $this->info('Le date delle posizioni importate sono giuste.');

            return self::SUCCESS;
        }

        $this->table(['Matricola', 'Cliente', 'Dal ora', 'Dal giusto', 'Bolla'], array_map(fn ($m) => [
            $m[0]->machineUnit->serial_number,
            $m[0]->customer->company_name,
            $m[0]->placed_at->format('d/m/Y'),
            $m[1]->format('d/m/Y'),
            $m[3] ?: '—',
        ], $modifiche));

        if ($this->option('dry-run')) {
            $this->warn('--dry-run: '.count($modifiche).' posizioni da correggere, niente e\' stato scritto.');

            return self::SUCCESS;
        }

        if (! $this->confirm('Correggo '.count($modifiche).' posizioni?', false)) {
            $this->line('Annullato: nessuna modifica.');

            return self::SUCCESS;
        }

        foreach ($modifiche as [$p, $data, $nota]) {
            $p->update(['placed_at' => $data, 'notes' => $nota]);
        }

        $this->info('Corrette: '.count($modifiche).'.');

        return self::SUCCESS;
    }
}
