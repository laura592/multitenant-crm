<?php

namespace App\Filament\Resources\MaterialOrderResource\Pages;

use App\Filament\Resources\MaterialOrderResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditMaterialOrder extends EditRecord
{
    protected static string $resource = MaterialOrderResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('pdf')
                ->label('Genera PDF ordine')
                ->icon('heroicon-o-document-arrow-down')
                ->action(fn () => MaterialOrderResource::streamPdf($this->record)),
            Actions\DeleteAction::make(),
        ];
    }
}
