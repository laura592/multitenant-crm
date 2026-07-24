<?php

namespace App\Filament\Resources\AuditLogResource\Pages;

use App\Filament\Resources\AuditLogResource;
use Filament\Resources\Pages\ListRecords;

/**
 * Nessuna CreateAction: il log e' generato automaticamente dal package,
 * mai creato a mano dal pannello (vedi AuditLogResource::form()).
 */
class ListAuditLogs extends ListRecords
{
    protected static string $resource = AuditLogResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }
}
