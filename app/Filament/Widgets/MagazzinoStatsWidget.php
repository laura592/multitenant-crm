<?php

namespace App\Filament\Widgets;

use App\Models\Material;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Facades\Auth;

/**
 * Area propria per l'ambito acquisti (materiali, categorie), separata dai
 * numeri commerciali di DashboardStatsWidget. I conteggi ordini (bozza/da
 * ricevere) non stanno piu' qui su richiesta: poco utili in dashboard,
 * restano visibili nella lista Ordini materiali stessa.
 */
class MagazzinoStatsWidget extends BaseWidget
{
    // Ultima sezione della dashboard (SezioneMagazzino, sort 7): sono numeri
    // di consistenza del catalogo, non cose da fare.
    protected static ?int $sort = 8;

    // Vedi commento su PrioritaWidget::getColumns(): stesso motivo.
    protected function getColumns(): int
    {
        return 2;
    }

    // Partner e amministrazione non hanno accesso ai materiali (vedi
    // RolePermissions): questi conteggi non devono comparire in dashboard.
    // Il gate e' su update_material e non su view_any_material perche' sono
    // numeri di consistenza del catalogo, utili a chi il catalogo lo cura:
    // per il dipendente, che sui materiali ha solo la lettura, "Materiali a
    // catalogo: 812" e "Categorie: 14" sono cifre che non cambiano mai e non
    // chiedono nessuna azione — occupavano meta' della prima schermata della
    // sua dashboard senza dirgli niente.
    public static function canView(): bool
    {
        return Auth::user()->can('update_material');
    }

    protected function getStats(): array
    {
        return [
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
