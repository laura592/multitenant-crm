<?php

namespace App\Filament\Resources\OffertaCaffeResource\Pages;

use App\Filament\Resources\OffertaCaffeResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditOffertaCaffe extends EditRecord
{
    protected static string $resource = OffertaCaffeResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('pdf')
                ->label('PDF')
                ->icon('heroicon-o-document-arrow-down')
                ->color('gray')
                ->action(fn () => OffertaCaffeResource::apriPdf($this->record, $this)),
            Actions\Action::make('invia')
                ->label('Invia')
                ->icon('heroicon-o-paper-airplane')
                ->color('success')
                ->form(fn () => OffertaCaffeResource::inviaFormSchema())
                ->action(fn (array $data) => OffertaCaffeResource::invia($this->record, $data)),
            Actions\DeleteAction::make(),
        ];
    }
}
