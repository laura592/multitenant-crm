<?php

namespace App\Console\Commands;

use App\Models\Material;
use App\Models\ServiceReport;
use App\Models\ServiceReportMaterial;
use App\Models\Tenant;
use App\Support\Gestionale\EurekaClient;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;

/**
 * Le righe dei rapportini gia' inviati, riallineate alla loro scheda Eureka.
 *
 * C'e' un buco, e si e' visto due volte in un'ora il 06/11/2026. Dal momento
 * in cui un rapportino e' su Eureka il CRM lo blocca in modifica
 * (ServiceReport::isSuEureka). L'ufficio quindi corregge DI LA' — un codice
 * articolo sbagliato, un ricambio dimenticato, un prezzo — ed e' la cosa
 * giusta da fare, perche' la scheda e' quella che diventa fattura. Ma di qua
 * non torna niente: `eureka:import-service-reports` salta apposta i
 * rapportini nati nel CRM (ci sono una nota e una brutta esperienza nel
 * codice: un sync aveva riportato tecnico, data e descrizioni di
 * RT-2026-0002..0014 ai valori di ripiego di Eureka).
 *
 * Qui si riallineano SOLO LE RIGHE. Data, tecnico, cliente, macchina,
 * problema e lavoro svolto non si toccano mai: su quelli il CRM resta la
 * fonte, ed e' esattamente la distinzione che mancava.
 *
 * Non va confuso con `eureka:importi-fatturati --allinea-righe`, che fa la
 * stessa cosa ma leggendo la FATTURA: quello e' il controllo definitivo e
 * arriva dopo. Questo serve prima, quando la fattura non c'e' ancora.
 */
