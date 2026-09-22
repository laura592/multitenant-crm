<?php

namespace App\Console\Commands;

use App\Models\Customer;
use App\Models\MachineUnit;
use App\Models\Product;
use App\Models\ServiceReport;
use App\Models\Tenant;
use App\Support\Gestionale\ControlloPaganteFattura;
use App\Support\Gestionale\SenzaFatturaCollegata;
use Illuminate\Console\Command;

/**
 * Quanto resta da guardare fra CRM ed Eureka, in un colpo d'occhio (sola
 * lettura): rapportini senza fattura per motivo (SenzaFatturaCollegata),
 * schede da correggere su Eureka e i riquadri di "Verifica sync gestionale".
 */
class RiepilogoGestionale extends Command
{
    protected $signature = 'gestionale:riepilogo {--tenant= : Slug tenant (default: tenant master)}';

    protected $description = 'Riepilogo (sola lettura) di cosa resta da sistemare fra CRM ed Eureka';

    private const MOTIVI = [
        SenzaFatturaCollegata::RECENTE => 'recenti (fattura di fine mese non ancora uscita)',
        SenzaFatturaCollegata::SENZA_IMPORTO => 'senza importo (niente da fatturare)',
        SenzaFatturaCollegata::DOPPIONE => 'doppioni di una scheda gia\' fatturata',
        SenzaFatturaCollegata::FATTURA_NON_COLLEGATA => 'probabilmente in una fattura fatta a mano',
        SenzaFatturaCollegata::NON_NELLA_FATTURA => 'lasciati fuori da una fattura fatta dalle schede: da controllare',
        SenzaFatturaCollegata::DA_VERIFICARE => 'da verificare',
    ];

    public function handle(): int
    {
        $tenant = $this->option('tenant')
            ? Tenant::where('slug', $this->option('tenant'))->firstOrFail()
            : Tenant::where('is_master', true)->firstOrFail();

        $gestionale = fn () => ControlloPaganteFattura::rapportiniNelGestionale($tenant->id);

        $this->info('RAPPORTINI NEL GESTIONALE: '.$gestionale()->count());
        $this->line('  fatturati: '.$gestionale()->whereNotNull('eureka_fatture')->count());
        $this->line('  senza fattura collegata: '.$gestionale()->whereNull('eureka_fatture')->count());

        $perMotivo = $gestionale()->whereNull('eureka_fatture')
            ->selectRaw("COALESCE(eureka_fattura_motivo, '-') as motivo, COUNT(*) as n")
            ->groupBy('motivo')
            ->pluck('n', 'motivo');

        foreach ($perMotivo->sortDesc() as $motivo => $n) {
            $this->line("     - {$n} ".(self::MOTIVI[$motivo] ?? 'non ancora classificati'));
        }

        $this->line('  schede da correggere su Eureka (pagante diverso dalla fattura): '.$gestionale()->whereNotNull('pagante_fattura_customer_id')->count());

        $this->newLine();
        $this->info('RAPPORTINI NON ANCORA NEL GESTIONALE: '.ServiceReport::withoutGlobalScopes()
            ->whereNull('deleted_at')->where('tenant_id', $tenant->id)
            ->where('source', ServiceReport::SOURCE_MANUALE)->whereNull('eureka_service_report_id')
            ->where(fn ($q) => $q->whereNull('gestionale_sync_status')->orWhere('gestionale_sync_status', '!=', 'sent'))
            ->count());

        $clienti = fn () => Customer::withoutGlobalScopes()->whereNull('deleted_at')->where('tenant_id', $tenant->id);
        $macchine = fn () => MachineUnit::withoutGlobalScopes()->whereNull('deleted_at')->where('tenant_id', $tenant->id);

        $this->newLine();
        $this->info('VERIFICA SYNC GESTIONALE:');
        $this->table(['Riquadro', 'Da guardare'], [
            ['Da rivedere (clienti con differenze da Eureka)', $clienti()->whereNotNull('gestionale_review_flagged_at')
                ->where(fn ($q) => $q->where('gestionale_review_note', 'not like', 'Compilati automaticamente:%')->orWhereNull('gestionale_review_note'))->count()],
            ['Collegamenti proposti - clienti', $clienti()->whereNotNull('gestionale_suggested_code')->count()],
            ['Collegamenti proposti - prodotti', Product::withoutGlobalScopes()->whereNotNull('gestionale_suggested_code')->count()],
            ['Collegamenti proposti - macchinari', $macchine()->whereNotNull('gestionale_suggested_code')->count()],
            ['Rapportini doppi', ServiceReport::withoutGlobalScopes()->whereNull('deleted_at')->where('tenant_id', $tenant->id)->whereNotNull('duplicato_suggerito_id')->count()],
            ['Macchine doppie (stessa matricola)', $macchine()->whereNotNull('fusione_suggerita_id')->count()],
            ['Macchine spostate su Eureka', $macchine()->whereNotNull('spostamento_suggerito_customer_id')->count()],
            ['Schede da correggere su Eureka (pagante)', $gestionale()->whereNotNull('pagante_fattura_customer_id')->count()],
        ]);

        return self::SUCCESS;
    }
}
