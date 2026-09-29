<?php

namespace App\Console\Commands;

use App\Models\MachineUnit;
use App\Models\ServiceReport;
use App\Models\Tenant;
use App\Support\DisplayName;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Da IMP-SPINA-032 a IMP-SPINA-BARRICATA (28/09/2026).
 *
 * Le matricole a numero non dicono niente: davanti a "IMP-SPINA-032"
 * bisogna aprire la scheda per sapere di chi e'. Con il nome del cliente
 * dentro, l'elenco delle macchine si legge come un elenco di locali — che
 * e' il modo in cui l'ufficio li cerca. E' la stessa scelta gia' fatta a
 * mano su IMP-SOLEIL-CHIOSCO e IMP-SOLEIL-RISTORANTE.
 *
 * Tocca solo gli impianti che ci siamo inventati noi (IMP-*): le matricole
 * vere delle macchine, quelle stampate sulla targhetta e conosciute da
 * Eureka, non si toccano mai.
 *
 * Quello che i rapportini gia' scritti hanno dentro non cambia: il numero
 * di matricola sul rapportino e' la fotografia di quel giorno, ed e' quello
 * che il cliente ha ricevuto stampato. Solo le bozze non ancora passate in
 * gestionale vengono riallineate.
 */
class RinominaImpianti extends Command
{
    protected $signature = 'macchine:rinomina-impianti
                            {--rinomina= : correzioni a mano, es. IMP-ACQUA-012:CASTRETTE,IMP-ACQUA-011:STRANACOPPIA}
                            {--tenant=alex : slug del tenant}
                            {--dry-run : mostra i nomi proposti senza scrivere}';

    protected $description = 'Sostituisce il numero progressivo delle matricole degli impianti col nome del cliente';

    /**
     * Parole che non distinguono un locale da un altro: forme societarie e
     * insegne generiche. "Market Barricata" diventa BARRICATA, non
     * MARKETBARRICATA, perche' e' la parola che l'ufficio ricorda.
     */
    private const DA_TOGLIERE = [
        'srl', 'srls', 's.r.l.', 'snc', 's.n.c.', 'sas', 's.a.s.', 'spa', 's.p.a.', 'ss', 's.s.',
        'societa', 'societa\'', 'unipersonale', 'socio', 'unico', 'con', 'di', 'del', 'della', 'dei',
        'delle', 'dal', 'dalla', 'e', '&', 'c', 'c.', 'sig', 'sigg', 'azienda', 'ditta', 'impresa',
        'bar', 'hotel', 'albergo', 'ristorante', 'market', 'supermercati', 'supermercato', 'camping',
        'centro', 'vacanze', 'villaggio', 'stabilimento', 'balneare', 'chiosco', 'caffe', 'cafe',
        'pasticceria', 'panetteria', 'gelateria', 'pizzeria', 'trattoria', 'osteria', 'ostaria',
        'locanda', 'campeggio', 'villa', 'residence', 'agriturismo', 'spiaggia',
    ];

