<?php

namespace App\Filament\Pages;

use App\Filament\Pages\Widgets\AppointmentsCalendarWidget;
use App\Filament\Resources\AppointmentResource;
use BezhanSalleh\FilamentShield\Traits\HasPageShield;
use Filament\Actions\Action;
use Filament\Pages\Page;

/**
 * Calendario in-app degli appuntamenti (docs/architecture.md §15.3).
 */
class AppuntamentiCalendario extends Page
{
    use HasPageShield;

    protected static ?string $navigationIcon = 'heroicon-o-calendar';

    protected static ?string $navigationGroup = 'Interventi tecnici';

    protected static ?string $navigationLabel = 'Calendario appuntamenti';

    protected static ?string $title = 'Calendario appuntamenti';

    protected static string $view = 'filament.pages.appuntamenti-calendario';

    protected function getFooterWidgets(): array
    {
        return [
            AppointmentsCalendarWidget::class,
        ];
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('viewList')
                ->label('Vedi elenco/filtra')
                ->icon('heroicon-o-list-bullet')
                ->color('gray')
                ->url(fn () => AppointmentResource::getUrl('index')),
        ];
    }
}
