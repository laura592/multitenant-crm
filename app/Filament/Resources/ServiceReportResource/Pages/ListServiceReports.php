<?php

namespace App\Filament\Resources\ServiceReportResource\Pages;

use App\Filament\Resources\ServiceReportResource;
use App\Filament\Pages\ClientiVicini;
use Filament\Actions;
use Filament\Forms;
use Filament\Resources\Pages\ListRecords;

class ListServiceReports extends ListRecords
{
    protected static string $resource = ServiceReportResource::class;

    protected function getHeaderActions(): array
    {
        return [
            // Il riepilogo di un periodo, da stampare: cliente, chi paga,
            // macchina e articoli su una riga sola. Chiede le due date qui
            // invece di leggere i filtri della tabella, perche' chi stampa
            // pensa "il mese scorso", non "quello che ho filtrato".
            Actions\Action::make('riepilogo')
                ->label('Stampa riepilogo')
                ->icon('heroicon-o-printer')
                ->color('gray')
                ->modalHeading('Riepilogo interventi da stampare')
                ->modalSubmitActionLabel('Apri il PDF')
                ->modalWidth('md')
                ->form([
                    Forms\Components\DatePicker::make('da')
                        ->label('Dal')
                        ->default(now()->startOfMonth())
                        ->required()
                        ->native(false),
                    Forms\Components\DatePicker::make('a')
                        ->label('Al')
                        ->default(now())
                        ->required()
                        ->native(false)
                        ->afterOrEqual('da'),
                ])
                // Si apre in una scheda nuova, cosi' l'elenco resta dov'era
                // (con i suoi filtri e la sua pagina) mentre si guarda la
                // stampa. openUrlInNewTab() qui non si puo' usare: l'URL
                // dipende dalle date, che si conoscono solo al submit.
                //
                // Il tenant va passato perche' la rotta sta fuori dal
                // pannello e lo staff master ha tenant_id nullo sull'utente.
                ->action(function (array $data, $livewire): void {
                    $url = route('service-reports.riepilogo', [
                        'da' => $data['da'],
                        'a' => $data['a'],
                        'tenant' => \Filament\Facades\Filament::getTenant()?->getKey(),
                    ]);

                    // JSON_UNESCAPED_SLASHES: senza, l'URL finisce nel JS
                    // come "http:\/\/localhost/..." — funziona, ma e' illeggibile
                    // in console e nei log del browser.
                    $livewire->js('window.open('.json_encode($url, JSON_UNESCAPED_SLASHES).", '_blank')");
                })
                ->visible(fn (): bool => auth()->user()?->can('viewAny', \App\Models\ServiceReport::class) ?? false),
            Actions\Action::make('clientiVicini')
                ->label('Cliente più vicino')
                ->icon('heroicon-o-map-pin')
                ->color('gray')
                ->url(fn () => ClientiVicini::getUrl()),
            Actions\CreateAction::make()
                ->extraAttributes(['data-tour' => 'service-reports-create']),
        ];
    }
}
