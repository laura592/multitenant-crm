<?php

namespace App\Console\Commands;

use App\Models\Customer;
use App\Models\MachineUnit;
use App\Models\Material;
use App\Models\Tenant;
use App\Support\DisplayName;
use App\Support\Gestionale\EurekaClient;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;

/**
 * Rimette a ogni macchina l'articolo che dice la bolla, non quello che dice
 * la scheda di lavoro (28/09/2026).
 *
 * Il CRM prende il modello di una macchina da `sl_articolo` della scheda: e'
 * l'unico punto in cui Eureka gli passa matricola e articolo insieme. Ma le
 * schede si contraddicono fra loro — sulle due macchine di Albergo Stellamare
 * (34000003512 52 e 53) tre schede dicono A300 e due A600FM — e il CRM si
 * tiene la prima che ha visto.
 *
 * La bolla invece e' una sola: `/show/q/art_installati` elenca cosa e' stato
 * consegnato a ciascun cliente, con matricola e articolo. Quella e' la fonte
 * buona, ed e' la stessa che l'ufficio guarda quando dice "e' una A600".
 *
 * Non tocca niente che finisca in fattura: le tariffe dipendono dal pagante,
 * mai dal modello. Cambia cosa c'e' scritto sotto "Modello" nella copia del
 * rapportino che va al cliente, il conteggio del parco macchine, e la chiave
 * con cui i rapportini si abbinano alle schede di Eureka.
 */
