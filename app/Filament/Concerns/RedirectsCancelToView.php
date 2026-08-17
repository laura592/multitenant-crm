<?php

namespace App\Filament\Concerns;

use Filament\Actions\Action;

/**
 * Il bottone "Annulla" del form di modifica (Filament EditRecord::
 * getCancelFormAction()) di default fa window.history.back(): se si arriva
 * in modifica direttamente dall'elenco (azione di riga "Modifica", non
 * passando dalla view) "Annulla" riporta all'elenco invece che al dettaglio
 * del record. Qui lo si punta esplicitamente alla view del record (o
 * all'elenco se la risorsa non ne ha una), indipendentemente da come si e'
 * arrivati in modifica — ricostruita da zero (non un ->url() sull'azione
 * base) perche' altrimenti resterebbe anche l'x-on:click di history.back()
 * ereditato, in conflitto con l'href appena impostato.
 *
 * @mixin \Filament\Resources\Pages\EditRecord
 */
trait RedirectsCancelToView
{
    protected function getCancelFormAction(): Action
    {
        return Action::make('cancel')
            ->label(__('filament-panels::resources/pages/edit-record.form.actions.cancel.label'))
            ->color('gray')
            ->url($this->getCancelRedirectUrl());
    }

    protected function getCancelRedirectUrl(): string
    {
        $resource = static::getResource();

        return $resource::hasPage('view')
            ? $resource::getUrl('view', ['record' => $this->getRecord()])
            : $resource::getUrl('index');
    }
}
