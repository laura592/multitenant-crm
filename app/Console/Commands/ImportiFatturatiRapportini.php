<?php

namespace App\Console\Commands;

use App\Models\Material;
use App\Models\ServiceReport;
use App\Models\ServiceReportMaterial;
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
 * Di suo scrive solo il TOTALE. Con --allinea-righe riscrive anche le righe
 * del rapportino prendendole dalla fattura, una per una.
 *
 * Quell'abbinamento per un po' si e' creduto impossibile: il campo annidato
 * `articolo` del FB arriva sempre vuoto, e leggendo quello sembrava che la
 * fattura portasse solo del testo. Il codice invece c'e', piatto accanto
 * alla descrizione -- `id_articolo`, con `quantita` e `prezzo`. Su un
 * campione di 60 righe se ne abbinano 59 (04/11/2026).
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
        {--allinea-righe : Riscrive le righe del rapportino con quelle della fattura}
        {--dry-run    : Mostra cosa cambierebbe senza scrivere}';

    protected $description = 'Legge dai documenti FB quanto e\' stato fatturato per ogni rapportino';

    public function handle(): int
    {
        $tenant = $this->option('tenant')
            ? Tenant::query()->where('slug', $this->option('tenant'))->firstOrFail()
            : Tenant::query()->firstOrFail();

        $dryRun = (bool) $this->option('dry-run');
        $azzera = (bool) $this->option('azzera-righe');
        $allinea = (bool) $this->option('allinea-righe');
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

        // Cinque fatture coprono piu' di un rapportino (04/11/2026).
        // Allinearli tutti e due allo stesso documento vorrebbe dire
        // scrivere le stesse righe due volte e cancellare quelle vere:
        // meglio lasciarli stare e dirlo.
        $condivise = $allinea ? $this->bolleCondivise($tenant) : [];

        $barra = $this->output->createProgressBar($totale);
        $conti = ['letti' => 0, 'scritti' => 0, 'a_zero' => 0, 'non_leggibili' => 0, 'saltati' => 0, 'righe_azzerate' => 0,
            'allineati' => 0, 'righe_aggiunte' => 0, 'righe_corrette' => 0, 'righe_tolte' => 0, 'bolla_condivisa' => 0];
        $zeri = [];
        $scartate = [];
        $diff = [];

        $query->chunkById(self::BLOCCO, function (Collection $rapportini) use ($client, $dryRun, $numero, $azzera, $allinea, $condivise, &$conti, &$zeri, &$scartate, &$diff, $barra) {
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

                if ($allinea && $importo > 0.0 && in_array($idBolla, $condivise, true)) {
                    $conti['bolla_condivisa']++;
                } elseif ($allinea && $importo > 0.0) {
                    $esito = $this->allineaRighe($r, $documento['dettaglio'] ?? [], $dryRun);

                    if ($esito['cambiate'] > 0) {
                        $conti['allineati']++;
                        $conti['righe_aggiunte'] += $esito['aggiunte'];
                        $conti['righe_corrette'] += $esito['corrette'];
                        $conti['righe_tolte'] += $esito['tolte'];
                        $diff[] = [$r->number, mb_substr((string) $r->customer?->company_name, 0, 26),
                            number_format($suo, 2, ',', '.'), number_format($importo, 2, ',', '.'),
                            '+'.$esito['aggiunte'].' ~'.$esito['corrette'].' -'.$esito['tolte']];
                    }

                    foreach ($esito['scartate'] as $s) {
                        $scartate[] = $s;
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
            ['Allineati alla fattura', $conti['allineati']],
            ['Saltati: fattura condivisa con altri rapportini', $conti['bolla_condivisa']],
        ]);

        if ($diff !== []) {
            $this->newLine();
            $this->info('Rapportini riallineati alla fattura:');
            $this->table(
                ['Rapportino', 'Cliente', 'Prima', 'Fattura', 'Righe'],
                array_slice($diff, 0, 60),
            );

            if (count($diff) > 60) {
                $this->line('  ... e altri '.(count($diff) - 60).'.');
            }

            $this->line(sprintf(
                '  Righe: %d aggiunte, %d corrette, %d tolte.',
                $conti['righe_aggiunte'], $conti['righe_corrette'], $conti['righe_tolte'],
            ));
        }

        if ($scartate !== []) {
            $this->newLine();
            $this->warn('Righe della fattura senza un materiale corrispondente nel CRM.');
            $this->line('Qui il totale resta diverso: va creato l\'articolo, oppure la riga va messa a mano.');
            $this->table(['Rapportino', 'Codice', 'Descrizione', 'Importo'], array_slice($scartate, 0, 40));
        }

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

    /**
     * Le bolle che appartengono a piu' di un rapportino.
     *
     * Succede quando una fattura raccoglie piu' interventi: il totale e'
     * di tutti insieme, e non si puo' dare per intero a nessuno dei due.
     *
     * @return array<int, int>
     */
    private function bolleCondivise(Tenant $tenant): array
    {
        $conteggio = [];

        ServiceReport::query()->withoutGlobalScopes()
            ->whereNull('deleted_at')->where('tenant_id', $tenant->id)
            ->whereNotNull('eureka_fatture')
            ->select(['id', 'eureka_fatture'])
            ->chunkById(500, function (Collection $blocco) use (&$conteggio): void {
                foreach ($blocco as $r) {
                    $id = (int) ($r->eureka_fatture[0]['id_bolla'] ?? 0);

                    if ($id > 0) {
                        $conteggio[$id] = ($conteggio[$id] ?? 0) + 1;
                    }
                }
            }, 'id');

        return array_keys(array_filter($conteggio, fn (int $n) => $n > 1));
    }

    /**
     * Riscrive le righe di un rapportino con quelle della fattura.
     *
     * Il rapportino racconta quello che il tecnico ha scritto sulla scheda,
     * prezzato col nostro listino. La fattura racconta quello che l'ufficio
     * ha poi davvero messo in conto -- spesso non e' la stessa cosa: su
     * RT-2026-0994 il tecnico aveva segnato un'ora e la fattura ne porta
     * tre, piu' un decalcificante e quattro guarnizioni che sulla scheda non
     * c'erano. Il foglio che si allega alla fattura deve dire quello che il
     * cliente paga, non quello che avevamo previsto.
     *
     * L'abbinamento e' per codice articolo (`id_articolo` sul FB,
     * `gestionale_code` da noi), uno a uno: due righe dello stesso articolo
     * nella stessa fattura si consumano in ordine. Le righe della fattura
     * che non hanno un materiale corrispondente NON si inventano -- la
     * tabella vuole un material_id -- ma si elencano a fine comando, perche'
     * sono l'unico caso in cui il totale resta diverso.
     *
     * Le righe nostre che la fattura non ha vanno in soft delete: si
     * recuperano, ma smettono di far tornare un totale che nessuno ha pagato.
     *
     * @param  array<int, array<string, mixed>>  $dettaglio
     * @return array{cambiate:int, aggiunte:int, corrette:int, tolte:int, scartate:array<int, array<int, string>>}
     */
    private function allineaRighe(ServiceReport $r, array $dettaglio, bool $dryRun): array
    {
        $esito = ['cambiate' => 0, 'aggiunte' => 0, 'corrette' => 0, 'tolte' => 0, 'scartate' => []];

        // Solo le righe che portano un importo: il FB apre sempre con tre
        // righe di intestazione (cliente, articolo, matricola) a zero, che
        // sono testo di stampa e non voci di spesa.
        $voci = array_values(array_filter($dettaglio, fn ($riga) => (float) ($riga['importo'] ?? 0) !== 0.0));

        if ($voci === []) {
            return $esito;
        }

        $libere = $r->materialsUsed->keyBy(fn (ServiceReportMaterial $m) => $m->getKey());

        foreach ($voci as $voce) {
            $codice = (string) ($voce['id_articolo'] ?? '');
            $materiale = $codice !== ''
                ? Material::withoutGlobalScopes()->where('gestionale_code', $codice)->first()
                : null;

            if (! $materiale) {
                $esito['scartate'][] = [
                    $r->number,
                    $codice.' '.($voce['codice'] ?? ''),
                    mb_substr((string) ($voce['descrizione'] ?? ''), 0, 34),
                    number_format((float) ($voce['importo'] ?? 0), 2, ',', '.').' EUR',
                ];

                continue;
            }

            $quantita = (float) ($voce['quantita'] ?? 0);
            $prezzo = (float) ($voce['prezzo'] ?? 0);
            $importo = round((float) ($voce['importo'] ?? 0), 2);

            $riga = $libere->first(fn (ServiceReportMaterial $m) => $m->material_id === $materiale->getKey());

            if ($riga) {
                $libere->forget($riga->getKey());

                $uguale = (float) $riga->quantity === $quantita
                    && (float) $riga->line_total_snapshot === $importo;

                if ($uguale) {
                    continue;
                }

                $esito['corrette']++;
                $esito['cambiate']++;

                if (! $dryRun) {
                    // saveQuietly: il saving() del modello rifarebbe
                    // l'importo come prezzo x quantita', e su una fattura
                    // scontata non e' quello il numero giusto.
                    $riga->forceFill([
                        'quantity' => $quantita,
                        'unit_cost_snapshot' => $prezzo,
                        'line_total_snapshot' => $importo,
                        'prezzo_stimato' => false,
                    ])->saveQuietly();
                }

                continue;
            }

            $esito['aggiunte']++;
            $esito['cambiate']++;

            if (! $dryRun) {
                $nuova = new ServiceReportMaterial;
                $nuova->forceFill([
                    'service_report_id' => $r->getKey(),
                    'material_id' => $materiale->getKey(),
                    'quantity' => $quantita,
                    'unit_cost_snapshot' => $prezzo,
                    'line_total_snapshot' => $importo,
                    'prezzo_stimato' => false,
                ])->saveQuietly();
            }
        }

        foreach ($libere as $avanzo) {
            $esito['tolte']++;
            $esito['cambiate']++;

            if (! $dryRun) {
                $avanzo->delete();
            }
        }

        return $esito;
    }
}
