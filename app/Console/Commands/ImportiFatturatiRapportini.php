<?php

namespace App\Console\Commands;

use App\Models\ServiceReport;
use App\Models\Tenant;
use App\Support\Gestionale\EurekaClient;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Collection;

/**
 * Scrive su ogni rapportino quanto e' stato fatturato davvero.
 *
 * Il PDF "rapportini per fattura" sommava gli importi del CRM, e dove
 * Eureka manda prezzo 0 l'import ci mette il listino: sulla FT 516 di
 * Dersut il CRM diceva 2.737,13 € contro 1.286,76 € di imponibile, e tre
 * disinstallazioni regalate (201,00 €) sembravano incassate.
 *
 * L'importo vero sta nel documento FB. `/show/q/sl_fattura` restituisce,
 * accanto alla fattura, l'`id_bolla`: quello e' il FB, e `GET /b10/{id}` lo
 * legge per intero. Gli FT invece rispondono 401 "Tipo documento non
 * consentito", quindi la fattura in se' non si apre -- ma non serve, perche'
 * ogni FB appartiene a un rapportino solo.
 *
 * Si somma il TOTALE del documento e non si toccano le righe: riga per riga
 * l'abbinamento non regge, perche' sulle voci di intervento il FB porta solo
 * il testo e non il codice articolo (su 16 righe se ne riconoscono 2).
 *
 * Comando e non parte del sync: sono migliaia di chiamate a Eureka, e il
 * giro notturno non deve allungarsi di mezz'ora per un dato che serve
 * quando si stampa una fattura.
 */
class ImportiFatturatiRapportini extends Command
{
    private const BLOCCO = 100;

    protected $signature = 'eureka:importi-fatturati
        {--tenant=    : Slug tenant (default: tenant master)}
        {--fattura=   : Solo i rapportini di questo numero di fattura}
        {--tutti      : Rilegge anche quelli che un importo ce l\'hanno gia\'}
        {--azzera-righe : Dove la fattura vale zero, porta a zero anche le righe del rapportino}
        {--dry-run    : Mostra cosa cambierebbe senza scrivere}';

    protected $description = 'Legge dai documenti FB quanto e\' stato fatturato per ogni rapportino';

    public function handle(): int
    {
        $tenant = $this->option('tenant')
            ? Tenant::query()->where('slug', $this->option('tenant'))->firstOrFail()
            : Tenant::query()->firstOrFail();

        $dryRun = (bool) $this->option('dry-run');
        $azzera = (bool) $this->option('azzera-righe');
        $client = new EurekaClient($tenant);

        $query = ServiceReport::query()
            ->withoutGlobalScopes()
            ->whereNull('deleted_at')
            ->where('tenant_id', $tenant->id)
            ->whereNotNull('eureka_fatture')
            ->when(! $this->option('tutti'), fn ($q) => $q->whereNull('eureka_importo_letto_il'))
            ->with(['materialsUsed', 'customer:id,company_name']);

        if ($numero = $this->option('fattura')) {
            // Il numero sta dentro il JSON: si filtra in PHP, perche' una
            // LIKE sul JSON prenderebbe anche il 516 dentro il 5160.
            $query->whereNotNull('eureka_fatture');
        }

        $totale = (clone $query)->count();

        if ($totale === 0) {
            $this->info('Niente da leggere.');

            return self::SUCCESS;
        }

        $barra = $this->output->createProgressBar($totale);
        $conti = ['letti' => 0, 'scritti' => 0, 'a_zero' => 0, 'non_leggibili' => 0, 'saltati' => 0, 'righe_azzerate' => 0];
        $zeri = [];

        $query->chunkById(self::BLOCCO, function (Collection $rapportini) use ($client, $dryRun, $numero, &$conti, &$zeri, $barra) {
            foreach ($rapportini as $r) {
                $barra->advance();

                $f = $r->eureka_fatture[0] ?? [];

                if ($numero !== null && (int) ($f['numero_fattura'] ?? 0) !== (int) $numero) {
                    $conti['saltati']++;

                    continue;
                }

                $idBolla = (int) ($f['id_bolla'] ?? 0);
                $documento = $idBolla ? $client->documentoB10($idBolla) : null;

                if (! $documento) {
                    $conti['non_leggibili']++;

                    continue;
                }

                $conti['letti']++;

                $importo = 0.0;
                foreach ($documento['dettaglio'] ?? [] as $riga) {
                    $importo += (float) ($riga['importo'] ?? 0);
                }

                $importo = round($importo, 2);
                $suo = (float) $r->materialsUsed->sum('line_total_snapshot');

                if ($importo <= 0.0 && $suo > 0.0) {
                    $conti['a_zero']++;
                    $zeri[] = [$r->number, $r->customer?->company_name, $suo];

                    // Quando il documento vale zero, zero valgono tutte le
                    // sue righe: qui non serve abbinarle una per una (e non
                    // si potrebbe, perche' sulle voci di intervento il FB
                    // porta solo il testo e non il codice articolo). Il
                    // rapportino smette di mostrare un prezzo che nessuno ha
                    // pagato, invece di dirlo solo in un riquadro a parte
                    // (Laura, 04/11/2026).
                    //
                    // Non si perde niente di irrecuperabile: quel numero
                    // veniva dal listino dell'articolo, ed e' sempre li'.
                    if ($azzera && ! $dryRun) {
                        foreach ($r->materialsUsed as $riga) {
                            $riga->forceFill([
                                'unit_cost_snapshot' => 0,
                                'line_total_snapshot' => 0,
                                // Zero e' quello che Eureka ha fatturato
                                // davvero: non e' piu' una stima nostra.
                                'prezzo_stimato' => false,
                            ])->saveQuietly();
                        }

                        $conti['righe_azzerate'] += $r->materialsUsed->count();
                    }
                }

                if ($dryRun) {
                    continue;
                }

                $r->forceFill([
                    'eureka_importo_fatturato' => $importo,
                    'eureka_importo_letto_il' => now(),
                ])->saveQuietly();

                $conti['scritti']++;
            }
        }, 'id');

        $barra->finish();
        $this->newLine(2);

        $this->table(['', 'Rapportini'], [
            ['Letti da Eureka', $conti['letti']],
            ['Scritti', $conti['scritti']],
            ['Documento non leggibile', $conti['non_leggibili']],
            ['Saltati (altra fattura)', $conti['saltati']],
            ['FATTURATI A ZERO ma con un importo nel CRM', $conti['a_zero']],
            ['Righe portate a zero', $conti['righe_azzerate']],
        ]);

        if ($zeri !== []) {
            $this->newLine();
            $this->warn('Fatturati a zero — il CRM mostrava un importo che nessuno ha incassato:');
            $this->table(['Rapportino', 'Cliente', 'Importo nel CRM'], array_map(
                fn (array $z) => [$z[0], mb_substr((string) $z[1], 0, 34), number_format($z[2], 2, ',', '.').' €'],
                $zeri,
            ));
        }

        if ($dryRun) {
            $this->warn('--dry-run: niente e\' stato scritto.');
        }

        return self::SUCCESS;
    }
}
