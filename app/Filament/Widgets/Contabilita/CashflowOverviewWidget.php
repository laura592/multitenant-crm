<?php

namespace App\Filament\Widgets\Contabilita;

use App\Models\EurekaCashflowMese;
use Filament\Facades\Filament;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Collection;

/**
 * I numeri del cash flow, contati SOLO SUL FUTURO.
 *
 * Il periodo che Eureka restituisce parte dal 1° gennaio, quindi comprende
 * mesi già passati: sommarli darebbe una "previsione" che per metà è storia
 * e che non aiuta a decidere niente. Da qui in avanti, invece, è il numero
 * su cui si ragiona.
 *
 * Entrate certe e da fatturare restano separate: una scadenza fattura è un
 * impegno preso, un ordine o una bolla è merce che deve ancora diventare
 * fattura. Sommarle farebbe sembrare sicuro un incasso che non lo è.
 */
class CashflowOverviewWidget extends StatsOverviewWidget
{
    protected static ?string $pollingInterval = null;

    protected static bool $isLazy = false;

    protected function getStats(): array
    {
        /** @var Collection<int, EurekaCashflowMese> $futuri */
        $futuri = EurekaCashflowMese::query()
            ->where('tenant_id', Filament::getTenant()?->id)
            ->orderBy('anno')
            ->orderBy('mese')
            ->get()
            ->filter(fn (EurekaCashflowMese $m) => $m->eFuturo());

        $euro = fn (float $v) => '€ '.number_format($v, 2, ',', '.');

        $entrate = (float) $futuri->sum('entrate');
        $uscite = (float) $futuri->sum('uscite');
        $certe = (float) $futuri->sum(fn (EurekaCashflowMese $m) => $m->entrateCerte());

        // Il primo mese che chiude in rosso: e' l'informazione che fa agire,
        // molto piu' del saldo complessivo.
        $primoRosso = $futuri->first(fn (EurekaCashflowMese $m) => (float) $m->saldo_mese < 0);

        return [
            Stat::make('Entrate previste', $euro($entrate))
                ->description($entrate > 0
                    ? $euro($certe).' già fatturate, il resto da ordini e bolle'
                    : 'nessuna scadenza in arrivo')
                ->color('gray'),

            Stat::make('Uscite previste', $euro($uscite))
                ->description($futuri->count().' mesi da qui in avanti')
                ->color('gray'),

            Stat::make('Saldo del periodo', $euro($entrate - $uscite))
                ->description($entrate - $uscite >= 0 ? 'entra più di quanto esce' : 'esce più di quanto entra')
                ->color($entrate - $uscite >= 0 ? 'success' : 'danger'),

            Stat::make('Primo mese in rosso', $primoRosso?->etichetta() ?? 'nessuno')
                ->description($primoRosso
                    ? 'chiude a '.$euro((float) $primoRosso->saldo_mese)
                    : 'nessun mese chiude sotto zero')
                ->color($primoRosso ? 'danger' : 'success'),
        ];
    }
}
