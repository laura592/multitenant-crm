<?php

namespace App\Filament\Resources\ProductOptionGroupResource\Pages;

use App\Filament\Resources\ProductOptionGroupResource;
use Filament\Actions;
use Filament\Resources\Pages\ManageRecords;

class ManageProductOptionGroups extends ManageRecords
{
    protected static string $resource = ProductOptionGroupResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }
}
