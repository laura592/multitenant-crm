<?php

namespace App\Filament\Resources\ServiceReportResource\Pages;

use App\Filament\Resources\ServiceReportResource;
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
            // Stessa action "pdf" della tabella (ServiceReportResource::table()),
            // qui per non dover tornare all'elenco solo per stampare.
            Actions\Action::make('pdf')
                ->label('PDF')
                ->icon('heroicon-o-document-arrow-down')
                ->color('gray')
                ->visible(fn (): bool => auth()->user()?->can('view', $this->record) ?? false)
                ->url(fn () => route('service-reports.pdf', $this->record))
                ->openUrlInNewTab(),
            Actions\EditAction::make(),
        ];
    }
}
