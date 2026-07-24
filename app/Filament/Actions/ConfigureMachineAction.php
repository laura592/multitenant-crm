<?php

namespace App\Filament\Actions;

use App\Models\Product;
use App\Models\ProductFamily;
use App\Models\ProductOptionSlot;
use App\Models\Quote;
use App\Models\QuoteProduct;
use Filament\Actions\Action;
use Filament\Forms;
use Filament\Forms\Components\Wizard\Step;
use Filament\Notifications\Notification;
use Filament\Tables\Actions\Action as TableAction;
use Illuminate\Support\Collection;

/**
 * Wizard di configurazione macchina (files/PRD.md §3.1, files/DATABASE-SCHEMA.md):
 * famiglia -> variante base -> uno step per ciascuno slot noto (unità di
 * raffreddamento, macinacaffè, dosatori, vapore, ...) -> riepilogo. Ogni
 * step e' nascosto automaticamente se la macchina scelta non ha uno slot
 * di quel nome - "Altre opzioni" fa da rete di sicurezza per qualunque slot
 * non tra quelli noti, cosi' nessuno slot sparisce mai anche se in futuro
 * comparira' un nome nuovo. La lista di step resta STATICA tra un render e
 * l'altro (solo contenuto/visibilita' sono reattivi): un closure sull'intero
 * array di step spezza l'idratazione Livewire dei componenti.
 *
 * Uno slot obbligatorio con un solo componente ammesso e' auto-incluso senza
 * scelta dell'utente (equivalente al vecchio "compatibilita' required");
 * uno slot obbligatorio con piu' componenti ammessi richiede comunque una
 * scelta dell'utente (min 1), a differenza del vecchio sistema che non
 * supportava "obbligatorio ma a scelta".
 *
 * Prezzo sempre visibile accanto a ogni opzione (nome variante inclusa),
 * per poter decidere consapevolmente durante la selezione.
 */
class ConfigureMachineAction
{
    /** slot_name -> etichetta step */
    protected const KNOWN_SLOTS = [
        'cooling_unit' => 'Unità di raffreddamento',
        'grinder' => 'Macinacaffè',
        'powder' => 'Dosatori polvere',
        'steam' => 'Lancia vapore',
        'addon' => 'Accessori aggiuntivi',
        'color' => 'Colore/Estetica',
        'power' => 'Alimentazione',
        'license' => 'Licenze e servizi',
    ];

    public static function make(): Action
    {
        return Action::make('configureMachine')
            ->label('Configura macchina')
            ->icon('heroicon-o-cog-6-tooth')
            ->modalWidth('3xl')
            ->modalHeading('Configura macchina')
            ->steps(static::buildSteps())
            ->action(function (array $data, Quote $record, $livewire) {
                static::createQuoteProducts($record, $data);

                // Le righe sono create scrivendo sul modello, fuori dal ciclo
                // form/tabella del RelationManager "Righe preventivo": senza
                // questo evento il tab resta con lo stato di quando e' stato
                // aperto finche' non si interagisce di nuovo con la tabella
                // (bug reale segnalato: "se ricarico la pagina" le righe compaiono).
                $livewire->dispatch('quoteProductsUpdated');
            });
    }

    /**
     * Stessa configurazione di step di make(), ma per modificare una
     * macchina GIA' presente nel preventivo invece di aggiungerne una nuova:
     * bug reale segnalato ("se ho inserito una macchina e poi voglio
     * modificarne la configurazione devo poterlo fare") - prima l'unico modo
     * era cancellare/modificare le righe a mano nel RelationManager, perdendo
     * tutta l'assistenza del wizard (step per slot, vincoli, riepilogo prezzi).
     * Azione di riga nel RelationManager "Righe preventivo", quindi opera su
     * un QuoteProduct (la riga macchina), non sul Quote.
     */
    public static function makeEdit(): TableAction
    {
        return TableAction::make('editMachineConfiguration')
            ->label('Modifica configurazione')
            ->icon('heroicon-o-pencil-square')
            ->modalWidth('3xl')
            ->modalHeading('Modifica configurazione macchina')
            ->steps(static::buildSteps())
            ->visible(fn (QuoteProduct $record) => $record->isBase() && $record->product?->isMachine())
            ->fillForm(fn (QuoteProduct $record) => static::fillFormForEdit($record))
            ->action(function (array $data, QuoteProduct $record) {
                static::updateQuoteProducts($record, $data);
            });
    }

