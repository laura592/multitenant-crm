<?php

namespace App\Filament\Widgets\Contabilita;

use App\Models\EurekaFatturatoMese;
use Filament\Facades\Filament;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/**
 * I numeri del fatturato in chiaro: anno in corso, stesso periodo dell'anno
 * scorso, e quanto esce verso i fornitori.
 *
 * Il confronto è a PARITÀ DI PERIODO, non anno intero contro anno intero:
 * a settembre, dire "2026: 400.000, 2025: 600.000" farebbe sembrare un
 * crollo quello che è solo un anno non ancora finito.
 */
class FatturatoOverviewWidget extends StatsOverviewWidget
{
    protected static ?string $pollingInterval = null;

    // Vedi ScadutoOverviewWidget: sincrono perche' vive dentro una Page.
    protected static bool $isLazy = false;

    protected function getStats(): array
    {
        $anno = (int) now()->format('Y');
        $meseCorrente = (int) now()->format('n');

        $netto = fn (string $tipo, int $anno, ?int $finoAlMese = null) => (float) EurekaFatturatoMese::query()
            ->where('tenant_id', Filament::getTenant()?->id)
            ->where('tipo', $tipo)
            ->where('anno', $anno)
            ->when($finoAlMese !== null, fn ($q) => $q->where('mese', '<=', $finoAlMese))
            ->sum('netto');

        $euro = fn (float $v) => '€ '.number_format($v, 2, ',', '.');

        $quest = $netto(EurekaFatturatoMese::TIPO_CLIENTE, $anno);
        $scorso = $netto(EurekaFatturatoMese::TIPO_CLIENTE, $anno - 1, $meseCorrente);
        $scorsoIntero = $netto(EurekaFatturatoMese::TIPO_CLIENTE, $anno - 1);
        $fornitori = $netto(EurekaFatturatoMese::TIPO_FORNITORE, $anno);

        return [
            Stat::make("Fatturato {$anno}", $euro($quest))
                ->description($this->confronto($quest, $scorso, $anno - 1))
                ->color(match (true) {
                    $scorso <= 0 => 'gray',
                    $quest >= $scorso => 'success',
                    default => 'warning',
                }),

            Stat::make('Stesso periodo '.($anno - 1), $euro($scorso))
                ->description($scorsoIntero > 0 ? 'anno intero '.$euro($scorsoIntero) : '—')
                ->color('gray'),

            Stat::make("Acquisti {$anno}", $euro($fornitori))
                ->description('fatture fornitori registrate')
                ->color('gray'),
        ];
    }

    /** "+12% sul 2025" — e niente percentuale se non c'è un termine di paragone. */
    private function confronto(float $adesso, float $prima, int $annoPrima): string
    {
        if ($prima <= 0) {
            return "nessun dato sul {$annoPrima} da confrontare";
        }

        $variazione = (int) round(($adesso - $prima) / $prima * 100);

        return sprintf('%s%d%% sullo stesso periodo %d', $variazione >= 0 ? '+' : '', $variazione, $annoPrima);
    }
}
