<?php

namespace App\Filament\Resources\QuoteResource\RelationManagers;

use App\Models\Product;
use App\Models\QuoteProduct;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;
use Livewire\Attributes\On;

class QuoteProductsRelationManager extends RelationManager
{
    protected static string $relationship = 'quoteProducts';

    protected static ?string $title = 'Righe preventivo';

    public function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Select::make('product_id')
                ->label('Prodotto')
                ->options(fn () => Product::query()->pluck('name', 'id'))
                ->searchable()
                ->required(),
            Forms\Components\TextInput::make('quantity')->label('Quantità')->numeric()->default(1)->required(),
            Forms\Components\TextInput::make('price')->label('Prezzo (€)')->numeric()->prefix('€')->required(),
            Forms\Components\TextInput::make('discount')->label('Sconto (%)')->numeric()->default(0),
            Forms\Components\TextInput::make('tax')->label('IVA (%)')->numeric()->default(22),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('product.name')
            ->modifyQueryUsing(fn ($query) => $query->orderByRaw('parent_quote_product_id IS NOT NULL, parent_quote_product_id, created_at'))
            ->columns([
                Tables\Columns\TextColumn::make('product.name')
                    ->label('Prodotto')
                    ->formatStateUsing(fn (QuoteProduct $record, string $state) => $record->isOption() ? "↳ {$state}" : $state),
                Tables\Columns\TextColumn::make('quantity')->label('Quantità'),
                Tables\Columns\TextColumn::make('price')->label('Prezzo')->money('EUR'),
                Tables\Columns\TextColumn::make('discount')->label('Sconto')->suffix('%'),
                Tables\Columns\TextColumn::make('tax')->label('IVA')->suffix('%'),
                Tables\Columns\TextColumn::make('total')->label('Totale')->money('EUR'),
            ])
            ->headerActions([
                Tables\Actions\CreateAction::make()
                    ->after(fn (RelationManager $livewire) => $livewire->getOwnerRecord()->updateTotal()),
            ])
            ->actions([
                Tables\Actions\EditAction::make()
                    ->after(fn (RelationManager $livewire) => $livewire->getOwnerRecord()->updateTotal()),
                Tables\Actions\DeleteAction::make()
                    ->after(fn (RelationManager $livewire) => $livewire->getOwnerRecord()->updateTotal()),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make()
                        ->after(fn (RelationManager $livewire) => $livewire->getOwnerRecord()->updateTotal()),
                ]),
            ]);
    }

    #[On('quoteProductsUpdated')]
    public function refreshAfterMachineConfigured(): void
    {
        // Handler vuoto: la presenza dell'attributo #[On] forza Livewire a
        // ri-renderizzare il componente quando ConfigureMachineAction crea le
        // righe scrivendo direttamente sul modello (fuori dal ciclo form di
        // questo RelationManager) - senza questo, le nuove righe restano
        // invisibili finche' non si interagisce di nuovo con la tabella.
    }
}