    /**
     * @return array<int, Step>
     */
    protected static function buildSteps(): array
    {
        $steps = [
            Step::make('Macchina')
                ->schema([
                    Forms\Components\Select::make('product_family_id')
                        ->label('Famiglia')
                        ->options(fn () => ProductFamily::query()->orderBy('name')->pluck('name', 'id'))
                        ->live()
                        ->afterStateUpdated(fn (Forms\Set $set) => $set('machine_product_id', null))
                        ->required(),
                    Forms\Components\Select::make('machine_product_id')
                        ->label('Variante apparecchio base')
                        ->options(fn (Forms\Get $get) => Product::query()
                            ->where('product_family_id', $get('product_family_id'))
                            ->where('type', Product::TYPE_MACHINE)
                            ->get()
                            ->mapWithKeys(fn (Product $p) => [$p->id => static::formatOptionLabel($p)]))
                        ->live()
                        ->required()
                        ->disabled(fn (Forms\Get $get) => blank($get('product_family_id'))),
                ]),
            Step::make('Incluse automaticamente')
                ->visible(fn (Forms\Get $get) => static::autoIncludedSlots($get)->isNotEmpty())
                ->schema([
                    Forms\Components\Placeholder::make('required_info')
                        ->label('Obbligatorie per questa variante')
                        ->content(fn (Forms\Get $get) => static::autoIncludedSlots($get)
                            ->map(fn (ProductOptionSlot $slot) => static::formatOptionLabel($slot->items->first()->component))
                            ->implode(', ')),
                ]),
        ];

        foreach (self::KNOWN_SLOTS as $slotName => $stepLabel) {
            $steps[] = Step::make($stepLabel)
                ->visible(fn (Forms\Get $get) => static::choosableSlots($get, $slotName)->isNotEmpty())
                ->schema(fn (Forms\Get $get) => static::slotStepSchema($get, $slotName));
        }

        // Rete di sicurezza: qualunque slot non elencato sopra (nome
        // sconosciuto/futuro) finisce comunque qui, mai perso silenziosamente.
        $steps[] = Step::make('Altre opzioni')
            ->visible(fn (Forms\Get $get) => static::choosableSlots($get, null)->isNotEmpty())
            ->schema(fn (Forms\Get $get) => static::slotStepSchema($get, null));

        // Bug reale segnalato: il riepilogo era un testo fisso, senza
        // elencare cosa si sta per aggiungere ne' un totale - si confermava
        // "alla cieca". Ora mostra ogni riga (macchina, incluse
        // automaticamente, opzioni scelte) col prezzo e un totale finale.
        $steps[] = Step::make('Riepilogo')
            ->schema([
                Forms\Components\Placeholder::make('summary')
                    ->hiddenLabel()
                    ->content(fn (Forms\Get $get) => static::renderSummary($get)),
            ]);

        return $steps;
    }

    protected static function currentMachine(Forms\Get $get): ?Product
    {
        $machineId = $get('machine_product_id');

        return $machineId ? Product::find($machineId) : null;
    }

    /**
     * Righe (macchina + incluse automaticamente + opzioni scelte finora) con
     * prezzo e totale, per lo step "Riepilogo" - stessa selezione che
     * verrebbe creata confermando (resolveSelection), cosi' il riepilogo non
     * puo' mai mostrare qualcosa di diverso da quello che poi viene salvato.
     */
    protected static function renderSummary(Forms\Get $get): \Illuminate\Support\HtmlString
    {
        $machine = static::currentMachine($get);

        if (! $machine) {
            return new \Illuminate\Support\HtmlString('<p class="text-sm text-gray-500">Nessuna macchina selezionata.</p>');
        }

        $resolved = static::resolveSelection($machine, fn (string $key) => $get($key));

        $selectedIds = $resolved['autoIncludedIds']
            ->merge(collect($resolved['selectedBySlot'])->flatten())
            ->filter()->unique()->values();

        $options = Product::whereIn('id', $selectedIds)->get()->keyBy('id');

        $rows = collect([['label' => $machine->name, 'price' => (float) ($machine->getCurrentPrice()?->price ?? 0)]])
            ->concat($selectedIds->map(fn ($id) => [
                'label' => $options->get($id)?->name ?? '—',
                'price' => (float) ($options->get($id)?->getCurrentPrice()?->price ?? 0),
            ]));

        return new \Illuminate\Support\HtmlString(view('filament.partials.configure-machine-summary', [
            'rows' => $rows,
            'total' => $rows->sum('price'),
        ])->render());
    }

