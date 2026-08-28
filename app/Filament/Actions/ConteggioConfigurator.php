<?php

namespace App\Filament\Actions;

use App\Models\Product;
use App\Models\Quote;
use Filament\Forms;
use Filament\Forms\Components\Wizard\Step;
use Filament\Notifications\Notification;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Lo step "Sistema di conteggio" dentro il wizard Configura macchina
 * (ConfigureMachineAction): alloggiamento -> lettore di carte -> variante,
 * invece di cercare a mano fra gli 83 articoli della famiglia.
 *
 * Sta dentro quel wizard e non in un bottone suo perche' un sistema di
 * conteggio si vende insieme a una macchina, e perche' li' si sa gia' QUALE
 * macchina: e' cosi' che il vincolo "AC200 non possibile per A300" diventa
 * un'opzione disabilitata invece di una nota da ricordare.
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
class ConteggioConfigurator
{
    /** Come il listino distingue gli alloggiamenti: sigla -> etichetta. */
    protected const ALLOGGIAMENTI = [
        'AC200' => 'AC200 — alloggiamento standard (non disponibile per A300)',
        'AC125' => 'AC125 — alloggiamento compatto',
        'SU03 CL' => 'SU03 CL — dentro l\'unità di raffreddamento SU03 EC',
    ];

    protected const SKU_INTERRUTTORE = 'OPT-CHIAVE-VENDITA';

    protected const SKU_GETTONI = 'OPT-GETTONI-100';

    protected const SKU_SU03_EC = 'SU03-EC';

