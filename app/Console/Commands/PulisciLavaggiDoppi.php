<?php

namespace App\Console\Commands;

use App\Models\Lavaggio;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Toglie i lavaggi doppi veri (21/09/2026, visti su "La Strana Coppia"):
 *
 * 1. righe SENZA piano e col testo generico "Generato da rapportino ..."
 *    accanto alle righe per piano dello stesso rapportino: orfane rimaste
 *    quando i piani sono stati divisi per bevanda
 *    (ServiceReport::syncGeneratedLavaggi() non le vedeva). Quelle con una
 *    nota vera restano;
 * 2. stesso cliente, stesso giorno, stesso piano ripetuto: si tiene quella
 *    agganciata al rapportino (o la piu' vecchia), recuperando da quelle
 *    tolte vie lavate, filtro e note che mancassero.
 *
 * Le righe birra / vino / bibite dello stesso rapportino NON sono doppie:
 * ognuna tiene vie e scadenza della sua bevanda. Restano.
 *
 * Di default mostra soltanto: cancella solo con --esegui, dopo conferma.
 */
class PulisciLavaggiDoppi extends Command
{
    protected $signature = 'lavaggi:pulisci-doppi {--esegui : Cancella davvero (senza, mostra soltanto)}';

    protected $description = 'Mostra (e con --esegui toglie) i lavaggi doppi: righe orfane senza piano e stesso giorno+piano ripetuto';

    public function handle(): int
    {
        $orfane = $this->orfane();
        [$daTogliere, $daAggiornare] = $this->ripetute();

        $this->info("Righe senza piano accanto a quelle giuste dello stesso rapportino: {$orfane->count()}");
        $this->info("Righe ripetute stesso cliente + giorno + piano: {$daTogliere->count()}");

        $righe = $orfane->map(fn (Lavaggio $l) => $this->riga($l, 'orfana senza piano'))
            ->merge($daTogliere->map(fn (Lavaggio $l) => $this->riga($l, 'ripetuta')));

        if ($righe->isEmpty()) {
            $this->info('Niente da pulire.');

            return self::SUCCESS;
        }

        $this->table(['Cliente', 'Data', 'Piano', 'Rapportino', 'Note', 'Motivo', 'Id'], $righe->all());

        if (! $this->option('esegui')) {
            $this->warn('Solo anteprima: niente e\' stato cancellato. Rilancia con --esegui per togliere queste righe.');

            return self::SUCCESS;
        }

        if (! $this->confirm("Cancellare {$righe->count()} lavaggi?")) {
            return self::SUCCESS;
        }

        DB::transaction(function () use ($orfane, $daTogliere, $daAggiornare) {
            foreach ($daAggiornare as [$tenuta, $dati]) {
                $tenuta->fill($dati)->save();
            }

            // delete() sul modello: Lavaggio::booted() ricalcola la scadenza
            // del piano e l'audit registra cosa e' stato tolto.
            $orfane->merge($daTogliere)->each->delete();
        });

        $this->info("Fatto: {$righe->count()} lavaggi tolti.");

        return self::SUCCESS;
    }

    /**
     * @return Collection<int, Lavaggio>
     */
    private function orfane(): Collection
    {
        return Lavaggio::query()
            ->with(['customer', 'serviceReport'])
            ->whereNull('maintenance_schedule_id')
            ->whereNotNull('service_report_id')
            // Solo le righe generiche: una nota vera ("5 Vie (Selz)") viene
            // dall'import storico e dice cosa e' stato lavato, anche senza
            // un piano per quella bevanda.
            ->where('descrizione', 'like', 'Generato da rapportino%')
            ->whereExists(fn ($q) => $q->selectRaw('1')
                ->from('lavaggi as altre')
                ->whereColumn('altre.service_report_id', 'lavaggi.service_report_id')
                ->whereNotNull('altre.maintenance_schedule_id'))
            ->get();
    }

    /**
     * @return array{0: Collection<int, Lavaggio>, 1: Collection<int, array{0: Lavaggio, 1: array<string, mixed>}>}
     */
    private function ripetute(): array
    {
        $daTogliere = collect();
        $daAggiornare = collect();

        Lavaggio::query()
            ->with(['customer', 'serviceReport', 'maintenanceSchedule'])
            ->whereNotNull('maintenance_schedule_id')
            ->orderBy('created_at')
            ->get()
            ->groupBy(fn (Lavaggio $l) => $l->customer_id.'|'.$l->data?->toDateString().'|'.$l->maintenance_schedule_id)
            ->filter(fn (Collection $gruppo) => $gruppo->count() > 1)
            ->each(function (Collection $gruppo) use ($daTogliere, $daAggiornare) {
                $conRapportino = $gruppo->whereNotNull('service_report_id');

                // Due rapportini diversi sullo stesso piano nello stesso
                // giorno sono due documenti Eureka: non si tocca niente.
                if ($conRapportino->pluck('service_report_id')->unique()->count() > 1) {
                    return;
                }

                $tenuta = $conRapportino->first() ?? $gruppo->first();
                $altre = $gruppo->reject(fn (Lavaggio $l) => $l->is($tenuta));

                $dati = [];
                if ($tenuta->lines_washed === null && ($vie = $altre->pluck('lines_washed')->filter()->first())) {
                    $dati['lines_washed'] = $vie;
                }
                if (! $tenuta->filtro_sostituito && $altre->contains('filtro_sostituito', true)) {
                    $dati['filtro_sostituito'] = true;
                }
                if (str_starts_with((string) $tenuta->descrizione, 'Generato da rapportino')
                    && ($nota = $altre->pluck('descrizione')->first(fn ($d) => filled($d) && ! str_starts_with($d, 'Generato da rapportino')))) {
                    $dati['descrizione'] = $nota;
                }

                if ($dati !== []) {
                    $daAggiornare->push([$tenuta, $dati]);
                }

                $altre->each(fn (Lavaggio $l) => $daTogliere->push($l));
            });

        return [$daTogliere, $daAggiornare];
    }

    private function riga(Lavaggio $l, string $motivo): array
    {
        return [
            $l->customer?->company_name ?? '—',
            $l->data?->format('d/m/Y'),
            $l->maintenanceSchedule?->beverage_type ?? '—',
            $l->serviceReport?->number ?? '—',
            mb_strimwidth((string) $l->descrizione, 0, 40, '…'),
            $motivo,
            $l->id,
        ];
    }
}