    protected static function allSlots(Forms\Get $get): Collection
    {
        $machine = static::currentMachine($get);

        if (! $machine) {
            return collect();
        }

        return $machine->slots()->with('items.component')->get();
    }

    /**
     * Slot obbligatori con un solo componente ammesso: nessuna scelta per
     * l'utente, aggiunti automaticamente (equivalente al vecchio
     * "constraint_type = required").
     */
    protected static function autoIncludedSlots(Forms\Get $get): Collection
    {
        return static::allSlots($get)->filter(
            fn (ProductOptionSlot $slot) => $slot->required && $slot->items->count() === 1
        );
    }

    /**
     * Slot che richiedono una scelta dell'utente (non auto-inclusi) per un
     * dato slot_name. $slotName = null -> rete di sicurezza: tutti gli slot
     * con nome NON tra quelli noti (KNOWN_SLOTS).
     */
    protected static function choosableSlots(Forms\Get $get, ?string $slotName): Collection
    {
        $known = array_keys(self::KNOWN_SLOTS);

        return static::allSlots($get)
            ->reject(fn (ProductOptionSlot $slot) => $slot->required && $slot->items->count() === 1)
            // Uno slot rimasto senza item (es. "other" svuotato da
            // products:recategorize-options) non deve comparire come step
            // vuoto.
            ->reject(fn (ProductOptionSlot $slot) => $slot->items->isEmpty())
            ->filter(function (ProductOptionSlot $slot) use ($slotName, $known) {
                return $slotName === null ? ! in_array($slot->slot_name, $known) : $slot->slot_name === $slotName;
            });
    }

    /**
     * @return array<int, Forms\Components\Component>
     */
    protected static function slotStepSchema(Forms\Get $get, ?string $slotName): array
    {
        $slots = static::choosableSlots($get, $slotName);

        if ($slots->isEmpty()) {
            return [];
        }

        // Un gruppo "sicurezza" (null) puo' in realta' raggruppare piu'
        // slot diversi: un field per ciascuno slot realmente presente.
        $schema = [];

        foreach ($slots as $slot) {
            $label = $slotName === null ? $slot->label : '';

            if ($slot->isSingleChoice()) {
                $options = $slot->items->mapWithKeys(
                    fn ($item) => [$item->component_product_id => static::formatOptionLabel($item->component)]
                );
                $schema[] = Forms\Components\Radio::make("slot_{$slot->id}")->label($label)->options($options)->required($slot->required);

                continue;
            }

            // Bug reale segnalato: un unico CheckboxList con wire:model
            // condiviso su piu' checkbox, dentro un wizard annidato in una
            // modale action, si comporta in modo rotto lato client (un click
            // su UNA opzione le selezionava TUTTE). Un campo booleano
            // indipendente per opzione evita del tutto il problema - e in
            // piu' permette di raggruppare le opzioni per categoria come nei
            // listini (richiesto a parte). required/min/max non sono piu'
            // validati inline dal campo ma nell'action stessa, vedi
            // findSlotQuantityViolation().
            $itemsByCategory = $slot->items->load('component.category')->groupBy(
                fn ($item) => $item->component->category?->name ?? 'Altro'
            );

            foreach ($itemsByCategory as $categoryName => $items) {
                $checkboxes = $items->map(fn ($item) => Forms\Components\Checkbox::make("slot_{$slot->id}__{$item->component_product_id}")
                    ->label(static::formatOptionLabel($item->component)))->all();

                // Con una sola categoria non serve un riquadro extra: il
                // titolo dello slot (o l'etichetta gia' mostrata dallo step)
                // basta.
                if ($itemsByCategory->count() === 1) {
                    if ($label) {
                        $schema[] = Forms\Components\Placeholder::make("slot_{$slot->id}_label")->label($label)->content('');
                    }

                    $schema[] = Forms\Components\Grid::make(2)->schema($checkboxes);

                    continue;
                }

                $schema[] = Forms\Components\Fieldset::make($categoryName)->schema($checkboxes)->columns(2);
            }
        }

        return $schema;
    }

