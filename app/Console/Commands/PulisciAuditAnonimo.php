<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Elimina dall'audit trail le righe senza autore.
 *
 * Sono quelle scritte dagli import e dalle sincronizzazioni prima che
 * LogsAuditTrail smettesse di registrarle (2026-08-31): 16.229 su 16.305,
 * che facevano di activity_log la tabella piu' grossa del database — 13,2 MB
 * su 35,7, piu' di clienti, rapportini e macchine messi insieme.
 *
 * Una riga di audit senza utente non risponde alla domanda per cui l'audit
 * esiste ("chi ha cambiato questo dato"), e intanto sommerge le poche che
 * rispondono. Cosa abbia cambiato la sincronizzazione lo dicono gia' i log
 * dei comandi.
 *
 * Di default non cancella: mostra cosa toglierebbe. Serve --scrivi.
 */
class PulisciAuditAnonimo extends Command
{
    protected $signature = 'audit:pulisci-anonimi
        {--scrivi          : Cancella davvero (senza, produce solo un report)}
        {--prima-di=       : Solo le righe anteriori a questa data (Y-m-d)}';

    protected $description = "Elimina dall'audit trail le righe senza autore, scritte da import e sincronizzazioni";

    public function handle(): int
    {
        $query = DB::table('activity_log')->whereNull('causer_id');

        if ($prima = $this->option('prima-di')) {
            $query->where('created_at', '<', $prima);
        }

        $daTogliere = (clone $query)->count();
        $totale = DB::table('activity_log')->count();
        $restano = $totale - $daTogliere;

        if ($daTogliere === 0) {
            $this->info('Niente da pulire: tutte le righe hanno un autore.');

            return self::SUCCESS;
        }

        $this->newLine();
        $this->line("  Righe totali:      <options=bold>{$totale}</>");
        $this->line("  Senza autore:      <fg=red;options=bold>{$daTogliere}</>");
        $this->line("  Restano:           <fg=green;options=bold>{$restano}</>");
        $this->newLine();

        // Le prime voci per tipo, cosi' si vede cosa si sta togliendo prima
        // di toglierlo.
        $perTipo = (clone $query)
            ->select('subject_type', 'description', DB::raw('COUNT(*) as quante'))
            ->groupBy('subject_type', 'description')
            ->orderByDesc('quante')
            ->limit(8)
            ->get();

        $this->table(
            ['Modello', 'Evento', 'Righe'],
            $perTipo->map(fn ($r) => [
                class_basename($r->subject_type ?? '—'),
                $r->description,
                $r->quante,
            ])->all()
        );

        if (! $this->option('scrivi')) {
            $this->info('Report soltanto. Per cancellare davvero: <options=bold>--scrivi</>');

            return self::SUCCESS;
        }

        // A blocchi: una DELETE su decine di migliaia di righe tiene il lock
        // sulla tabella piu' del necessario.
        $tolte = 0;

        do {
            $blocco = (clone $query)->limit(1000)->pluck('id');

            if ($blocco->isEmpty()) {
                break;
            }

            $tolte += DB::table('activity_log')->whereIn('id', $blocco)->delete();
            $this->output->write('.');
        } while (true);

        $this->newLine(2);
        $this->info("Eliminate {$tolte} righe senza autore. Ne restano {$restano} con autore.");
        $this->line('Lo spazio su disco si recupera con OPTIMIZE TABLE activity_log, se serve.');

        return self::SUCCESS;
    }
}
