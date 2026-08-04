<?php

namespace App\Filament\Widgets\Gestionale;

use App\Models\MachineUnit;
use App\Models\MachineUnitProposal;
use App\Models\Product;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget as BaseWidget;

/**
 * Macchine trovate su Eureka (/show/q/art_installati) che non esistono
 * ancora nel CRM — vedi GestionaleSyncRunner::proposeInstalledMachines().
 * "Conferma" crea davvero la MachineUnit (e il Product se non era gia'
 * collegato), "Scarta" cancella solo la proposta.
 */
class GestionaleMacchineNuoveWidget extends BaseWidget
{
    protected static ?string $heading = 'Macchine nuove trovate su Eureka';

    protected int|string|array $columnSpan = 1;

    // Vedi GestionaleDaRivedereWidget: senza un identificatore proprio, la
    // paginazione di questa tabella condivide ?page= con le altre 4 della
    // stessa pagina.
    protected function getTableQueryStringIdentifier(): ?string
    {
        return 'macchineNuove';
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(MachineUnitProposal::query()->whereNull('dismissed_at')->with(['customer', 'product']))
            ->columns([
                Tables\Columns\TextColumn::make('customer.full_name')->label('Cliente'),
                Tables\Columns\TextColumn::make('serial_number')->label('Matricola'),
                Tables\Columns\TextColumn::make('model_name')->label('Modello trovato')->placeholder('—'),
                Tables\Columns\TextColumn::make('product.name')->label('Prodotto gia\' collegato')->placeholder('nessuno — verra\' creato'),
            ])
            ->actions([
                Tables\Actions\Action::make('conferma_macchina_nuova')
                    ->label('Conferma')
                    ->icon('heroicon-o-plus-circle')
                    ->color('warning')
                    ->requiresConfirmation()
                    ->modalDescription('Crea davvero questa macchina nel CRM (e il prodotto collegato, se non esisteva). Non scrive nulla su Eureka.')
                    ->form([
                        Forms\Components\TextInput::make('owner_name')
                            ->label('Proprietà (chi possiede legalmente la macchina)')
                            ->helperText('Eureka non lo dice — es. "Dersut" se in comodato, o il nome del cliente se è suo.')
                            ->required(),
                        Forms\Components\Select::make('product_id')
                            ->label('Prodotto collegato')
                            ->options(fn () => Product::query()->orderBy('name')->pluck('name', 'id'))
                            ->searchable()
                            ->live()
                            ->default(fn (MachineUnitProposal $record) => $record->product_id)
                            ->helperText('Se lasci vuoto, viene creato un prodotto nuovo con i dati trovati su Eureka.'),
                        Forms\Components\Select::make('product_type')
                            ->label('Tipo del prodotto nuovo')
                            ->helperText('Su Eureka compaiono sia macchine da caffè che accessori (addolcitori, macinadosatori...): va scelto a vista, non indovinato.')
                            ->options([
                                Product::TYPE_MACHINE => 'Macchina',
                                Product::TYPE_AUXILIARY_UNIT => 'Unità ausiliaria',
                                Product::TYPE_ACCESSORY => 'Accessorio',
                                Product::TYPE_OPTION => 'Opzione',
                                Product::TYPE_SERVICE => 'Servizio',
                            ])
                            ->visible(fn (Forms\Get $get) => blank($get('product_id')))
                            ->required(fn (Forms\Get $get) => blank($get('product_id'))),
                    ])
                    ->action(function (MachineUnitProposal $record, array $data) {
                        $product = $data['product_id']
                            ? Product::find($data['product_id'])
                            : Product::create([
                                'tenant_id' => $record->tenant_id,
                                'type' => $data['product_type'],
                                'sku' => 'EUREKA-'.$record->eureka_article_id,
                                'name' => $record->model_name ?: $record->eureka_article_code,
                                'gestionale_code' => $record->eureka_article_id,
                            ]);

                        $machineUnit = MachineUnit::create([
                            'tenant_id' => $record->tenant_id,
                            'product_id' => $product->id,
                            'serial_number' => $record->serial_number,
                            'model_name' => $record->model_name,
                            'owner_name' => $data['owner_name'],
                        ]);
                        $machineUnit->moveTo($record->customer);

                        $record->delete();

                        Notification::make()->title('Macchina creata')->success()->send();
                    }),
                Tables\Actions\Action::make('scarta_macchina_nuova')
                    ->label('Scarta')
                    ->icon('heroicon-o-x-mark')
                    ->color('gray')
                    ->requiresConfirmation()
                    ->modalDescription('Nasconde solo questa proposta: non verra\' riproposta al prossimo sync per questa matricola.')
                    ->action(fn (MachineUnitProposal $record) => $record->update(['dismissed_at' => now()])),
            ])
            ->paginated([10, 25, 50]);
    }
}
