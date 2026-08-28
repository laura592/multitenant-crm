<?php

namespace App\Filament\Actions;

use App\Models\Product;
use App\Models\Quote;
use Filament\Actions\Action;
use Filament\Forms;
use Filament\Forms\Components\Wizard\Step;
use Filament\Notifications\Notification;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Guida alla scelta del sistema di conteggio (capitolo "Sistemi di conteggio"
 * del listino Franke): alloggiamento -> lettore di carte -> variante, invece
 * di cercare a mano fra gli 83 articoli della famiglia.
 *
 * NON e' un configuratore a supplementi come ConfigureMachineAction. I delta
 * del listino sono regolari (VIP-1 +355, foro lettore +138, gettoniera +692,
 * cambiamonete +2290) e comporli sarebbe tecnicamente facile, ma il prezzo
 * non e' il problema: Franke ordina per CODICE, e ogni combinazione ha il
 * suo numero d'ordine (AC200 + VIP-1 + G13 = 560.0543.637, un codice solo).
 * Un preventivo composto da riga base + supplementi tornerebbe come totale e
 * sarebbe inordinabile. Qui quindi si filtra il catalogo e si finisce sempre
 * su UN prodotto reale, col suo codice e il suo prezzo di listino.
 *
 * Interruttore a chiave e gettoni sono invece voci separate anche nel listino
 * ("Opzioni per l'alloggiamento conteggio"), quindi restano righe a parte.
 */
class ConfiguraConteggioAction
{
    /** Come il listino distingue gli alloggiamenti: sigla -> etichetta. */
    protected const ALLOGGIAMENTI = [
        'AC200' => 'AC200 — alloggiamento standard (non disponibile per A300)',
        'AC125' => 'AC125 — alloggiamento compatto',
        'SU03 CL' => 'SU03 CL — dentro l\'unità di raffreddamento SU03 EC',
    ];

    protected const SKU_INTERRUTTORE = 'OPT-CHIAVE-VENDITA';

    protected const SKU_GETTONI = 'OPT-GETTONI-100';

    public static function make(): Action
    {
        return Action::make('configuraConteggio')
            ->label('Configura sistema di conteggio')
            ->icon('heroicon-o-credit-card')
            ->color('gray')
            ->modalWidth('3xl')
            ->modalHeading('Configura sistema di conteggio')
            ->steps(static::buildSteps())
            ->action(function (array $data, Quote $record, $livewire) {
                static::createQuoteProducts($record, $data);
                $livewire->dispatch('quoteProductsUpdated');
            });
    }

    /**
     * @return array<int, Step>
     */
    protected static function buildSteps(): array
    {
        return [
            Step::make('Alloggiamento')
                ->description('Dove va montato il sistema')
                ->schema([
                    Forms\Components\Radio::make('alloggiamento')
                        ->label('Alloggiamento')
                        ->options(self::ALLOGGIAMENTI)
                        ->required()
                        ->live()
                        ->afterStateUpdated(fn (Forms\Set $set) => $set('conteggio_product_id', null)),
                ]),
            Step::make('Lettore di carte')
                ->description('Il foro frontale e\' sagomato sul modello di lettore')
                ->schema([
                    Forms\Components\Radio::make('con_lettore')
                        ->label('Il cliente monta un lettore di carte?')
                        ->options([
                            'no' => 'No — solo alloggiamento, senza foro frontale',
                            'si' => 'Sì — alloggiamento predisposto per un lettore',
                        ])
                        ->default('no')
                        ->required()
                        ->live()
                        ->afterStateUpdated(fn (Forms\Set $set) => $set('conteggio_product_id', null))
                        // Il lettore in se' non lo vende Franke: il listino
                        // dice "fornito e installato dal cliente/service
                        // partner", qui si sceglie solo l'alloggiamento
                        // giusto per quel modello.
                        ->helperText('Il lettore non e\' compreso: lo fornisce il cliente o il service partner. Qui si sceglie l\'alloggiamento con il foro giusto.'),
                ]),
            Step::make('Variante')
                ->description('Interfaccia e gestione contanti')
                ->schema([
                    Forms\Components\Select::make('conteggio_product_id')
                        ->label('Sistema di conteggio')
                        ->options(fn (Forms\Get $get) => static::candidati($get('alloggiamento'), $get('con_lettore') === 'si')
                            ->mapWithKeys(fn (Product $p) => [$p->id => static::etichetta($p)]))
                        ->required()
                        ->searchable()
                        ->helperText('Le voci vengono dal listino Franke: codice e prezzo sono quelli, non calcolati.'),
                ]),
            Step::make('Opzioni')
                ->description('Voci a parte nel listino')
                ->schema([
                    Forms\Components\Toggle::make('interruttore_chiave')
                        ->label('Interruttore a chiave (vendita libera e di prova)')
                        ->helperText(fn () => static::prezzoDi(self::SKU_INTERRUTTORE)),
                    Forms\Components\TextInput::make('gettoni')
                        ->label('Confezioni di gettoni da 100 pz.')
                        ->numeric()
                        ->minValue(0)
                        ->default(0)
                        ->helperText(fn () => static::prezzoDi(self::SKU_GETTONI)),
                ]),
        ];
    }