    /**
     * Verifica required/min/max PER SLOT sulle opzioni multi-scelta
     * (checkbox individuali, non piu' un CheckboxList validabile inline).
     * Ritorna un messaggio d'errore se un vincolo e' violato, altrimenti null.
     *
     * @param  array<string, array<int, string>>  $selectedBySlot  slot_id => id componenti selezionati
     */
    protected static function findSlotQuantityViolation(Product $machine, array $selectedBySlot): ?string
    {
        $slots = $machine->slots()->with('items')->get()
            ->reject(fn (ProductOptionSlot $slot) => $slot->required && $slot->items->count() === 1)
            ->reject(fn (ProductOptionSlot $slot) => $slot->isSingleChoice());

        foreach ($slots as $slot) {
            $count = count($selectedBySlot[(string) $slot->id] ?? []);

            if ($slot->required && $count < max(1, $slot->min_qty)) {
                return "«{$slot->label}»: seleziona almeno ".max(1, $slot->min_qty).' opzione/i.';
            }

            if ($slot->max_qty !== null && $count > $slot->max_qty) {
                return "«{$slot->label}»: puoi selezionare al massimo {$slot->max_qty} opzione/i.";
            }
        }

        return null;
    }

    /**
     * "Nome prodotto — 1.234,56 €", cosi' il prezzo e' visibile mentre si
     * sceglie, non solo nel riepilogo finale.
     */
    protected static function formatOptionLabel(Product $product): string
    {
        $price = $product->getCurrentPrice()?->price;

        if ($price === null) {
            return $product->name;
        }

        return $product->name.' — '.number_format((float) $price, 2, ',', '.').' €';
    }

    /**
     * Ricostruisce cosa e' effettivamente selezionato in un dato momento
     * (auto-incluso + scelte utente), leggendo campo per campo dai singoli
     * slot invece di iterare l'intero array dati. Usato sia dal riepilogo
     * live (mentre si compila il wizard) sia dalla validazione/creazione
     * righe finale, cosi' i due punti non possono disallinearsi.
     *
     * @param  callable(string): mixed  $getter  legge lo stato di un campo per nome (Forms\Get o array $data)
     * @return array{autoIncludedIds: Collection, selectedBySlot: array<string, array<int, string>>}
     */
    protected static function resolveSelection(Product $machine, callable $getter): array
    {
        $slots = $machine->slots()->with('items.component')->get();

        $autoIncludedIds = $slots
            ->filter(fn (ProductOptionSlot $slot) => $slot->required && $slot->items->count() === 1)
            ->flatMap(fn (ProductOptionSlot $slot) => $slot->items->pluck('component_product_id'));

        $selectedBySlot = [];

        foreach ($slots as $slot) {
            if ($slot->required && $slot->items->count() === 1) {
                continue;
            }

            if ($slot->isSingleChoice()) {
                if ($value = $getter("slot_{$slot->id}")) {
                    $selectedBySlot[(string) $slot->id] = [$value];
                }

                continue;
            }

            foreach ($slot->items as $item) {
                if ($getter("slot_{$slot->id}__{$item->component_product_id}")) {
                    $selectedBySlot[(string) $slot->id][] = $item->component_product_id;
                }
            }
        }

        return ['autoIncludedIds' => $autoIncludedIds, 'selectedBySlot' => $selectedBySlot];
    }

    protected static function createQuoteProducts(Quote $quote, array $data): void
    {
        $machine = Product::find($data['machine_product_id'] ?? null);

        if (! $machine) {
            Notification::make()->title('Nessuna macchina selezionata')->danger()->send();

            return;
        }

        $selectedIds = static::resolveAndValidateSelection($machine, $data);

        if ($selectedIds === null) {
            return;
        }

        $baseLine = $quote->quoteProducts()->create([
            'product_id' => $machine->id,
            'quantity' => 1,
            'price' => $machine->getCurrentPrice()?->price ?? 0,
            'discount' => 0,
            'tax' => 22,
        ]);

        Product::whereIn('id', $selectedIds)->get()->each(function (Product $product) use ($quote, $baseLine) {
            $quote->quoteProducts()->create([
                'product_id' => $product->id,
                'parent_quote_product_id' => $baseLine->id,
                'quantity' => 1,
                'price' => $product->getCurrentPrice()?->price ?? 0,
                'discount' => 0,
                'tax' => 22,
            ]);
        });

        $quote->updateTotal();

        Notification::make()->title('Macchina configurata e aggiunta al preventivo')->success()->send();
    }

