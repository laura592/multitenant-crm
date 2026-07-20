<?php

namespace App\Filament\Resources\MaterialOrderResource\RelationManagers;

use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;
use Livewire\Attributes\On;

/**
 * Tabella vera (non un Repeater) per le righe dell'ordine, raggruppata per
 * categoria materiale. Niente azione "New" qui: le righe si aggiungono solo
 * dal picker per categoria nell'header di EditMaterialOrder, che scrive
 * direttamente su DB e poi dispatcha materialOrderItemsUpdated per
 * aggiornare questa tabella (stesso pattern di QuoteProductsRelationManager/
 * ConfigureMachineAction).
 */
class ItemsRelationManager extends RelationManager
{
    protected static string $relationship = 'items';

    protected static ?string $title = 'Materiali nell\'ordine';

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('material.code')
            ->columns([
                Tables\Columns\TextColumn::make('material.code')
                    ->label('Codice')
                    ->searchable(),
                Tables\Columns\TextColumn::make('supplier_mismatch')
                    ->label('')
                    ->getStateUsing(function ($record) {
                        $orderSupplierId = $this->getOwnerRecord()->supplier_id;

                        if (blank($orderSupplierId) || $record->material->supplier_id === $orderSupplierId) {
                            return null;
                        }

                        return 'Altro fornitore';
                    })
                    ->badge()
                    ->color('warning'),
                Tables\Columns\TextColumn::make('material.variant')
                    ->label('Tipo')
                    ->formatStateUsing(fn ($record) => $record->material->variant ?: $record->material->type),
                Tables\Columns\TextColumn::make('material.tube_diameter')
                    ->label('Tubo Ø')
                    ->formatStateUsing(fn ($record) => $record->material->tube_diameter_2
                        ? "{$record->material->tube_diameter} x {$record->material->tube_diameter_2}"
                        : $record->material->tube_diameter)
                    ->placeholder('—'),
                Tables\Columns\TextColumn::make('material.thread_size')
                    ->label('Filetto')
                    ->formatStateUsing(fn ($record) => trim(($record->material->thread_size ?? '').' '.($record->material->thread_type ?? '')))
                    ->placeholder('—'),
                Tables\Columns\TextColumn::make('material.barb_diameter')
                    ->label('Codolo Ø')
                    ->placeholder('—')
                    ->toggleable(),
                Tables\Columns\TextInputColumn::make('quantity')
                    ->label('Quantità')
                    ->type('number')
                    ->rules(['required', 'integer', 'min:1']),
            ])
            ->defaultGroup(
                Tables\Grouping\Group::make('material.category')->label('Categoria')
            )
            ->groups([
                Tables\Grouping\Group::make('material.category')->label('Categoria'),
            ])
            ->headerActions([])
            ->actions([
                Tables\Actions\DeleteAction::make(),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ])
            ->emptyStateHeading('Nessun materiale ancora nell\'ordine')
            ->emptyStateDescription('Usa "Aggiungi materiali" qui sopra per iniziare.')
            ->emptyStateIcon('heroicon-o-clipboard-document-list');
    }

    #[On('materialOrderItemsUpdated')]
    public function refreshAfterAdd(): void
    {
        // Handler vuoto: la presenza dell'attributo #[On] forza Livewire a
        // ri-renderizzare la tabella quando il picker per categoria scrive
        // righe direttamente sul modello (fuori dal ciclo tabella di questo
        // RelationManager) - senza questo, le nuove righe restano invisibili
        // finche' non si interagisce di nuovo con la tabella.
    }
}
