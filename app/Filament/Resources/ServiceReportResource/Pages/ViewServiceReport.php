<?php

namespace App\Filament\Resources\ServiceReportResource\Pages;

use App\Filament\Resources\ServiceReportResource;
use App\Support\Gestionale\FattureRapportino;
use Filament\Actions;
use Filament\Resources\Pages\ViewRecord;

class ViewServiceReport extends ViewRecord
{
    protected static string $resource = ServiceReportResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('back')
                ->label('Torna all\'elenco')
                ->icon('heroicon-o-arrow-left')
                ->color('gray')
                ->url(fn () => ServiceReportResource::getUrl('index')),
            ServiceReportResource::faiFirmareAction(Actions\Action::make('fai_firmare')),
            ServiceReportResource::dividiPerMacchinaAction(Actions\Action::make('dividi_per_macchina')),
            // Stesse due action "pdf" della tabella (ServiceReportResource::table()),
            // qui per non dover tornare all'elenco solo per stampare.
            //
            // Ai dipendenti la copia con i prezzi non compare: il controllo
            // che conta e' comunque nel controller, perche' la route sta
            // fuori dal pannello e accetta ?prezzi=1 da chiunque.
            Actions\Action::make('pdf')
                ->label('PDF con prezzi')
                ->icon('heroicon-o-document-arrow-down')
                ->color('gray')
                ->visible(fn (): bool => auth()->user()?->can('viewPrices', $this->record) ?? false)
                ->url(fn () => route('service-reports.pdf', $this->record))
                ->openUrlInNewTab(),
            // Per chi non puo' vedere i prezzi questa e' l'unica voce, e
            // allora si chiama semplicemente "PDF".
            Actions\Action::make('pdf_senza_prezzi')
                ->label(fn (): string => (auth()->user()?->can('viewPrices', $this->record) ?? false)
                    ? 'PDF senza prezzi'
                    : 'PDF')
                ->icon('heroicon-o-document-arrow-down')
                ->color('gray')
                ->visible(fn (): bool => auth()->user()?->can('view', $this->record) ?? false)
                ->url(fn () => route('service-reports.pdf', [$this->record, 'prezzi' => 0]))
                ->openUrlInNewTab(),
            // La fattura Eureka su cui e' finita la scheda. Ha i prezzi, e
            // spesso e' la riepilogativa di chi paga con gli interventi di
            // altri locali: la vede solo chi vede i prezzi. Eureka si
            // interroga all'apertura del modale, non al caricamento pagina.
            Actions\Action::make('fattura_eureka')
                ->label('Fattura')
                ->icon('heroicon-o-receipt-percent')
                ->color('gray')
                ->visible(fn (): bool => $this->record->idSchedaEureka() !== null
                    && (auth()->user()?->can('viewPrices', $this->record) ?? false))
                ->modalHeading('Fattura su Eureka')
                ->modalContent(fn () => view('filament.modals.fatture-eureka', FattureRapportino::per($this->record)))
                ->modalSubmitAction(false)
                ->modalCancelActionLabel('Chiudi'),
            // ->visible() esplicito e indipendente dal Gate: EditAction usa
            // di default ServiceReportPolicy::update(), che nega gia' la
            // modifica sui rapportini bloccati (Eureka o "completato"), ma
            // Gate::before() (is_super_admin bypassa Shield/spatie ovunque,
            // vedi AppServiceProvider) lo scavalcherebbe per lo staff con
            // quel flag — il pulsante non deve comparire per nessuno su un
            // record bloccato, si veda anche RapportiniAPassi::caricaRapportino().
            Actions\EditAction::make()
                ->visible(fn () => ! $this->record->isLocked()),
            ServiceReportResource::modificaVisitaAction(Actions\Action::make('modifica_visita')),
        ];
    }
}