    /**
     * Aggiorna la configurazione di una macchina gia' presente nel
     * preventivo: sostituisce interamente le opzioni figlie della riga con
     * la nuova selezione (piu' semplice e coerente di un merge/diff, dato
     * che il riepilogo del wizard mostra sempre la selezione risolta da
     * zero). Usata da makeEdit(), speculare a createQuoteProducts() ma opera
     * su un QuoteProduct (riga macchina) gia' esistente invece di crearne
     * una nuova sul Quote.
     */
    protected static function updateQuoteProducts(QuoteProduct $record, array $data): void
    {
        $machine = Product::find($data['machine_product_id'] ?? null);

        if (! $machine) {
            Notification::make()->title('Nessuna macchina selezionata')->danger()->send();

            return;
        }

        $selectedIds = static::resolveAndValidateSelection($machine, $data);

        if ($selectedIds === null) {
            return;
        }

        $quote = $record->quote;

        $record->update([
            'product_id' => $machine->id,
            'price' => $machine->getCurrentPrice()?->price ?? 0,
        ]);

        $record->options()->delete();

        Product::whereIn('id', $selectedIds)->get()->each(function (Product $product) use ($quote, $record) {
            $quote->quoteProducts()->create([
                'product_id' => $product->id,
                'parent_quote_product_id' => $record->id,
                'quantity' => 1,
                'price' => $product->getCurrentPrice()?->price ?? 0,
                'discount' => 0,
                'tax' => 22,
            ]);
        });

        $quote->updateTotal();

        Notification::make()->title('Configurazione aggiornata')->success()->send();
    }

    /**
     * Risolve la selezione del wizard e verifica i vincoli (min/max per
     * slot, requires/excludes). Ritorna gli id selezionati, o null se un
     * vincolo e' violato (la Notification d'errore e' gia' stata inviata) -
     * condivisa da createQuoteProducts() e updateQuoteProducts() cosi' le
     * due azioni non possono validare in modo diverso.
     */
    protected static function resolveAndValidateSelection(Product $machine, array $data): ?Collection
    {
        $resolved = static::resolveSelection($machine, fn (string $key) => $data[$key] ?? null);

        if ($violation = static::findSlotQuantityViolation($machine, $resolved['selectedBySlot'])) {
            Notification::make()->title($violation)->danger()->send();

            return null;
        }

        $selectedIds = $resolved['autoIncludedIds']
            ->merge(collect($resolved['selectedBySlot'])->flatten())
            ->filter()->unique()->values();

        if ($violation = static::findConstraintViolation($selectedIds)) {
            Notification::make()->title($violation)->danger()->send();

            return null;
        }

        return $selectedIds;
    }

    /**
     * Ricostruisce lo stato iniziale del wizard dalla configurazione gia'
     * salvata (riga macchina + sue opzioni figlie), cosi' "Modifica
     * configurazione" riapre il wizard con le scelte attuali gia' spuntate
     * invece di far ripartire l'utente da zero.
     */
    protected static function fillFormForEdit(QuoteProduct $record): array
    {
        $machine = $record->product;

        $data = [
            'product_family_id' => $machine->product_family_id,
            'machine_product_id' => $machine->id,
        ];

        $selectedIds = $record->options()->pluck('product_id');

        foreach ($machine->slots()->with('items')->get() as $slot) {
            if ($slot->required && $slot->items->count() === 1) {
                continue;
            }

            if ($slot->isSingleChoice()) {
                $selected = $slot->items->pluck('component_product_id')->intersect($selectedIds)->first();

                if ($selected) {
                    $data["slot_{$slot->id}"] = $selected;
                }

                continue;
            }

            foreach ($slot->items as $item) {
                $data["slot_{$slot->id}__{$item->component_product_id}"] = $selectedIds->contains($item->component_product_id);
            }
        }

        return $data;
    }

    /**
     * Verifica i vincoli requires/excludes (files/DATABASE-SCHEMA.md).
     * Ritorna un messaggio d'errore se un vincolo è violato, altrimenti null.
     */
    protected static function findConstraintViolation(Collection $selectedIds): ?string
    {
        $products = Product::whereIn('id', $selectedIds)->get()->keyBy('id');

        foreach ($products as $product) {
            foreach ($product->requiredProducts as $requirement) {
                if (! $selectedIds->contains($requirement->id)) {
                    return "«{$product->name}» richiede anche «{$requirement->name}», non selezionato.";
                }
            }

            foreach ($product->excludedProducts as $exclusion) {
                if ($selectedIds->contains($exclusion->id)) {
                    return "«{$product->name}» non è compatibile con «{$exclusion->name}».";
                }
            }
        }

        return null;
    }
}
