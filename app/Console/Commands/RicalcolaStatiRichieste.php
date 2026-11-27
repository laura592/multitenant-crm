<?php

namespace App\Console\Commands;

use App\Models\InformationRequest;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Collection;

/**
 * Rimette sulle richieste lo stato che dicono i documenti collegati.
 *
 * Serve per lo storico. Gli stati "preventivo accettato" e "preventivo
 * rifiutato" esistevano nelle etichette da sempre ma non li scriveva
 * nessuno: il ricalcolo mandava su "chiusa" tutto quello che era deciso, e
 * in produzione il 27/11/2026 c'erano 173 richieste su 330 in quella parola
 * sola, piu' 102 su "gestita". Guardando l'elenco non si capiva se una
 * trattativa l'avevamo vinta o persa, che e' l'unica cosa che si vuole
 * sapere da un archivio di richieste (Laura, 27/11/2026).
 *
 * Due regole che non si toccano:
 *
 * - una richiesta SENZA documenti collegati non si tocca mai. "Chiusa" e'
 *   anche uno stato messo a mano: se non c'e' niente attaccato non sappiamo
 *   perche' e' stata chiusa, e riscriverla sarebbe inventare.
 * - una richiesta chiusa o gestita a mano non si riapre per una bozza. Se
 *   l'unico documento e' un preventivo mai mandato, "In lavorazione"
 *   sarebbe una nostra deduzione, non un fatto.
 *
 * Da qui in avanti ci pensano Quote::booted() e Noleggio::booted() via
 * InformationRequest::syncStatusFromQuotes(): questo comando e' per il
 * pregresso, e per rimettere a posto se qualcosa si disallinea.
 */
class RicalcolaStatiRichieste extends Command
{
    protected $signature = 'richieste:ricalcola-stati
        {--dry-run : Mostra cosa cambierebbe senza scrivere}';

    protected $description = 'Rimette lo stato vero sulle richieste che hanno un preventivo, un\'offerta o un noleggio collegato';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');

        // Le etichette vere stanno in InformationRequestResource, ma un
        // comando non deve dipendere dal pannello per stampare una tabella:
        // senza underscore gli stati si leggono uguale.
        $leggibile = fn (string $stato): string => str_replace('_', ' ', $stato);

        $conti = ['viste' => 0, 'senza_documenti' => 0, 'solo_bozze' => 0, 'gia_a_posto' => 0, 'cambiate' => 0];
        $righe = [];

        /** @var array<string, int> $transizioni */
        $transizioni = [];

        // withoutGlobalScopes: da console il tenant non c'e' e lo scope e'
        // comunque inerte, ma il pregresso va sistemato per tutti i tenant e
        // va detto a voce alta.
        InformationRequest::query()
            ->withoutGlobalScopes()
            ->with('customer:id,company_name')
            ->chunkById(100, function (Collection $richieste) use ($dryRun, $leggibile, &$conti, &$righe, &$transizioni) {
                foreach ($richieste as $richiesta) {
                    /** @var InformationRequest $richiesta */
                    $conti['viste']++;

                    $proposte = $richiesta->proposteCollegate();

                    if ($proposte->isEmpty()) {
                        $conti['senza_documenti']++;

                        continue;
                    }

                    $nuovo = $richiesta->statoDaiDocumenti();
                    $vecchio = (string) $richiesta->status;
                    $aMano = ! in_array($vecchio, InformationRequest::AUTO_STATUSES, true);

                    // Vedi il commento in testa: "gestita"/"chiusa" piu' una
                    // bozza non bastano per riaprire niente.
                    if ($nuovo === null || ($aMano && ! in_array($nuovo, InformationRequest::DOCUMENT_STATUSES, true))) {
                        $conti['solo_bozze']++;

                        continue;
                    }

                    if ($nuovo === $vecchio) {
                        $conti['gia_a_posto']++;

                        continue;
                    }

                    $conti['cambiate']++;
                    $chiave = ($leggibile($vecchio)).'  →  '.($leggibile($nuovo));
                    $transizioni[$chiave] = ($transizioni[$chiave] ?? 0) + 1;

                    $righe[] = [
                        $richiesta->number,
                        mb_substr((string) $richiesta->customer?->company_name, 0, 26),
                        $leggibile($vecchio),
                        $leggibile($nuovo),
                        $proposte
                            ->map(fn (array $p): string => "{$p['tipo']} {$p['numero']}: {$p['esito']}")
                            ->implode(' | '),
                    ];

                    if (! $dryRun) {
                        // saveQuietly no: l'audit trail deve vedere passare
                        // questo giro, cosi' chi guarda una richiesta capisce
                        // perche' lo stato e' cambiato da solo.
                        $richiesta->update(['status' => $nuovo]);
                    }
                }
            });

        $this->newLine();

        if ($righe !== []) {
            $this->table(['Richiesta', 'Cliente', 'Da', 'A', 'Perche\''], $righe);
        }

        if ($transizioni !== []) {
            arsort($transizioni);
            $this->table(
                ['Da  →  A', 'Quante'],
                array_map(fn (string $k, int $n): array => [$k, $n], array_keys($transizioni), array_values($transizioni)),
            );
        }

        $this->table(['', 'Richieste'], [
            ['Viste', $conti['viste']],
            ['Senza documenti collegati (intoccate)', $conti['senza_documenti']],
            ['Solo bozze, stato a mano (intoccate)', $conti['solo_bozze']],
            ['Gia\' a posto', $conti['gia_a_posto']],
            ['Cambiate', $conti['cambiate']],
        ]);

        if ($dryRun) {
            $this->warn('--dry-run: niente e\' stato scritto.');
        }

        return self::SUCCESS;
    }
}
