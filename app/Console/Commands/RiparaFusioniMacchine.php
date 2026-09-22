<?php

namespace App\Console\Commands;

use App\Models\MachineUnit;
use App\Models\MachineUnitPlacement;
use App\Models\Customer;
use App\Models\Tenant;
use App\Support\Gestionale\EurekaClient;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Rimedia al giro fusione -> ripristino -> fusione (22/09/2026).
 *
 * Fino a oggi la macchina assorbita da una fusione non ricordava in chi era
 * confluita: il sync degli installati, che la ritrova per la matricola scritta
 * come la scrive Eureka, la ripristinava con un posizionamento nuovo copiato
 * dalla bolla, la fusione si riproponeva e ogni conferma aggiungeva allo
 * storico della macchina tenuta un'altra copia della stessa consegna
 * (matricola 1919045: tre volte "Regia S.A.S. dal 01/01/2024, bolla n. 97").
 *
 * Due passi:
 * 1. le fusioni gia' fatte si rileggono dal registro del sync ("macchine
 *    fuse", logs/gestionale-*.log) e la macchina archiviata riceve fusa_in_id,
 *    cosi' il prossimo sync non la ripristina;
 * 2. i posizionamenti doppi (stessa macchina, stesso cliente, stessa data,
 *    stessa nota) si riducono a uno. La nota conta: "-0819352" ha due righe
 *    della stessa bolla con articoli diversi, e puo' darsi che siano due
 *    apparecchi con la stessa matricola, non una copia. Resta il piu' vecchio,
 *    completato con il pagante delle copie se gli mancava;
 * 3. dove la fusione ha tenuto la matricola che Eureka non usa ("1919045"
 *    tenuta, Eureka elenca "1919045-21679"), la macchina tenuta prende quella
 *    di Eureka (MachineUnit::scambiaMatricolaCon()). Solo letture verso
 *    Eureka, per i clienti delle macchine fuse.
 */
class RiparaFusioniMacchine extends Command
{
    protected $signature = 'macchine:ripara-fusioni
                            {--tenant=alex : slug del tenant}
                            {--dry-run : mostra cosa cambierebbe, senza scrivere}';

    protected $description = 'Collega le macchine fuse a quella tenuta e toglie i posizionamenti doppi che il giro di ripristini ha lasciato';

    public function handle(): int
    {
        $tenant = Tenant::query()->where('slug', $this->option('tenant'))->firstOrFail();
        $dryRun = (bool) $this->option('dry-run');

        DB::transaction(function () use ($tenant, $dryRun) {
            $this->collegaFusioni($tenant, $dryRun);
            $this->togliDoppioni($tenant, $dryRun);
            $this->matricoleComeEureka($tenant, $dryRun);
        });

        if ($dryRun) {
            $this->warn('Dry run: niente e\' stato scritto.');
        }

        return self::SUCCESS;
    }

    private function collegaFusioni(Tenant $tenant, bool $dryRun): void
    {
        $righe = [];

        foreach ($this->fusioniDalRegistro() as [$tenuta, $assorbita]) {
            $fusa = MachineUnit::onlyTrashed()->withoutGlobalScopes()
                ->where('tenant_id', $tenant->id)->where('serial_number', $assorbita)
                ->whereNull('fusa_in_id')->get();
            $viva = MachineUnit::query()->withoutGlobalScopes()
                ->where('tenant_id', $tenant->id)->where('serial_number', $tenuta)
                ->whereNull('deleted_at')->get();

            // Solo quando non c'e' dubbio: una sola archiviata e una sola viva.
            // Una assorbita ora di nuovo attiva e' gia' tornata fra le
            // proposte, e alla prossima conferma si collega da sola.
            if ($fusa->count() !== 1 || $viva->count() !== 1) {
                continue;
            }

            $righe[] = [$assorbita, $tenuta];

            if (! $dryRun) {
                // Senza eventi: e' una macchina archiviata, non deve
                // ripassare dall'audit ne' dagli observer di updated().
                MachineUnit::withoutEvents(fn () => $fusa->first()->forceFill(['fusa_in_id' => $viva->first()->id])->save());
            }
        }

        $this->info(count($righe).' fusioni collegate alla macchina tenuta.');

        if ($righe !== []) {
            $this->table(['Assorbita (archiviata)', 'Tenuta'], $righe);
        }
    }

    /**
     * Le coppie tenuta/assorbita dal registro, la piu' recente per ultima.
     *
     * @return array<string, array{0: string, 1: string}>
     */
    private function fusioniDalRegistro(): array
    {
        $coppie = [];
        $file = glob(storage_path('logs/gestionale*.log')) ?: [];
        sort($file);

        foreach ($file as $percorso) {
            foreach (new \SplFileObject($percorso) as $riga) {
                if (! is_string($riga) || ! str_contains($riga, 'macchine: macchine fuse')) {
                    continue;
                }

                $json = json_decode(substr($riga, (int) strpos($riga, '{')), true);

                if (filled($json['tenuta'] ?? null) && filled($json['assorbita'] ?? null)) {
                    $coppie[$json['assorbita']] = [(string) $json['tenuta'], (string) $json['assorbita']];
                }
            }
        }

        return $coppie;
    }

