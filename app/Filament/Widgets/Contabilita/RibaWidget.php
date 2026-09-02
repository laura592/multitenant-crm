<?php

namespace App\Filament\Widgets\Contabilita;

use App\Models\EurekaFattura;
use Filament\Facades\Filament;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/**
 * Quanta parte del fatturato viaggia su RiBa.
 *
 * Serve a leggere lo scaduto per quello che è. Le fatture a RiBa non
 * compaiono MAI fra le partite aperte — l'effetto è presentato in banca, non
 * lo si sollecita al telefono — quindi lo scaduto clienti racconta solo la
 * parte di fatturato incassata in altro modo. Senza questo numero accanto,
 * un'esposizione bassa può voler dire "incassiamo bene" tanto quanto
 * "quasi tutto passa dalla banca e non lo vediamo qui".
 *
 * Le condizioni RiBa iniziano tutte per R (R001, R041, R030...): è l'unico
 * campo che lo dice, non esiste un flag.
 */
class RibaWidget extends StatsOverviewWidget
{
    protected static ?string $pollingInterval = null;

    protected static bool $isLazy = false;

    // La base e' il TOTALE dei documenti, IVA inclusa: e' quello che il
    // cliente paga davvero, ed e' cio' che passa (o non passa) dalla banca.
    // Detto a voce perche' accanto, nella stessa pagina, il riquadro
    // "Fatturato" mostra il netto contabile: due numeri diversi che
    // parlano della stessa annata sembrano un errore finche' non si dice
    // che misurano cose diverse.
    // Non statiche: StatsOverviewWidget le dichiara come proprieta' di
    // istanza, e ridichiararle static e' un errore fatale di PHP.
    protected ?string $heading = 'Come si incassa';

    protected ?string $description = 'Sul totale delle fatture emesse, IVA inclusa — non sul netto contabile del riquadro sopra.';

    protected function getStats(): array
    {
        $anno = (int) now()->format('Y');

        $fatture = EurekaFattura::query()
            ->where('tenant_id', Filament::getTenant()?->id)
            ->where('tipo', EurekaFattura::TIPO_CLIENTE)
            ->whereYear('data_doc', $anno)
            // Le note di credito abbassano il fatturato ma non "viaggiano"
            // su un canale di incasso: contarle falserebbe la quota.
            ->where('totale_doc', '>', 0)
            ->get(['totale_doc', 'pagamento']);

        $totale = (float) $fatture->sum('totale_doc');
        $riba = (float) $fatture->filter(fn (EurekaFattura $f) => $f->aRiba())->sum('totale_doc');
        $senzaCondizione = $fatture->filter(fn (EurekaFattura $f) => blank($f->pagamento))->count();

        $euro = fn (float $v) => '€ '.number_format($v, 2, ',', '.');

        return [
            Stat::make("Su RiBa nel {$anno}", $euro($riba))
                ->description($totale > 0
                    ? round($riba / $totale * 100).'% del fatturato: non passa mai dallo scaduto'
                    : '—')
                ->color('info'),

            Stat::make('Incassato in altro modo', $euro($totale - $riba))
                ->description('è su questa parte che lo scaduto dice qualcosa')
                ->color('gray'),

            Stat::make('Senza condizione di pagamento', (string) $senzaCondizione)
                ->description($senzaCondizione > 0
                    ? 'fatture su cui non si sa come si incassa'
                    : 'ogni fattura dice come si paga')
                ->color($senzaCondizione > 0 ? 'warning' : 'success'),
        ];
    }
}
