<?php

namespace App\Filament\Resources\UserResource\Pages;

use App\Filament\Resources\UserResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditUser extends EditRecord
{
    protected static string $resource = UserResource::class;

    protected function getHeaderActions(): array
    {
        return [
            // Non permettere di eliminare il proprio account dalla pagina di modifica:
            // si perderebbe l'accesso al pannello senza un altro admin che lo ripristini.
            Actions\DeleteAction::make()
                ->hidden(fn () => $this->record->id === auth()->id()),
        ];
    }
}