    /**
     * Righe di preventivo create qui e non dentro
     * ConfigureMachineAction::createQuoteProducts(): il sistema di conteggio
     * e' un articolo Franke a se', ordinato insieme alla macchina ma non una
     * sua opzione. Restano quindi righe di primo livello, che "Modifica
     * configurazione" non cancella insieme alle opzioni macchina.
     */
    public static function step(): Step
    {
        return Step::make('Sistema di conteggio')
            ->description('Facoltativo: carte, gettoniera o cambiamonete')
            ->schema([
                Forms\Components\Radio::make('alloggiamento')
                    ->label('Alloggiamento')
                    ->options(self::ALLOGGIAMENTI)
                    // "Non possibile per A300" e' una nota del listino, non un
                    // consiglio: con una A300 selezionata l'AC200 non si puo'
                    // proprio scegliere.
                    ->disableOptionWhen(fn (string $value, Forms\Get $get) => $value === 'AC200' && static::eUnaA300($get))
                    // Il contante (gettoniera G13 e cambiamonete Gryphon) nel
                    // listino ha un prezzo SOLO nella colonna AC200: tutte e
                    // quindici le righe che li citano hanno "-" su AC125 e
                    // SU03 CL. Su A300, dove l'AC200 non e' possibile, si
                    // possono quindi fare solo le carte.
                    ->helperText(fn (Forms\Get $get) => static::eUnaA300($get)
                        ? 'Su A300 il listino esclude l\'AC200: restano AC125 e SU03 CL. Gettoniera e cambiamonete esistono solo su AC200, quindi qui si possono fare solo i pagamenti con carta.'
                        : 'Lascia vuoto se questa macchina non ha un sistema di conteggio.')
                    ->live()
                    ->afterStateUpdated(fn (Forms\Set $set) => $set('conteggio_product_id', null)),
                Forms\Components\Radio::make('con_lettore')
                    ->label('Il cliente monta un lettore di carte?')
                    ->options([
                        'no' => 'No — solo alloggiamento, senza foro frontale',
                        'si' => 'Sì — alloggiamento predisposto per un lettore',
                    ])
                    ->default('no')
                    ->visible(fn (Forms\Get $get) => filled($get('alloggiamento')))
                    ->live()
                    ->afterStateUpdated(fn (Forms\Set $set) => $set('conteggio_product_id', null))
                    // Il lettore in se' non lo vende Franke: il listino dice
                    // "fornito e installato dal cliente/service partner", qui
                    // si sceglie solo l'alloggiamento col foro giusto.
                    ->helperText('Il lettore non e\' compreso: lo fornisce il cliente o il service partner. Qui si sceglie l\'alloggiamento con il foro giusto per quel modello.'),
                Forms\Components\Select::make('conteggio_product_id')
                    ->label('Sistema di conteggio')
                    ->options(fn (Forms\Get $get) => static::candidati($get('alloggiamento'), $get('con_lettore') === 'si')
                        ->mapWithKeys(fn (Product $p) => [$p->id => static::etichetta($p)]))
                    ->visible(fn (Forms\Get $get) => filled($get('alloggiamento')))
                    ->live()
                    ->searchable()
                    ->helperText('Le voci vengono dal listino Franke: codice e prezzo sono quelli, non calcolati.'),
                // Il conteggio SU03 CL e' integrato nell'unita' di
                // raffreddamento e il suo prezzo e' un supplemento: senza la
                // SU03 EC in preventivo mancano 1.170 euro.
                Forms\Components\Toggle::make('aggiungi_su03')
                    ->label('Aggiungi anche l\'unità di raffreddamento SU03 EC')
                    ->default(true)
                    ->visible(fn (Forms\Get $get) => static::eIntegratoNellaSu03($get('conteggio_product_id')))
                    ->helperText(fn () => 'Il conteggio SU03 CL vive dentro la SU03 EC e il suo prezzo e\' un supplemento. '
                        .(static::prezzoDi(self::SKU_SU03_EC) ?? '')),
                Forms\Components\Toggle::make('interruttore_chiave')
                    ->label('Interruttore a chiave (vendita libera e di prova)')
                    ->visible(fn (Forms\Get $get) => filled($get('conteggio_product_id')))
                    ->helperText(fn () => static::prezzoDi(self::SKU_INTERRUTTORE)),
                Forms\Components\TextInput::make('gettoni')
                    ->label('Confezioni di gettoni da 100 pz.')
                    ->numeric()
                    ->minValue(0)
                    ->default(0)
                    ->visible(fn (Forms\Get $get) => filled($get('conteggio_product_id')))
                    ->helperText(fn () => static::prezzoDi(self::SKU_GETTONI)),
            ]);
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

    /** La macchina scelta nel wizard e' della famiglia A300? */
    protected static function eUnaA300(Forms\Get $get): bool
    {
        $machine = Product::with('family')->find($get('machine_product_id'));

        return $machine?->family?->name === 'A300';
    }

    protected static function eIntegratoNellaSu03(?string $productId): bool
    {
        return str_contains(Product::find($productId)?->name ?? '', 'SU03 CL');
    }

    protected static function prezzoDi(string $sku): ?string
    {
        $prezzo = Product::query()->where('sku', $sku)->first()?->getCurrentPrice()?->price;

        return $prezzo ? number_format((float) $prezzo, 2, ',', '.').' € cad.' : null;
    }

    /**
     * Aggiunge al preventivo il sistema scelto (se e' stato scelto: lo step
     * e' facoltativo) e le sue voci accessorie.
     */
    public static function creaRighe(Quote $quote, array $data, float $sconto = 0): void
    {
        $sistema = Product::find($data['conteggio_product_id'] ?? null);

        if (! $sistema) {
            return;
        }

        $riga = $quote->quoteProducts()->create([
            'product_id' => $sistema->id,
            'quantity' => 1,
            'price' => $sistema->getCurrentPrice()?->price ?? 0,
            // Stesso sconto configurazione della macchina: e' un'unica cosa
            // che si sta quotando, e il riepilogo lo somma tutto insieme.
            'discount' => $sconto,
            'tax' => 22,
        ]);

        // Le opzioni restano appese alla riga del sistema (come le opzioni
        // macchina al loro apparecchio base): spostare o cancellare il
        // sistema si porta dietro anche loro.
        // La SU03 EC come riga a se': non e' un'opzione del conteggio, e' il
        // contrario — e' il conteggio a stare dentro di lei.
        if (($data['aggiungi_su03'] ?? false) && static::eIntegratoNellaSu03($sistema->id)) {
            static::aggiungiOpzione($quote, null, self::SKU_SU03_EC, 1, $sconto);
        }

        if ($data['interruttore_chiave'] ?? false) {
            static::aggiungiOpzione($quote, $riga->id, self::SKU_INTERRUTTORE, 1, $sconto);
        }

        $gettoni = (int) ($data['gettoni'] ?? 0);
        if ($gettoni > 0) {
            static::aggiungiOpzione($quote, $riga->id, self::SKU_GETTONI, $gettoni, $sconto);
        }

        $quote->updateTotal();

        Notification::make()->title('Sistema di conteggio aggiunto al preventivo')->success()->send();
    }

    protected static function aggiungiOpzione(Quote $quote, ?string $parentId, string $sku, int $quantity, float $sconto = 0): void
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
            'discount' => $sconto,
            'tax' => 22,
        ]);
    }

    /**
     * Le stesse righe che verrebbero create, per il riepilogo del wizard:
     * senza, il totale mostrato prima di confermare ignorava il sistema di
     * conteggio e chi confermava vedeva poi un totale piu' alto.
     *
     * @return Collection<int, array{label: string, price: float}>
     */
    public static function righeRiepilogo(Forms\Get $get): Collection
    {
        $sistema = Product::find($get('conteggio_product_id'));

        if (! $sistema) {
            return collect();
        }

        $righe = collect([static::rigaRiepilogo($sistema, 1)]);

        if ($get('aggiungi_su03') && static::eIntegratoNellaSu03($sistema->id)) {
            $righe->push(static::rigaRiepilogo(Product::where('sku', self::SKU_SU03_EC)->first(), 1));
        }

        if ($get('interruttore_chiave')) {
            $righe->push(static::rigaRiepilogo(Product::where('sku', self::SKU_INTERRUTTORE)->first(), 1));
        }

        $gettoni = (int) ($get('gettoni') ?? 0);
        if ($gettoni > 0) {
            $righe->push(static::rigaRiepilogo(Product::where('sku', self::SKU_GETTONI)->first(), $gettoni));
        }

        return $righe->filter();
    }

    /** @return array{label: string, price: float}|null */
    protected static function rigaRiepilogo(?Product $product, int $quantity): ?array
    {
        if (! $product) {
            return null;
        }

        $prezzo = (float) ($product->getCurrentPrice()?->price ?? 0) * $quantity;

        return [
            'label' => $quantity > 1 ? "{$product->name} × {$quantity}" : $product->name,
            'price' => $prezzo,
        ];
    }
}