    /**
     * I prodotti del catalogo compatibili con le scelte fatte. Il filtro e'
     * sul nome perche' e' li' che il listino codifica la combinazione
     * (alloggiamento, interfaccia, contanti) e i nomi seguono lo stesso
     * schema del listino; il passo finale mostra comunque l'elenco completo
     * con prezzo, quindi una scelta sbagliata si vede prima di confermare.
     */
    public static function candidati(?string $alloggiamento, bool $conLettore): Collection
    {
        if (blank($alloggiamento)) {
            return collect();
        }

        return Product::query()
            ->when(
                $conLettore,
                // Con lettore: le righe per marca/modello, che il listino
                // elenca una per ogni lettore supportato. L'alloggiamento e'
                // in coda fra parentesi: "Nayax [Onyx] con VIP-1 (AC200)".
                fn (Builder $q) => $q
                    ->where('name', 'not like', 'Alloggiamento conteggio%')
                    ->where('name', 'like', '%('.$alloggiamento.')%')
                    // Il codice d'ordine Franke tiene fuori tutto il resto
                    // del catalogo; i "PM kit" lo condividono ma non sono
                    // sistemi di conteggio.
                    ->where('sku', 'like', '560.%')
                    ->where('name', 'not like', 'PM kit%'),
                // Senza lettore: gli alloggiamenti nudi, dove la sigla viene
                // subito dopo "Alloggiamento conteggio". Qui il filtro NON
                // puo' essere sul codice: quattro di questi sono a catalogo
                // da prima, con uno sku nostro (AC200-VIP1) invece del numero
                // d'ordine Franke. FrankeAccountingHousingsSeeder uniforma i
                // nomi proprio perche' questo filtro regga.
                fn (Builder $q) => $q->where('name', 'like', 'Alloggiamento conteggio '.$alloggiamento.'%'),
            )
            ->orderBy('name')
            ->get();
    }

    public static function etichetta(Product $product): string
    {
        $prezzo = $product->getCurrentPrice()?->price;

        return $product->name
            .($prezzo ? ' — '.number_format((float) $prezzo, 2, ',', '.').' €' : '')
            .' ('.$product->sku.')';
    }

    protected static function prezzoDi(string $sku): ?string
    {
        $prezzo = Product::query()->where('sku', $sku)->first()?->getCurrentPrice()?->price;

        return $prezzo ? number_format((float) $prezzo, 2, ',', '.').' € cad.' : null;
    }

    protected static function createQuoteProducts(Quote $quote, array $data): void
    {
        $sistema = Product::find($data['conteggio_product_id'] ?? null);

        if (! $sistema) {
            Notification::make()->title('Nessun sistema di conteggio selezionato')->danger()->send();

            return;
        }

        $riga = $quote->quoteProducts()->create([
            'product_id' => $sistema->id,
            'quantity' => 1,
            'price' => $sistema->getCurrentPrice()?->price ?? 0,
            'discount' => 0,
            'tax' => 22,
        ]);

        // Le opzioni restano appese alla riga del sistema (come le opzioni
        // macchina al loro apparecchio base): spostare o cancellare il
        // sistema si porta dietro anche loro.
        if ($data['interruttore_chiave'] ?? false) {
            static::aggiungiOpzione($quote, $riga->id, self::SKU_INTERRUTTORE, 1);
        }

        $gettoni = (int) ($data['gettoni'] ?? 0);
        if ($gettoni > 0) {
            static::aggiungiOpzione($quote, $riga->id, self::SKU_GETTONI, $gettoni);
        }

        $quote->updateTotal();

        Notification::make()->title('Sistema di conteggio aggiunto al preventivo')->success()->send();
    }

    protected static function aggiungiOpzione(Quote $quote, string $parentId, string $sku, int $quantity): void
    {
        $product = Product::query()->where('sku', $sku)->first();

        if (! $product) {
            return;
        }

        $quote->quoteProducts()->create([
            'product_id' => $product->id,
            'parent_quote_product_id' => $parentId,
            'quantity' => $quantity,
            'price' => $product->getCurrentPrice()?->price ?? 0,
            'discount' => 0,
            'tax' => 22,
        ]);
    }
}
