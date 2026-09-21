<?php

namespace App\Filament\Resources\PriceListResource\Pages;

use App\Filament\Resources\PriceListResource;
use App\Models\PriceList;
use Filament\Actions;
use Filament\Resources\Components\Tab;
use Filament\Resources\Pages\ManageRecords;
use Illuminate\Database\Eloquent\Builder;

class ManagePriceLists extends ManageRecords
{
    protected static string $resource = PriceListResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make()
                ->extraAttributes(['data-tour' => 'price-lists-create']),
        ];
    }

    public function getTabs(): array
    {
        return [
            'tutti' => Tab::make('Tutti'),
            'listini' => Tab::make('Listini')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('category', PriceList::LISTINO)),
            'cataloghi' => Tab::make('Cataloghi')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('category', PriceList::CATALOGO)),
            'contratti' => Tab::make('Contratti')
                ->modifyQueryUsing(fn (Builder $query) => $query->whereIn('category', PriceList::CONTRATTI)),
            'altro' => Tab::make('Altro')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('category', PriceList::ALTRO)),
        ];
    }
}
