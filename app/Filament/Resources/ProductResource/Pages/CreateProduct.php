<?php

namespace App\Filament\Resources\ProductResource\Pages;

use App\Filament\Resources\ProductResource;
use App\Models\Product;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

class CreateProduct extends CreateRecord
{
    protected static string $resource = ProductResource::class;

    /**
     * Filament di default creerebbe il record via $tenant->products()->save(),
     * che sovrascriverebbe sempre tenant_id con il tenant corrente - rompendo
     * il toggle "catalogo condiviso" riservato al master admin (§4.2/§11.2),
     * che imposta esplicitamente tenant_id a NULL. Creando direttamente il
     * modello si lascia decidere al trait BelongsToTenant (che rispetta un
     * NULL esplicito) invece che al meccanismo di tenancy nativo di Filament.
     */
    protected function handleRecordCreation(array $data): Model
    {
        return Product::create($data);
    }
}