class AllineaRapportiniSchede extends Command
{
    protected $signature = 'rapportini:allinea-schede
                            {--tenant=alex : slug del tenant}
                            {--dal= : data intervento da cui partire (default: 30 giorni fa)}
                            {--al= : data intervento fino a cui arrivare (default: oggi)}
                            {--rapportino=* : uno o piu\' numeri RT-..., invece del periodo}
                            {--togli-avanzi : cancella anche le righe che nel CRM ci sono e su Eureka no}
                            {--dry-run : mostra le differenze senza scrivere niente}';

    protected $description = 'Riallinea le righe dei rapportini gia\' su Eureka alla loro scheda lavoro';

    public function handle(): int
    {
        $tenant = Tenant::query()->where('slug', $this->option('tenant'))->firstOrFail();
        $client = new EurekaClient($tenant);
        $dryRun = (bool) $this->option('dry-run');
        $togliAvanzi = (bool) $this->option('togli-avanzi');

        $rapportini = $this->rapportini($tenant);

        if ($rapportini->isEmpty()) {
            $this->info('Nessun rapportino con una scheda Eureka nel periodo.');

            return self::SUCCESS;
        }

        $this->info(($dryRun ? '[PROVA] ' : '').'Rapportini da confrontare: '.$rapportini->count());
        $this->newLine();

        $conti = ['letti' => 0, 'allineati' => 0, 'aggiunte' => 0, 'corrette' => 0, 'tolte' => 0, 'mute' => 0];
        $differenze = [];
        $scartate = [];
        $nonLeggibili = [];

        foreach ($rapportini as $r) {
            $idScheda = (int) ($r->gestionale_scheda_lavoro_id ?: $r->eureka_service_report_id);
            $scheda = $client->schedaLavoro($idScheda);

            if ($scheda === null) {
                // Cancellata su Eureka, oppure Eureka non ha risposto: i due
                // casi si somigliano e nessuno dei due autorizza a toccare
                // le righe. Si segnala e si tira avanti.
                $nonLeggibili[] = [$r->number, $idScheda, mb_substr((string) $r->customer?->company_name, 0, 30)];

                continue;
            }

            $conti['letti']++;
            $esito = $this->allinea($r, $scheda['dettaglio'] ?? [], $dryRun, $togliAvanzi);

            foreach ($esito['scartate'] as $s) {
                $scartate[] = $s;
            }

            if ($esito['cambiate'] === 0) {
                continue;
            }

            $conti['allineati']++;
            $conti['aggiunte'] += $esito['aggiunte'];
            $conti['corrette'] += $esito['corrette'];
            $conti['tolte'] += $esito['tolte'];
            $conti['mute'] += $esito['mute'];

            foreach ($esito['righe'] as $riga) {
                $differenze[] = array_merge([$r->number, mb_substr((string) $r->customer?->company_name, 0, 24)], $riga);
            }
        }

        $this->table(['', 'Quante'], [
            ['Schede lette da Eureka', $conti['letti']],
            ['Rapportini con righe diverse', $conti['allineati']],
            ['Righe aggiunte', $conti['aggiunte']],
            ['Righe corrette', $conti['corrette']],
            ['Righe tolte', $conti['tolte']],
            ['Righe lasciate stare (Eureka non le valorizza)', $conti['mute']],
        ]);

        if ($differenze !== []) {
            $this->newLine();
            $this->info($dryRun ? 'Differenze trovate:' : 'Differenze sistemate:');
            $this->table(['Rapportino', 'Cliente', 'Cosa', 'Articolo', 'Descrizione', 'Prima', 'Dopo'], $differenze);
        }

        if ($scartate !== []) {
            $this->newLine();
            $this->warn('Righe della scheda senza un articolo corrispondente a catalogo: qui il CRM resta indietro.');
            $this->table(['Rapportino', 'Articolo', 'Descrizione', 'Prezzo'], $scartate);
        }

        if ($nonLeggibili !== []) {
            $this->newLine();
            $this->warn('Schede che Eureka non ha restituito: cancellate di la\', o gestionale non raggiungibile.');
            $this->table(['Rapportino', 'Scheda', 'Cliente'], $nonLeggibili);
        }

        if ($dryRun) {
            $this->newLine();
            $this->line('Era una prova: non e\' stato scritto niente. Togli --dry-run per applicare.');
        }

        return self::SUCCESS;
    }

    /** @return Collection<int, ServiceReport> */
    private function rapportini(Tenant $tenant): Collection
    {
        $query = ServiceReport::query()
            ->withoutGlobalScopes()
            ->where('tenant_id', $tenant->getKey())
            ->whereNull('deleted_at')
            ->where(fn ($q) => $q->whereNotNull('gestionale_scheda_lavoro_id')->orWhereNotNull('eureka_service_report_id'))
            ->with(['materialsUsed.material', 'customer']);

        /** @var array<int, string> $numeri */
        $numeri = (array) $this->option('rapportino');

        if ($numeri !== []) {
            return $query->whereIn('number', $numeri)->orderBy('number')->get();
        }

        $dal = $this->option('dal') ?: now()->subDays(30)->toDateString();
        $al = $this->option('al') ?: now()->toDateString();

        return $query->whereBetween('intervention_date', [$dal, $al])->orderBy('number')->get();
    }

    /**
     * Confronta le righe di una scheda con quelle del rapportino.
     *
     * @param  array<int, mixed>  $dettaglio
     * @return array{cambiate: int, aggiunte: int, corrette: int, tolte: int, mute: int, righe: array<int, array<int, string>>, scartate: array<int, array<int, string>>}
     */
    private function allinea(ServiceReport $r, array $dettaglio, bool $dryRun, bool $togliAvanzi): array
    {
        $esito = ['cambiate' => 0, 'aggiunte' => 0, 'corrette' => 0, 'tolte' => 0, 'mute' => 0, 'righe' => [], 'scartate' => []];
        $avanzi = $r->materialsUsed->keyBy(fn (ServiceReportMaterial $m) => $m->getKey());

        foreach ($dettaglio as $voce) {
            if (! is_array($voce)) {
                continue;
            }

            $codice = (string) ($voce['id_articolo'] ?? '');
            $materiale = $codice !== ''
                ? Material::withoutGlobalScopes()->where('gestionale_code', $codice)->first()
                : null;

            if (! $materiale) {
                $esito['scartate'][] = [
                    $r->number,
                    $codice.' '.($voce['codice'] ?? ''),
                    mb_substr((string) ($voce['descrizione'] ?? ''), 0, 34),
                    number_format((float) ($voce['prezzo'] ?? 0), 2, ',', '.').' EUR',
                ];

                continue;
            }

            $quantita = (float) ($voce['quantita'] ?? 0);
            $prezzo = (float) ($voce['prezzo'] ?? 0);
            $etichetta = mb_substr($materiale->display_label, 0, 30);

            $riga = $avanzi->first(fn (ServiceReportMaterial $m) => $m->material_id === $materiale->getKey());

            if (! $riga) {
                $esito['aggiunte']++;
                $esito['cambiate']++;
                $esito['righe'][] = ['aggiunta', $codice, $etichetta, '—', $this->mostra($quantita, $prezzo)];

                if (! $dryRun) {
                    $nuova = new ServiceReportMaterial;
                    $nuova->forceFill([
                        'service_report_id' => $r->getKey(),
                        'material_id' => $materiale->getKey(),
                        'quantity' => $quantita,
                        // Prezzo a zero sulla scheda vuol dire "lo mette la
                        // fattura" (e' cosi' per tutti i lavaggi): si lascia
                        // vuoto e ci pensa l'hook del modello a metterci il
                        // listino, segnando la riga come stimata.
                        'unit_cost_snapshot' => $prezzo > 0 ? $prezzo : null,
                        'prezzo_stimato' => ! ($prezzo > 0),
                    ])->save();
                }

                continue;
            }

            $avanzi->forget($riga->getKey());

            $primaQ = (float) $riga->quantity;
            $primaP = (float) $riga->unit_cost_snapshot;

            // Il prezzo a zero di Eureka non e' un prezzo: sulle schede i
            // lavaggi valgono sempre zero e il numero vero lo mette la
            // fattura. Sovrascrivere col suo zero cancellerebbe il listino
            // che il CRM ha gia' messo, quindi si allinea solo la quantita'.
            $prezzoDaEureka = $prezzo > 0;
            $cambiaQ = abs($primaQ - $quantita) >= 0.001;
            $cambiaP = $prezzoDaEureka && abs($primaP - $prezzo) >= 0.01;

            if (! $cambiaQ && ! $cambiaP) {
                if (! $prezzoDaEureka && $primaP > 0) {
                    $esito['mute']++;
                }

                continue;
            }

            $esito['corrette']++;
            $esito['cambiate']++;
            $esito['righe'][] = [
                'corretta', $codice, $etichetta,
                $this->mostra($primaQ, $primaP),
                $this->mostra($quantita, $prezzoDaEureka ? $prezzo : $primaP),
            ];

            if (! $dryRun) {
                $riga->quantity = $quantita;

                if ($prezzoDaEureka) {
                    $riga->unit_cost_snapshot = $prezzo;
                    $riga->prezzo_stimato = false;
                }

                // save() e non saveQuietly(): qui l'importo di riga E'
                // prezzo x quantita', e l'hook del modello lo rifa' da solo.
                $riga->save();
            }
        }

        // Quello che resta nel CRM e su Eureka non c'e'. Non si cancella
        // senza che qualcuno lo chieda: puo' essere una riga che l'ufficio
        // ha tolto dalla scheda, ma anche una scheda incompleta. Si elenca
        // e basta, a meno di --togli-avanzi.
        foreach ($avanzi as $avanzo) {
            $esito['cambiate']++;
            $esito['righe'][] = [
                $togliAvanzi ? 'tolta' : 'solo nel CRM',
                (string) ($avanzo->material?->gestionale_code ?? '—'),
                mb_substr((string) $avanzo->material?->display_label, 0, 30),
                $this->mostra((float) $avanzo->quantity, (float) $avanzo->unit_cost_snapshot),
                $togliAvanzi ? '—' : 'lasciata',
            ];

            if ($togliAvanzi) {
                $esito['tolte']++;

                if (! $dryRun) {
                    $avanzo->delete();
                }
            }
        }

        return $esito;
    }

    private function mostra(float $quantita, float $prezzo): string
    {
        return rtrim(rtrim(number_format($quantita, 2, ',', ''), '0'), ',').' x '.number_format($prezzo, 2, ',', '.');
    }
}
