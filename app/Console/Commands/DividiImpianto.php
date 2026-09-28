<?php

namespace App\Console\Commands;

use App\Models\MachineUnit;
use App\Models\MaintenanceSchedule;
use App\Models\Tenant;
use App\Support\DisplayName;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Separa una macchina che in realta' sono due impianti (28/09/2026).
 *
 * Le Soleil aveva un record solo, `IMP-SPINA-021`, che nel nome confessava il
 * problema: "Impianto Spina (2 impianti: chiosco+ristorante, birra+vino+selz)".
 * Tutti i piani lavaggio appesi li', e quindi nessun modo di sapere quali vie
 * stiano al chiosco e quali in terrazza. Il conteggio non tornava mai, e il
 * tecnico se lo scriveva a mano nella descrizione — "2 Imp x 2 Via", "1 via
 * birra e 1 via vino chiosco".
 *
 * Non e' un caso isolato: al 28/09/2026 sono 24 le macchine che nel nome
 * dichiarano piu' di un impianto.
 *
 * Qui si spacca in due. La macchina esistente resta con i suoi rapportini e i
 * suoi piani — la storia non si riscrive, perche' a posteriori non si sa quale
 * dei due impianti fosse — e ne nasce una seconda con i piani suoi.
 */
class DividiImpianto extends Command
{
    protected $signature = 'macchine:dividi-impianto
                            {matricola : la macchina da dividere}
                            {--nome= : nome del nuovo impianto (es. "Impianto Spina Chiosco")}
                            {--vie= : vie del NUOVO impianto, es. birra:1,vino:1,selz:1}
                            {--resta= : vie che restano sull\'esistente, es. birra:2,vino:1,selz:1}
                            {--matricola= : matricola del nuovo impianto (default: il numero successivo)}
                            {--rinomina= : nuova matricola per quello esistente, se il numero non dice niente}
                            {--tenant=alex : slug del tenant}
                            {--dry-run : mostra cosa cambierebbe senza scrivere}';

    protected $description = 'Separa in due una macchina che tiene insieme due impianti, distribuendo le vie';

    public function handle(): int
    {
        $tenant = Tenant::query()->where('slug', $this->option('tenant'))->firstOrFail();

        $vecchia = MachineUnit::query()
            ->withoutGlobalScopes()
            ->where('tenant_id', $tenant->id)
            ->where('serial_number', $this->argument('matricola'))
            ->first();

        if (! $vecchia) {
            $this->error('Matricola '.$this->argument('matricola').' non trovata.');

            return self::FAILURE;
        }

        if (! $vecchia->current_customer_id) {
            $this->error('La macchina non e\' assegnata a nessun cliente: non c\'e\' niente da dividere.');

            return self::FAILURE;
        }

        $nuove = self::vie((string) $this->option('vie'));
        $restano = self::vie((string) $this->option('resta'));

        if ($nuove === []) {
            $this->error('Serve --vie con le vie del nuovo impianto, es. --vie=birra:1,vino:1,selz:1');

            return self::FAILURE;
        }

        $nome = (string) ($this->option('nome') ?: $vecchia->model_name.' (2)');
        $matricolaNuova = (string) ($this->option('matricola')
            ?: $this->prossimaMatricola($tenant, $vecchia->serial_number));

        // Una matricola gia' in uso farebbe due macchine indistinguibili:
        // e' proprio il problema che stiamo risolvendo.
        foreach (array_filter([$matricolaNuova, (string) $this->option('rinomina')]) as $m) {
            if ($m !== $vecchia->serial_number && MachineUnit::query()->withoutGlobalScopes()
                ->where('tenant_id', $tenant->id)->where('serial_number', $m)->exists()) {
                $this->error("La matricola {$m} e' gia' in uso.");

                return self::FAILURE;
            }
        }

        $matricolaEsistente = (string) ($this->option('rinomina') ?: $vecchia->serial_number);

        $pianiEsistenti = MaintenanceSchedule::query()
            ->withoutGlobalScopes()
            ->where('machine_unit_id', $vecchia->id)
            ->where('type', MaintenanceSchedule::TYPE_LAVAGGIO)
            ->get()
            ->keyBy('beverage_type');

        $this->line('Cliente: '.DisplayName::titleCase($vecchia->currentCustomer?->company_name));
        $this->newLine();

        $righe = [];
        foreach ($pianiEsistenti as $bevanda => $piano) {
            $righe[] = [
                $bevanda,
                $piano->lines_count ?? '—',
                $restano[$bevanda] ?? ($piano->lines_count ?? '—'),
                $nuove[$bevanda] ?? '—',
            ];
        }
        foreach ($nuove as $bevanda => $vie) {
            if (! $pianiEsistenti->has($bevanda)) {
                $righe[] = [$bevanda, '—', $restano[$bevanda] ?? '—', $vie];
            }
        }

        $this->table(
            ['Bevanda', 'Vie adesso', $matricolaEsistente, $matricolaNuova],
            $righe,
        );

        $this->line('  Esistente: '.$matricolaEsistente
            .($matricolaEsistente !== $vecchia->serial_number ? ' (era '.$vecchia->serial_number.')' : '')
            .' — '.mb_substr((string) $vecchia->model_name, 0, 50));
        $rapportini = \App\Models\ServiceReport::query()->withoutGlobalScopes()
            ->where('machine_unit_id', $vecchia->id)->count();
        $this->line('             tiene i suoi '.$rapportini.' rapportini e lo storico');
        $this->line('  Nuovo:     '.$matricolaNuova.' — '.$nome);

        if ($this->option('dry-run')) {
            $this->warn('--dry-run: niente e\' stato scritto.');

            return self::SUCCESS;
        }

        if (! $this->confirm('Divido '.$vecchia->serial_number.' in due impianti?', false)) {
            $this->line('Annullato: nessuna modifica.');

            return self::SUCCESS;
        }

        DB::transaction(function () use ($vecchia, $tenant, $nome, $matricolaNuova, $matricolaEsistente, $nuove, $restano, $pianiEsistenti) {
            $nuovaMacchina = MachineUnit::create([
                'tenant_id' => $tenant->id,
                'current_customer_id' => $vecchia->current_customer_id,
                'serial_number' => $matricolaNuova,
                'model_name' => $nome,
                'product_id' => $vecchia->product_id,
                'material_id' => $vecchia->material_id,
                'source' => 'manuale',
                // Chi paga e' della posizione, non del modello: il secondo
                // impianto sta dallo stesso cliente, quindi paga lo stesso.
                'billing_customer_id' => $vecchia->billing_customer_id,
            ]);

            foreach ($nuove as $bevanda => $vie) {
                $modello = $pianiEsistenti->get($bevanda);

                MaintenanceSchedule::create([
                    'tenant_id' => $tenant->id,
                    'customer_id' => $vecchia->current_customer_id,
                    'machine_unit_id' => $nuovaMacchina->id,
                    'type' => MaintenanceSchedule::TYPE_LAVAGGIO,
                    'status' => MaintenanceSchedule::STATUS_ATTIVO,
                    'beverage_type' => $bevanda,
                    'lines_count' => $vie,
                    // La cadenza la eredita dal piano gemello: e' lo stesso
                    // locale, si lavano insieme.
                    'frequency' => $modello?->frequency,
                    'frequency_days' => $modello?->frequency_days,
                ]);
            }

            foreach ($restano as $bevanda => $vie) {
                $pianiEsistenti->get($bevanda)?->update(['lines_count' => $vie]);
            }

            // Il nome non deve piu' dire "2 impianti": era la nota che
            // segnalava il problema, e il problema adesso non c'e' piu'.
            $ripulito = trim(preg_replace('/\s*\(?\s*2 impianti[^)]*\)?/i', '', (string) $vecchia->model_name));
            $cambi = [];
            if ($ripulito !== '' && $ripulito !== $vecchia->model_name) {
                $cambi['model_name'] = $ripulito;
            }
            // La matricola dell'esistente cambia solo se glielo si chiede: i
            // rapportini gia' fatti conservano quella vecchia scritta dentro
            // (machine_serial_number), e va bene — dice cosa c'era allora.
            if ($matricolaEsistente !== $vecchia->serial_number) {
                $cambi['serial_number'] = $matricolaEsistente;
            }
            if ($cambi !== []) {
                $vecchia->update($cambi);
            }
        });

        $this->info('Fatto. '.$matricolaEsistente.' e '.$matricolaNuova.' sono due impianti distinti.');
        $this->line('Controlla i piani dal dettaglio del cliente: lo storico dei lavaggi e\' rimasto su '.$matricolaEsistente.'.');

        return self::SUCCESS;
    }

    /**
     * "birra:1,vino:1,selz:1" => ['birra' => 1, 'vino' => 1, 'selz' => 1]
     *
     * @return array<string, int>
     */
    private static function vie(string $spec): array
    {
        $out = [];

        foreach (array_filter(explode(',', $spec)) as $pezzo) {
            [$bevanda, $vie] = array_pad(explode(':', trim($pezzo), 2), 2, null);
            if ($bevanda && is_numeric($vie)) {
                $out[strtolower(trim($bevanda))] = (int) $vie;
            }
        }

        return $out;
    }

    private function prossimaMatricola(Tenant $tenant, string $modello): string
    {
        // Stesso prefisso della macchina che si divide (IMP-SPINA-), numero
        // successivo al piu' alto in uso.
        $prefisso = preg_replace('/\d+$/', '', $modello);

        $massimo = MachineUnit::query()
            ->withoutGlobalScopes()
            ->where('tenant_id', $tenant->id)
            ->where('serial_number', 'like', $prefisso.'%')
            ->get()
            ->map(fn (MachineUnit $m) => (int) preg_replace('/\D/', '', substr($m->serial_number, strlen($prefisso))))
            ->max() ?? 0;

        return $prefisso.str_pad((string) ($massimo + 1), 3, '0', STR_PAD_LEFT);
    }
}
