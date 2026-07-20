<?php

namespace App\Filament\Widgets;

use App\Models\Material;
use App\Models\MaterialOrder;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/**
 * Area propria per l'ambito acquisti (ordini fornitori, materiali,
 * categorie), separata dai numeri commerciali di DashboardStatsWidget:
 * stesso sort (0) ma registrata subito dopo in AdminPanelProvider, cosi'
 * compare come blocco distinto appena sotto.
 */
class MagazzinoStatsWidget extends BaseWidget
{
    protected static ?int $sort = 0;

    protected function getStats(): array
    {
        $inviati = MaterialOrder::where('status', 'inviato')->count();

        return [
            Stat::make('Ordini in bozza', MaterialOrder::where('status', 'bozza')->count())
                ->description('Non ancora inviati al fornitore')
                ->icon('heroicon-o-clipboard-document-list')
                ->color('gray'),
            Stat::make('Ordini da ricevere', $inviati)
                ->description('Inviati, in attesa di arrivo')
                ->icon('heroicon-o-paper-airplane')
                ->color($inviati > 0 ? 'warning' : 'success'),
            Stat::make('Materiali a catalogo', Material::count())
                ->description('Totale articoli ordinabili')
                ->icon('heroicon-o-wrench-screwdriver')
                ->color('gray'),
            Stat::make('Categorie', Material::query()->distinct()->count('category'))
                ->description('Categorie materiali in uso')
                ->icon('heroicon-o-rectangle-stack')
                ->color('gray'),
        ];
    }
}