class ArticoliMacchineDaEureka extends Command
{
    protected $signature = 'macchine:articoli-da-eureka
                            {--cliente= : limita a un cliente (ragione sociale, anche parziale, o codice Eureka)}
                            {--solo-mancanti : solo le macchine che un articolo non ce l\'hanno proprio}
                            {--concorrenza=10 : quante richieste in parallelo a Eureka}
                            {--tenant=alex : slug del tenant}
                            {--dry-run : mostra le discordanze senza scrivere}';

    protected $description = 'Confronta l\'articolo delle macchine con la bolla di Eureka e corregge le discordanze';

    public function handle(): int
    {
        $tenant = Tenant::query()->where('slug', $this->option('tenant'))->firstOrFail();

        $macchine = MachineUnit::query()
            ->withoutGlobalScopes()
            ->where('tenant_id', $tenant->id)
            ->whereNotNull('current_customer_id')
            ->whereNotNull('serial_number')
            ->when($this->option('solo-mancanti'), fn ($q) => $q->whereNull('material_id'))
            ->with(['material', 'currentCustomer'])
            ->get()
            ->filter(fn (MachineUnit $m) => filled($m->currentCustomer?->gestionale_code));

        if ($filtro = $this->option('cliente')) {
            $macchine = $macchine->filter(fn (MachineUnit $m) => (string) $m->currentCustomer->gestionale_code === (string) $filtro
                || str_contains(mb_strtolower((string) $m->currentCustomer->company_name), mb_strtolower((string) $filtro)));
        }

        if ($macchine->isEmpty()) {
            $this->info('Nessuna macchina da controllare.');

            return self::SUCCESS;
        }

        /** @var Collection<int|string, Customer> $clienti */
        $clienti = $macchine->pluck('currentCustomer')->unique('id')->keyBy('gestionale_code');

        $this->line('  '.$macchine->count().' macchine presso '.$clienti->count().' clienti.');
        $this->line('  Interrogo Eureka a gruppi di '.$this->option('concorrenza').': i sistemi vecchi non gradiscono le raffiche.');
        $this->newLine();

        $client = new EurekaClient($tenant);

        $risposte = $client->pooledGet(
            '/show/q/art_installati',
            $clienti->keys()->mapWithKeys(fn ($codice) => [$codice => ['q' => $codice]])->all(),
            max(1, (int) $this->option('concorrenza')),
        );

        $falliti = $client->chiaviPooledFallite();

        if ($falliti !== []) {
            $this->warn('  '.count($falliti).' clienti non hanno risposto (Eureka a intermittenza): le loro macchine restano come sono.');
        }

        // Cliente -> matricola -> articolo. La chiave e' la coppia, non la
        // sola matricola: Eureka usa "000000" come segnaposto di "matricola
        // sconosciuta", e cercando per sola matricola il segnaposto di un
        // cliente pescava l'articolo della bolla di un altro. E' lo stesso
        // inciampo che aveva gia' mandato la sanificazione di Strana Coppia
        // su un macinadosatore altrui (vedi ImportEurekaServiceReports).
        $bolla = [];
        $segnaposto = 0;

        foreach ($risposte as $codiceCliente => $righe) {
            foreach ((array) $righe as $riga) {
                $matricola = trim((string) ($riga['matricola'] ?? ''));
                $codice = trim((string) ($riga['articolo'] ?? ''));

                if ($matricola === '' || $codice === '') {
                    continue;
                }

                // Solo zeri (e trattini): segnaposto, non una matricola.
                if (trim($matricola, "0- \t") === '') {
                    $segnaposto++;

                    continue;
                }

                $bolla[(string) $codiceCliente][mb_strtolower($matricola)] = [
                    'codice' => $codice,
                    'descrizione' => trim((string) ($riga['desc_articolo_1'] ?? '')),
                    'documento' => $riga['numero_doc_t23'] ?? null,
                ];
            }
        }

        $this->line('  Eureka ha risposto con '.collect($bolla)->map(fn (array $m) => count($m))->sum().' matricole buone'
            .($segnaposto > 0 ? ' e '.$segnaposto.' segnaposto senza matricola, scartati' : '').'.');
        $this->newLine();

        $daFare = [];
        $senzaBolla = 0;
        $senzaArticoloACatalogo = [];

        foreach ($macchine as $macchina) {
            $chiave = mb_strtolower(trim((string) $macchina->serial_number));
            $cliente = (string) $macchina->currentCustomer?->gestionale_code;

            // Anche dalla parte del CRM: una macchina con matricola di soli
            // zeri non si puo' riconoscere, e tirare a indovinare sul suo
            // modello e' peggio che lasciarla com'e'.
            if (trim($chiave, "0- \t") === '' || ! isset($bolla[$cliente][$chiave])) {
                $senzaBolla++;

                continue;
            }

            $giusto = $bolla[$cliente][$chiave];

            if ($macchina->material?->code === $giusto['codice']) {
                continue;
            }

            $articolo = Material::query()
                ->withoutGlobalScopes()
                ->where('tenant_id', $tenant->id)
                ->where('code', $giusto['codice'])
                ->first();

            if (! $articolo) {
                $senzaArticoloACatalogo[$giusto['codice']] = $giusto['descrizione'];

                continue;
            }

            $daFare[] = [
                'macchina' => $macchina,
                'articolo' => $articolo,
                'documento' => $giusto['documento'],
            ];
        }

        $this->line('  '.$senzaBolla.' macchine non compaiono in nessuna bolla: restano come sono.');

        if ($senzaArticoloACatalogo !== []) {
            $this->warn('  '.count($senzaArticoloACatalogo).' articoli della bolla non sono a catalogo nel CRM — lanciare prima eureka:sweep-materials-catalog:');

            foreach (array_slice($senzaArticoloACatalogo, 0, 8, true) as $codice => $descrizione) {
                $this->line('     '.$codice.'  '.$descrizione);
            }
        }

        if ($daFare === []) {
            $this->newLine();
            $this->info('Nessuna discordanza: le macchine hanno l\'articolo della bolla.');

            return self::SUCCESS;
        }

        // Tutte, non le prime quaranta: questo elenco esiste perche' qualcuno
        // lo legga prima di dire di si', e un elenco troncato non si puo'
        // approvare. In cima quelle che un articolo ce l'hanno ed e' sbagliato
        // — sono quelle su cui serve l'occhio — poi quelle che non ne hanno.
        $this->newLine();
        $this->table(
            ['Matricola', 'Cliente', 'Adesso', 'Dice la bolla', 'Doc.'],
            collect($daFare)
                ->sortBy(fn (array $r) => ($r['macchina']->material_id ? '0' : '1')
                    .DisplayName::titleCase($r['macchina']->currentCustomer?->company_name))
                ->map(fn (array $r) => [
                    $r['macchina']->serial_number,
                    mb_substr(DisplayName::titleCase($r['macchina']->currentCustomer?->company_name) ?? '—', 0, 26),
                    $r['macchina']->material?->code ?? '— nessuno',
                    $r['articolo']->code,
                    $r['documento'] ?? '—',
                ])->all(),
        );

        $sbagliate = collect($daFare)->filter(fn (array $r) => $r['macchina']->material_id !== null)->count();

        $this->line('  '.$sbagliate.' hanno un articolo sbagliato, '.(count($daFare) - $sbagliate).' non ne hanno nessuno.');
        $this->newLine();

        if ($this->option('dry-run')) {
            $this->warn('--dry-run: niente e\' stato scritto.');

            return self::SUCCESS;
        }

        if (! $this->confirm('Allineo '.count($daFare).' macchine alla bolla?', false)) {
            $this->line('Annullato: nessuna modifica.');

            return self::SUCCESS;
        }

        foreach ($daFare as $r) {
            // Solo l'articolo e il nome del modello. Il cliente, la posizione
            // e lo storico non si toccano: qui si sta correggendo "che cosa
            // e' questa macchina", non dove sta ne' di chi e'.
            $r['macchina']->forceFill([
                'material_id' => $r['articolo']->id,
                'model_name' => $r['articolo']->display_label,
            ])->saveQuietly();
        }

        $this->info('Fatto: '.count($daFare).' macchine allineate alla bolla.');
        $this->line('  I rapportini gia\' scritti conservano il modello che avevano al momento: e\' quello che il cliente ha ricevuto stampato.');

        return self::SUCCESS;
    }
}