    public function handle(): int
    {
        $tenant = Tenant::query()->where('slug', $this->option('tenant'))->firstOrFail();

        $macchine = MachineUnit::query()
            ->withoutGlobalScopes()
            ->where('tenant_id', $tenant->id)
            // Solo gli impianti nostri, col progressivo in coda.
            ->where('serial_number', 'regexp', '^IMP-[A-Z]+-[0-9]+$')
            ->with('currentCustomer')
            ->orderBy('serial_number')
            ->get();

        if ($macchine->isEmpty()) {
            $this->info('Nessun impianto con la matricola a numero.');

            return self::SUCCESS;
        }

        // Le matricole gia' occupate, comprese quelle che non stiamo
        // rinominando: il nome nuovo non puo' calpestarne una.
        $occupate = MachineUnit::query()->withoutGlobalScopes()
            ->where('tenant_id', $tenant->id)
            ->pluck('serial_number')
            ->map(fn ($s) => mb_strtoupper((string) $s))
            ->flip();

        $aMano = collect(explode(',', (string) $this->option('rinomina')))
            ->filter()
            ->mapWithKeys(function (string $pezzo) {
                [$matricola, $nome] = array_pad(explode(':', trim($pezzo), 2), 2, null);

                return $matricola && $nome
                    ? [mb_strtoupper(trim($matricola)) => mb_strtoupper(trim($nome))]
                    : [];
            })
            ->all();

        $daFare = [];
        $senzaNome = [];

        foreach ($macchine as $macchina) {
            $prefisso = preg_replace('/[0-9]+$/', '', (string) $macchina->serial_number);
            // Le correzioni a mano vincono: la regola automatica azzecca la
            // maggior parte dei nomi, non tutti, e per i rimanenti e' piu'
            // veloce scriverli che inseguire un'euristica migliore.
            $nome = $aMano[mb_strtoupper((string) $macchina->serial_number)]
                ?? self::sigla($macchina->currentCustomer?->company_name
                    ?: $macchina->currentCustomer?->full_name);

            if ($nome === '') {
                $senzaNome[] = $macchina;

                continue;
            }

            $nuova = $prefisso.$nome;

            // Due clienti diversi possono ridursi alla stessa parola: il
            // secondo prende un progressivo, ma solo lui.
            if (isset($occupate[$nuova])) {
                $i = 2;
                while (isset($occupate[$nuova.'-'.$i])) {
                    $i++;
                }
                $nuova .= '-'.$i;
            }

            $occupate[$nuova] = true;
            $daFare[] = ['macchina' => $macchina, 'nuova' => $nuova];
        }

        $this->table(
            ['Adesso', 'Diventa', 'Cliente'],
            collect($daFare)->map(fn (array $r) => [
                $r['macchina']->serial_number,
                $r['nuova'],
                mb_substr(DisplayName::titleCase($r['macchina']->currentCustomer?->company_name) ?? '—', 0, 40),
            ])->all(),
        );

        if ($senzaNome !== []) {
            $this->warn('  '.count($senzaNome).' impianti restano col numero: dal nome del cliente non esce niente di utile.');

            foreach ($senzaNome as $m) {
                $this->line('     '.$m->serial_number.'  '.($m->currentCustomer?->company_name ?? 'senza cliente'));
            }
        }

        // I rapportini gia' in gestionale conservano la matricola di allora.
        $vecchie = collect($daFare)->pluck('macchina.serial_number')->all();
        $bozze = ServiceReport::query()->withoutGlobalScopes()
            ->whereIn('machine_serial_number', $vecchie)
            ->whereNull('eureka_service_report_id')
            ->count();
        $chiusi = ServiceReport::query()->withoutGlobalScopes()
            ->whereIn('machine_serial_number', $vecchie)
            ->whereNotNull('eureka_service_report_id')
            ->count();

        $this->newLine();
        $this->line('  '.count($daFare).' matricole da cambiare.');
        $this->line('  '.$bozze.' rapportini non ancora in gestionale verranno riallineati.');
        $this->line('  '.$chiusi.' gia\' in gestionale restano com\'erano: e\' quello che il cliente ha ricevuto stampato.');
        $this->newLine();

        if ($this->option('dry-run')) {
            $this->warn('--dry-run: niente e\' stato scritto.');

            return self::SUCCESS;
        }

        if (! $this->confirm('Rinomino '.count($daFare).' impianti?', false)) {
            $this->line('Annullato: nessuna modifica.');

            return self::SUCCESS;
        }

        DB::transaction(function () use ($daFare) {
            foreach ($daFare as $r) {
                $vecchia = $r['macchina']->serial_number;

                $r['macchina']->forceFill(['serial_number' => $r['nuova']])->saveQuietly();

                ServiceReport::query()->withoutGlobalScopes()
                    ->where('machine_serial_number', $vecchia)
                    ->whereNull('eureka_service_report_id')
                    ->update(['machine_serial_number' => $r['nuova']]);
            }
        });

        $this->info('Fatto: '.count($daFare).' impianti rinominati.');

        return self::SUCCESS;
    }

    /**
     * La parola con cui l'ufficio chiama quel locale, ridotta a matricola.
     */
    private static function sigla(?string $nome): string
    {
        if (blank($nome)) {
            return '';
        }

        // Accenti e apostrofi via: "Pra' delle Torri" -> "PRA DELLE TORRI",
        // "Agora'" -> "AGORA". Sono gli stessi che rompono la ricerca.
        $pulito = str_replace(["'", '’'], '', (string) $nome);
        $pulito = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $pulito) ?: $pulito;
        $pulito = mb_strtolower(preg_replace('/[^A-Za-z0-9 ]+/', ' ', $pulito));

        $parole = collect(preg_split('/\s+/', $pulito, -1, PREG_SPLIT_NO_EMPTY))
            ->reject(fn (string $p) => in_array($p, self::DA_TOGLIERE, true))
            ->reject(fn (string $p) => mb_strlen($p) < 3 && ! ctype_digit($p))
            ->values();

        // Se togliendo le parole generiche non resta niente, si tengono
        // quelle: meglio MARKET di una matricola vuota.
        if ($parole->isEmpty()) {
            $parole = collect(preg_split('/\s+/', $pulito, -1, PREG_SPLIT_NO_EMPTY));
        }

        // Una parola sola: e' come le chiama l'ufficio (BARRICATA, SOLEIL)
        // e due attaccate danno mostri — TOFELISFELIS, ZANETTIZANETTI,
        // PATATRACMARTELLOZZ tagliato a meta'. La seconda entra solo se la
        // prima e' troppo corta per dire qualcosa.
        $prima = (string) $parole->first();

        if (mb_strlen($prima) < 5 && $parole->count() > 1) {
            $prima .= $parole->get(1);
        }

        // Mai tagliare in mezzo a una parola: se sfora, si tiene la prima
        // intera e si rinuncia alla seconda.
        if (mb_strlen($prima) > 18) {
            $prima = (string) $parole->first();
        }

        return mb_strtoupper(mb_substr($prima, 0, 18));
    }
}
