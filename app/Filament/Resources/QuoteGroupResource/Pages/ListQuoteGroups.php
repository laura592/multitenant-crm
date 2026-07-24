<?php

namespace App\Filament\Resources\QuoteGroupResource\Pages;

use App\Filament\Resources\QuoteGroupResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListQuoteGroups extends ListRecords
{
    protected static string $resource = QuoteGroupResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }
}
