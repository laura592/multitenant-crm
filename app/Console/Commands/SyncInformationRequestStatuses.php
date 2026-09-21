<?php

namespace App\Console\Commands;

use App\Filament\Resources\InformationRequestResource;
use App\Models\InformationRequest;
use Illuminate\Console\Command;

/**
 * Allinea una volta le richieste informazioni gia' esistenti allo stato dei
 * loro preventivi. Da qui in avanti lo fa da solo il preventivo (hook in
 * Quote::booted), questo serve solo per lo storico.
 *
 * Senza --apply mostra soltanto cosa cambierebbe: i dati di produzione si
 * toccano solo dopo aver visto l'elenco.
 */
class SyncInformationRequestStatuses extends Command
{
    protected $signature = 'information-requests:sync-quote-status {--apply : Applica davvero le modifiche}';

    protected $description = 'Allinea lo stato delle richieste informazioni ai preventivi collegati (anteprima senza --apply)';

    public function handle(): int
    {
        $labels = InformationRequestResource::statusLabels();
        $rows = [];

        InformationRequest::withoutGlobalScope('tenant')
            ->whereIn('status', InformationRequest::AUTO_STATUSES)
            ->whereHas('quotes', fn ($query) => $query->withoutGlobalScope('tenant'))
            ->with('tenant')
            ->orderBy('created_at')
            ->each(function (InformationRequest $request) use (&$rows, $labels) {
                $new = $request->statusFromQuotes();

                if ($new === null || $new === $request->status) {
                    return;
                }

                $rows[] = [$request->tenant?->name, $request->number, $labels[$request->status] ?? $request->status, $labels[$new] ?? $new];

                if ($this->option('apply')) {
                    $request->update(['status' => $new]);
                }
            });

        if ($rows === []) {
            $this->info('Nessuna richiesta da allineare.');

            return self::SUCCESS;
        }

        $this->table(['Tenant', 'Richiesta', 'Stato attuale', 'Nuovo stato'], $rows);

        $this->option('apply')
            ? $this->info(count($rows).' richieste aggiornate.')
            : $this->warn(count($rows).' richieste da aggiornare. Rilancia con --apply per applicare.');

        return self::SUCCESS;
    }
}