    private function matricoleComeEureka(Tenant $tenant, bool $dryRun): void
    {
        $fuse = MachineUnit::onlyTrashed()->withoutGlobalScopes()
            ->where('tenant_id', $tenant->id)->whereNotNull('fusa_in_id')->get()
            ->groupBy('fusa_in_id');

        $tenute = MachineUnit::query()->withoutGlobalScopes()
            ->whereIn('id', $fuse->keys())->whereNull('deleted_at')->get();

        $clienti = Customer::query()->withoutGlobalScopes()
            ->whereIn('id', $tenute->pluck('current_customer_id')->filter())
            ->whereNotNull('gestionale_code')->get();

        if ($clienti->isEmpty()) {
            return;
        }

        $risposte = (new EurekaClient($tenant))->pooledGet(
            '/show/q/art_installati',
            $clienti->mapWithKeys(fn (Customer $c) => [$c->id => ['q' => (int) $c->gestionale_code]])->all(),
        );

        $righe = [];
        $dubbi = [];

        foreach ($tenute as $tenuta) {
            $suEureka = collect((array) ($risposte[$tenuta->current_customer_id] ?? []))
                ->map(fn ($r) => MachineUnit::chiaveMatricola((string) (is_array($r) ? ($r['matricola'] ?? '') : '')))
                ->filter()->flip();

            if ($suEureka->isEmpty() || $suEureka->has(MachineUnit::chiaveMatricola($tenuta->serial_number))) {
                // Eureka non ha risposto, o la tenuta porta gia' la sua
                // matricola. Se Eureka elenca anche quella fusa, per Eureka
                // sono due apparecchi: si segnala e basta.
                foreach ($fuse[$tenuta->id] as $fusa) {
                    if ($suEureka->has(MachineUnit::chiaveMatricola($fusa->serial_number))) {
                        $dubbi[] = [$tenuta->serial_number, $fusa->serial_number];
                    }
                }

                continue;
            }

            $candidate = $fuse[$tenuta->id]->filter(fn (MachineUnit $f) => $suEureka->has(MachineUnit::chiaveMatricola($f->serial_number)));

            if ($candidate->count() !== 1) {
                continue;
            }

            $righe[] = [$tenuta->serial_number, $candidate->first()->serial_number];

            if (! $dryRun) {
                $tenuta->scambiaMatricolaCon($candidate->first());
            }
        }

        $this->info(count($righe).' macchine prendono la matricola come la scrive Eureka.');

        if ($righe !== []) {
            $this->table(['Matricola ora', 'Come la scrive Eureka'], $righe);
        }

        if ($dubbi !== []) {
            $this->warn(count($dubbi).' fusioni dove Eureka elenca tutte e due le matricole: forse erano due apparecchi, da guardare a mano.');
            $this->table(['Tenuta', 'Assorbita'], $dubbi);
        }
    }

    private function togliDoppioni(Tenant $tenant, bool $dryRun): void
    {
        $gruppi = MachineUnitPlacement::query()->withoutGlobalScopes()
            ->where('tenant_id', $tenant->id)
            ->whereNull('deleted_at')
            ->whereHas('machineUnit', fn ($q) => $q->withoutGlobalScopes()->whereNull('deleted_at'))
            ->with(['machineUnit' => fn ($q) => $q->withoutGlobalScopes(), 'customer' => fn ($q) => $q->withoutGlobalScopes()])
            ->orderBy('created_at')
            ->get()
            ->groupBy(fn (MachineUnitPlacement $p) => $p->machine_unit_id.'|'.$p->customer_id.'|'.$p->placed_at?->toDateTimeString().'|'.$p->notes)
            ->filter(fn (Collection $g) => $g->count() > 1);

        $righe = [];
        $saltati = [];

        foreach ($gruppi as $gruppo) {
            /** @var MachineUnitPlacement $tieni */
            $tieni = $gruppo->first();
            $copie = $gruppo->slice(1);

            // Una chiusa e una aperta non sono la stessa riga: qualcuno ha
            // spostato la macchina nel frattempo. Si guarda a mano.
            if ($gruppo->map(fn ($p) => $p->removed_at?->toDateTimeString())->unique()->count() > 1) {
                $saltati[] = [$tieni->machineUnit->serial_number, $tieni->customer?->company_name, $tieni->placed_at?->format('d/m/Y')];

                continue;
            }

            $righe[] = [$tieni->machineUnit->serial_number, $tieni->customer?->company_name ?? 'magazzino', $tieni->placed_at?->format('d/m/Y'), $copie->count()];

            if ($dryRun) {
                continue;
            }

            $tieni->update(array_filter([
                'billing_customer_id' => $tieni->billing_customer_id ?? $copie->pluck('billing_customer_id')->filter()->first(),
                'eureka_billing_customer_code' => $tieni->eureka_billing_customer_code ?? $copie->pluck('eureka_billing_customer_code')->filter()->first(),
            ], fn ($v) => $v !== null));

            $copie->each->delete();
        }

        $this->info(count($righe).' posizionamenti con copie doppie.');

        if ($righe !== []) {
            $this->table(['Matricola', 'Cliente', 'Dal', 'Copie tolte'], $righe);
        }

        if ($saltati !== []) {
            $this->warn(count($saltati).' gruppi lasciati com\'erano (una copia chiusa, una aperta): da guardare a mano.');
            $this->table(['Matricola', 'Cliente', 'Dal'], $saltati);
        }
    }
}
