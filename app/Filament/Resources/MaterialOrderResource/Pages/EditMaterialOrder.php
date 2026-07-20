<?php

namespace App\Filament\Resources\MaterialOrderResource\Pages;

use App\Filament\Resources\MaterialOrderResource;
use App\Filament\Resources\MaterialResource;
use App\Models\Material;
use App\Models\MaterialOrder;
use Filament\Actions;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;

class EditMaterialOrder extends EditRecord
{
    protected static string $resource = MaterialOrderResource::class;

    protected function getHeaderActions(): array
    {
        return [
            $this->addMaterialsAction(),
            Actions\Action::make('pdf')
                ->label('Genera PDF ordine')
                ->icon('heroicon-o-document-arrow-down')
                ->action(fn () => MaterialOrderResource::streamPdf($this->record)),
            Actions\Action::make('excel')
                ->label('Esporta Excel')
                ->icon('heroicon-o-table-cells')
                ->action(fn () => MaterialOrderResource::streamExcel($this->record)),
            Actions\DeleteAction::make(),
        ];
    }

    /**
     * Modale col picker per categoria (MaterialOrderResource::addMaterialsFormSchema):
     * scrive subito su DB (addSelectedMaterialsToOrder) invece che sullo stato
     * del form di questa pagina, cosi' un refresh non fa perdere niente. Resta
     * aperta finche' non si chiude, per aggiungere piu' categorie di fila.
     */
    protected function addMaterialsAction(): Actions\Action
    {
        return Actions\Action::make('addMaterials')
            ->label('Aggiungi materiali')
            ->icon('heroicon-o-plus-circle')
            ->modalWidth('4xl')
            ->modalHeading('Aggiungi materiali all\'ordine')
            ->modalSubmitAction(false)
            ->modalCancelActionLabel('Chiudi')
            ->form([
                ...MaterialOrderResource::addMaterialsFormSchema($this->record),
                Forms\Components\Actions::make([
                    Forms\Components\Actions\Action::make('addFromCategory')
                        ->label('Aggiungi selezionati')
                        ->icon('heroicon-o-plus')
                        ->button()
                        ->visible(fn (Forms\Get $get) => filled($get('code_search')) || filled($get('type_filter')))
                        ->action(function (Forms\Get $get, Forms\Set $set) {
                            /** @var MaterialOrder $order */
                            $order = $this->record;

                            $count = MaterialOrderResource::addSelectedMaterialsToOrder(
                                $order,
                                $get('category_quantities') ?? []
                            );

                            if ($count === 0) {
                                return;
                            }

                            // La tabella righe vive in un RelationManager separato
                            // (fuori dal ciclo di questo form): senza l'evento
                            // resterebbe ferma alla situazione di quando si e'
                            // aperta la modale.
                            $this->dispatch('materialOrderItemsUpdated');

                            // Azzerate dopo l'aggiunta: il campo quantita' qui
                            // significa "quante aggiungerne ORA", non "quantita'
                            // attuale nell'ordine" (quella si corregge dalla
                            // tabella sotto). Lasciarle valorizzate faceva
                            // sommare di nuovo la stessa quantita' se si
                            // riapriva/correggeva il numero e si ricliccava.
                            $set('category_quantities', []);

                            Notification::make()
                                ->title($count.' material'.($count === 1 ? 'e aggiunto' : 'i aggiunti')." all'ordine")
                                ->success()
                                ->send();
                        }),
                    $this->createMaterialAction(),
                ]),
            ]);
    }

    /**
     * Scorciatoia per chi puo' gestire il catalogo (oggi: admin): crea un
     * materiale nuovo senza uscire dall'ordine, e lo aggiunge subito.
     * Nascosta a chi non ha create_material (es. dipendente), che continua a
     * scegliere solo fra i materiali gia' a catalogo.
     */
    protected function createMaterialAction(): Forms\Components\Actions\Action
    {
        return Forms\Components\Actions\Action::make('createMaterial')
            ->label('Materiale non a catalogo? Crealo')
            ->icon('heroicon-o-plus-circle')
            ->color('gray')
            ->visible(fn () => auth()->user()?->can('create', Material::class) ?? false)
            ->modalHeading('Nuovo materiale')
            ->modalWidth('2xl')
            ->form([
                ...MaterialResource::formFields(),
                Forms\Components\TextInput::make('quantity')
                    ->label('Quantità da aggiungere all\'ordine')
                    ->numeric()
                    ->minValue(1)
                    ->default(1)
                    ->required(),
            ])
            ->action(function (array $data) {
                /** @var MaterialOrder $order */
                $order = $this->record;

                $quantity = (int) ($data['quantity'] ?? 1);
                unset($data['quantity']);

                $material = Material::create($data);

                MaterialOrderResource::addSelectedMaterialsToOrder($order, [$material->id => $quantity]);

                $this->dispatch('materialOrderItemsUpdated');

                Notification::make()
                    ->title("Materiale {$material->code} creato e aggiunto all'ordine")
                    ->success()
                    ->send();
            });
    }
}
