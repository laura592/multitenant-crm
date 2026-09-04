<?php

namespace App\Filament\Resources\MachineUnitResource\Pages;

use App\Filament\Concerns\ApreStampeInNuovaScheda;
use App\Filament\Resources\MachineUnitResource;
use App\Models\MachineUnit;
use App\Support\DisplayName;
use App\Support\Pdf\StampaTemporanea;
use Barryvdh\DomPDF\Facade\Pdf;
use Filament\Actions;
use Filament\Facades\Filament;
use Filament\Resources\Pages\ListRecords;

class ListMachineUnits extends ListRecords
{
    use ApreStampeInNuovaScheda;

    protected static string $resource = MachineUnitResource::class;

    protected function getHeaderActions(): array
    {
        return [
            // La stampa segue l'elenco che si ha davanti: filtri, ricerca e
            // ordinamento applicati. Cosi' una sola azione copre sia il parco
            // completo (nessun filtro) sia le macchine di un cliente
            // (cercandolo), senza due pulsanti che fanno quasi la stessa cosa.
            Actions\Action::make('stampa_riepilogo')
                ->label('Stampa riepilogo')
                ->icon('heroicon-o-printer')
                ->color('gray')
                ->action(fn () => $this->riepilogoPdf()),
            Actions\CreateAction::make()
                ->extraAttributes(['data-tour' => 'machine-units-create']),
        ];
    }

    private function riepilogoPdf(): void
    {
        // getFilteredSortedTableQuery(): quello che la tabella sta mostrando,
        // ricerca compresa. Senza limit: il PDF non e' paginato come
        // l'elenco, chi stampa vuole il foglio intero.
        $macchine = $this->getFilteredSortedTableQuery()
            // billingCustomer su due livelli: il codice manutenzione cambia
            // col pagante, che puo' stare sulla singola macchina o
            // sull'anagrafica del cliente. Senza, una query per riga.
            ->with(['currentCustomer.billingCustomer', 'billingCustomer', 'material', 'product'])
            ->get();

        $titolo = $this->descrizioneSelezione($macchine);

        $pdf = Pdf::loadView('pdf.machine-units', [
            'macchine' => $macchine,
            'tenant' => Filament::getTenant(),
            'data' => now(),
            'titolo' => $titolo,
            'etichetteStato' => MachineUnitResource::statusLabels(),
            'etichetteCategoria' => MachineUnitResource::typeLabels(),
        ])->setPaper('a4', 'landscape');

        // In una scheda nuova, non scaricato: l'elenco resta dov'era coi
        // suoi filtri, che sono anche quelli che questa stampa segue.
        static::apriUrlInNuovaScheda(
            StampaTemporanea::parcheggia($pdf->output(), 'riepilogo-macchine-'.now()->format('Y-m-d').'.pdf'),
            $this,
        );
    }

    /**
     * Cosa c'e' dentro questa stampa, scritto in testa al foglio: senza,
     * due riepiloghi diversi stampati lo stesso giorno sono indistinguibili
     * sulla scrivania.
     */
    private function descrizioneSelezione($macchine): string
    {
        $clienti = $macchine->pluck('currentCustomer.company_name')->filter()->unique();

        if ($clienti->count() === 1 && $macchine->every(fn (MachineUnit $m) => $m->currentCustomer !== null)) {
            return DisplayName::titleCase($clienti->first());
        }

        return filled($this->getTableSearch()) || array_filter($this->getTableFilterState('status') ?? [])
            ? 'Selezione filtrata'
            : 'Parco macchine completo';
    }
}
