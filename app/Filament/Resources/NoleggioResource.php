<?php

namespace App\Filament\Resources;

use App\Filament\Forms\Components\AllegatiEmail;
use App\Mail\NoleggioMail;
use App\Models\Customer;
use App\Support\DisplayName;
use App\Models\MachineUnit;
use App\Models\Noleggio;
use App\Models\NoleggioFornitura;
use App\Models\Material;
use App\Models\ProdottoCaffe;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Resources\Resource;
use Filament\Support\RawJs;
use Filament\Tables;
use Filament\Tables\Table;
use App\Support\OutsideLivewireRender;
use Barryvdh\DomPDF\Facade\Pdf;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\HtmlString;

class NoleggioResource extends Resource
{
    protected static ?string $model = Noleggio::class;

    protected static ?string $navigationIcon = 'heroicon-o-arrow-path-rounded-square';

    // Senza, Filament pluralizza all'inglese e l'indirizzo diventa
    // /noleggios.
    protected static ?string $slug = 'noleggi';

    protected static ?string $navigationGroup = 'Vendite';

    protected static ?int $navigationSort = 4;

    protected static ?string $modelLabel = 'Noleggio';

    protected static ?string $pluralModelLabel = 'Noleggi operativi';

    public static function form(Form $form): Form
    {
        // Due colonne: a sinistra si lavora, a destra il canone che si muove
        // mentre si scrive. Prima l'anteprima stava in fondo e per vedere
        // l'effetto di un ricarico bisognava scorrere tutta la pagina
        // (Laura, 07/10/2026).
        return $form->columns(3)->schema([
            Forms\Components\Group::make()->columnSpan(['default' => 3, 'lg' => 2])->schema([
            Forms\Components\Section::make('Chi e cosa')
                ->columns(2)
                ->schema([
                    // L'etichetta passa da DisplayName come nelle altre schede:
                    // due clienti privati su 2.315 non hanno ragione sociale, e
                    // con company_name nuda Filament riceve un'etichetta nulla
                    // e la pagina va in errore 500.
                    Forms\Components\Select::make('customer_id')
                        ->label('Cliente')
                        ->relationship('customer', 'company_name', modifyQueryUsing: fn ($query) => $query->orderBy('company_name'))
                        ->getOptionLabelFromRecordUsing(fn ($record) => DisplayName::customerOption($record))
                        ->searchable(['search_name', 'company_name', 'first_name', 'last_name'])
                        ->preload()->required(),
                    Forms\Components\TextInput::make('descrizione')
                        ->label('Oggetto del noleggio')
                        ->helperText('Come comparira\' sul contratto, es. "Franke A600 FM Plus con unita\' di raffreddamento".')
                        ->required()->maxLength(255),
                    Forms\Components\Select::make('machine_unit_id')
                        ->label('Macchina (se gia\' individuata)')
                        ->options(fn (Get $get) => $get('customer_id')
                            ? MachineUnit::query()->where('current_customer_id', $get('customer_id'))
                                ->whereNull('deleted_at')
                                ->get()
                                ->mapWithKeys(fn (MachineUnit $m) => [
                                    // Stesso motivo del cliente: mai un'etichetta nulla.
                                    $m->id => trim(($m->serial_number ?: 'senza matricola').' — '.($m->model_name ?: '')),
                                ])->all()
                            : [])
                        ->searchable(),
                    Forms\Components\Select::make('quote_id')
                        ->label('Preventivo di riferimento')
                        ->relationship('quote', 'number')
                        ->getOptionLabelFromRecordUsing(fn ($record) => $record->number ?: '—')
                        ->searchable(),
                ]),

            Forms\Components\Section::make('Da cosa nasce il canone')
                ->description('Il canone non si scrive: si calcola da questi numeri, e resta ricostruibile anche fra due anni.')
                ->columns(3)
                ->schema([
                    Forms\Components\TextInput::make('listino')
                        ->label('Listino (€)')
                        ->helperText('Base del Full-Service, che costa il 10% annuo del listino.')
                        ->numeric()->required()->live(onBlur: true),
                    Forms\Components\TextInput::make('sconto_acquisto')
                        ->label('Sconto fornitore (%)')
                        ->helperText('Compilandolo, il costo si calcola da sé.')
                        ->numeric()->live(onBlur: true)
                        ->afterStateUpdated(function (Forms\Set $set, Get $get, $state) {
                            if ($state === null || $state === '' || ! (float) $get('listino')) {
                                return;
                            }
                            $set('costo', round((float) $get('listino') * (1 - (float) $state / 100), 2));
                        }),
                    Forms\Components\TextInput::make('costo')
                        ->label('Costo d\'acquisto (€)')
                        ->helperText('Quanto costa a voi. Il canone recupera questo, non il listino.')
                        ->numeric()->required()->live(onBlur: true),
                    Forms\Components\TextInput::make('mesi')
                        ->label('Durata (mesi)')
                        ->numeric()->minValue(1)->default(60)->required()->live(onBlur: true),
                    // Il contratto puo' durare cinque anni e la macchina
                    // rientrare in tre: da li' in poi quella quota resta nel
                    // canone ed e' margine. E si puo' ammortizzare il listino
                    // invece del costo (Laura, 07/10/2026).
                    Forms\Components\Select::make('ammortamento_base')
                        ->label('Ammortamento calcolato su')
                        ->options(['costo' => 'Costo d\'acquisto', 'listino' => 'Prezzo di listino'])
                        // Di norma il listino: e' il valore che si concede in
                        // uso. Lo sconto strappato al fornitore e' margine di
                        // Alex, non uno sconto da girare al cliente.
                        ->default('listino')->required()->live()
                        ->helperText(fn (Get $get) => $get('ammortamento_base') === 'costo'
                            ? 'Sul costo si rientra della spesa e basta: nessun margine sulla macchina.'
                            : null),
                    Forms\Components\TextInput::make('ammortamento_mesi')
                        ->label('Da recuperare in (mesi)')
                        ->placeholder('come la durata del contratto')
                        ->helperText('Es. 36 per rientrare in tre anni su un contratto di cinque.')
                        ->numeric()->minValue(1)->live(onBlur: true),
                    Forms\Components\TextInput::make('margine')
                        ->label('Maggiorazione sulla macchina (%)')
                        ->helperText('Copre insieme margine e costo del denaro immobilizzato.')
                        ->numeric()->default(15)->live(onBlur: true),
                    Forms\Components\TextInput::make('detergenti_mese')
                        ->label('Detergenti al mese (€)')
                        ->helperText('Non sono nel Full-Service, che copre i ricambi.')
                        ->numeric()->default(0)->live(onBlur: true),
                    Forms\Components\TextInput::make('ricarico_detergenti')
                        ->label('Ricarico sui detergenti (%)')
                        ->helperText('Zero se l\'importo sopra e\' gia\' un prezzo di vendita.')
                        ->numeric()->default(0)->live(onBlur: true),
                    Forms\Components\TextInput::make('caffe_mese')
                        ->label('Caffè al mese (€)')
                        ->helperText('Consumo stimato. Dentro il canone il cliente ha un numero solo, ma se consuma più del previsto la differenza è vostra.')
                        ->numeric()->default(0)->live(onBlur: true),
                    Forms\Components\TextInput::make('ricarico_caffe')
                        ->label('Ricarico sul caffè (%)')
                        ->helperText('Zero se l\'importo sopra è già un prezzo di vendita.')
                        ->numeric()->default(0)->live(onBlur: true),
                    // "Caffè compreso (kg/mese)" e "Detergenti compresi"
                    // stavano qui: due caselle di testo libero per dire cosa
                    // spettava al cliente. Le ha sostituite la sezione "Cosa
                    // comprende il canone", che le stesse quantita' le tiene
                    // voce per voce e le stampa nel contratto. Lasciarle
                    // sarebbe peggio che inutile: si compilano credendo che
                    // finiscano nel documento, e invece non le legge piu'
                    // nessuno (Laura, 07/10/2026). Le colonne restano, con
                    // quanto c'era scritto.
                    Forms\Components\TextInput::make('valore_residuo')
                        ->label('Valore residuo a fine contratto (€)')
                        ->helperText('Se la macchina torna a voi e vale ancora qualcosa, il canone scende.')
                        ->numeric()->default(0)->live(onBlur: true),
                    Forms\Components\TextInput::make('full_service_percentuale')
                        ->label('Full-Service (% annuo del listino)')
                        ->numeric()->default(10)->live(onBlur: true),
                ]),

            Forms\Components\Section::make('Cosa comprende il canone')
                ->description('Le quantità si scrivono ANNUE, come il contratto le promette al cliente: il costo mensile lo calcola il programma. Se ci sono righe, gli importi complessivi di detergenti e caffè qui sopra vengono ignorati.')
                ->schema([
                    // Da dove vengono le quantita'. Senza, fra due anni
                    // nessuno sa piu' perche' erano 890 kg di caffe' e non
                    // 600, e se il cliente raddoppia il servizio non c'e'
                    // niente a cui appellarsi per rivedere il canone.
                    Forms\Components\TextInput::make('base_consumo')
                        ->label('Consumi calcolati su')
                        ->placeholder('Es. 250 colazioni al giorno')
                        ->helperText('Finisce nel contratto, nell\'articolo del canone: è il presupposto su cui si regge.')
                        ->maxLength(255)
                        ->columnSpanFull(),
                ]),

            // Tre elenchi invece di uno con la tendina del gruppo su ogni
            // riga: il caffe' si legge insieme al caffe' e i detergenti
            // insieme ai detergenti, e ogni sezione sa gia' cosa contiene
            // (Laura, 07/10/2026). Sono la stessa relazione filtrata: ognuno
            // vede e cancella solo le proprie righe.
            Forms\Components\Section::make('Caffè')
                ->description('Caffè in grani e decaffeinato, dal listino.')
                ->collapsible()
                ->schema([static::ripetitoreForniture(NoleggioFornitura::GRUPPO_CAFFE)]),

            Forms\Components\Section::make('Polveri e solubili')
                ->description('Cioccolato, orzo, latte in polvere: dal listino, fra i liofilizzati.')
                ->collapsible()
                ->schema([static::ripetitoreForniture(NoleggioFornitura::GRUPPO_POLVERI)]),

            Forms\Components\Section::make('Detergenti e igiene')
                ->description('Dal magazzino materiali, oppure scritti a mano se non sono a catalogo.')
                ->collapsible()
                ->schema([static::ripetitoreForniture(NoleggioFornitura::GRUPPO_DETERGENTI)]),

            // Se restano vuoti il contratto li esclude per nome, cosi' nessuno
            // dà per scontato che i bicchieri siano compresi.
            Forms\Components\Section::make('Consumabili')
                ->description('Bicchieri, palette, zucchero. Lasciando vuoto, il contratto li dichiara esclusi.')
                ->collapsible()
                ->schema([static::ripetitoreForniture(NoleggioFornitura::GRUPPO_CONSUMABILI)]),

            Forms\Components\Section::make('Contratto')
                ->columns(3)
                ->schema([
                    Forms\Components\DatePicker::make('data_inizio')->label('Decorrenza'),
                    // Finiscono nel contratto parola per parola: un noleggio
                    // senza condizioni di pagamento non si firma, e su
                    // sessanta canoni la differenza fra "30 giorni data
                    // fattura" e "60 giorni fine mese" sono due mesi sempre
                    // scoperti (Laura, 06/10/2026).
                    Forms\Components\Select::make('periodicita_fatturazione')
                        ->label('Fatturazione')->options(Noleggio::periodicitaLabels())
                        ->default('mensile')->required(),
                    Forms\Components\Select::make('modalita_pagamento')
                        ->label('Modalità di pagamento')->options(Noleggio::modalitaPagamentoLabels())
                        ->default('bonifico')->required(),
                    Forms\Components\Select::make('termini_pagamento')
                        ->label('Termini di pagamento')->options(Noleggio::terminiPagamentoLabels())
                        ->default('30_df')->required(),
                    Forms\Components\Select::make('stato')
                        ->label('Stato')->options(Noleggio::statiLabels())
                        ->default(Noleggio::STATO_BOZZA)->required(),
                    Forms\Components\Textarea::make('note')->label('Note')->rows(3)->columnSpanFull(),
                ]),
            ]),

            Forms\Components\Group::make()->columnSpan(['default' => 3, 'lg' => 1])->schema([
                Forms\Components\Section::make('Il canone')
                    ->schema([
                        Forms\Components\Placeholder::make('anteprima')
                            ->label('')
                            ->content(fn (Get $get): HtmlString => new HtmlString(static::anteprima($get))),
                    ]),
            ]),
        ]);
    }

    /**
     * L'anteprima mostra le tre voci separate e, soprattutto, il mese di
     * pareggio e lo scoperto a meta' contratto: il canone da solo non dice
     * quanto si rischia se il cliente disdice prima.
     */
    /**
     * L'elenco delle forniture di un gruppo: stessa relazione, filtrata.
     *
     * Ogni ripetitore vede e cancella solo le righe del proprio gruppo
     * (Filament filtra anche l'elenco dei record esistenti su cui decide le
     * cancellazioni), e il gruppo lo impone la sezione invece di chiederlo
     * riga per riga con una tendina.
     */
    public static function ripetitoreForniture(string $gruppo): Forms\Components\Repeater
    {
        return Forms\Components\Repeater::make('forniture_'.$gruppo)
            // Il filtro si passa a relationship(): e' il secondo argomento,
            // non un metodo a parte.
            ->relationship('forniture', fn ($query) => $query->where('gruppo', $gruppo))
            ->label('')
            ->columns(6)
            ->mutateRelationshipDataBeforeCreateUsing(fn (array $data) => array_merge($data, ['gruppo' => $gruppo]))
            ->mutateRelationshipDataBeforeSaveUsing(fn (array $data) => array_merge($data, ['gruppo' => $gruppo]))
            ->schema([
                static::sceltaProdotto($gruppo),
                Forms\Components\TextInput::make('voce')
                    ->label('Voce')->required()->maxLength(255)->columnSpan(3),
                // Si scrive quello che il contratto promette -- "890 kg di
                // caffe' all'anno" -- e il mensile lo calcola il programma.
                Forms\Components\TextInput::make('quantita')
                    ->label('Q.tà/anno')->numeric()->required()->live(onBlur: true)
                    ->helperText(fn (Get $get) => filled($get('quantita'))
                        ? '= '.rtrim(rtrim(number_format((float) $get('quantita') / 12, 2, ',', '.'), '0'), ',').' al mese'
                        : null)
                    ->columnSpan(1),
                Forms\Components\TextInput::make('unita')
                    ->label('Unità')->default('pz')->maxLength(16)->columnSpan(1),
                // Di VENDITA: e' il prezzo che fa il canone. Il costo sta
                // nella casella accanto, se lo si sa.
                Forms\Components\TextInput::make('prezzo_unitario')
                    ->label('€ vendita')->numeric()->required()->live(onBlur: true)->columnSpan(1),
                // Facoltativo, e serve solo a noi: senza, la scheda non puo'
                // dire quanto si guadagna e lo dichiara invece di inventare
                // un margine zero (Laura, 07/10/2026).
                Forms\Components\TextInput::make('prezzo_acquisto')
                    ->label('€ acquisto')->numeric()->live(onBlur: true)
                    ->helperText('Quanto lo paghiamo. Facoltativo: serve a calcolare il margine.')
                    ->columnSpan(1),
                Forms\Components\TextInput::make('ricarico')
                    ->label('Ricarico %')
                    ->helperText('Lasciare a zero se il prezzo di vendita è già quello giusto.')
                    ->numeric()->default(0)->live(onBlur: true)->columnSpan(1),
                Forms\Components\TextInput::make('note')
                    ->label('Nota (come si è calcolata la quantità)')->maxLength(255)->columnSpan(6),
            ])
            ->itemLabel(fn (array $state): ?string => filled($state['voce'] ?? null)
                ? $state['voce'].' — '.rtrim(rtrim(number_format((float) ($state['quantita'] ?? 0), 3, ',', '.'), '0'), ',').' '.($state['unita'] ?? '').'/anno'
                : null)
            ->addActionLabel('Aggiungi una voce')
            ->defaultItems(0)
            ->columnSpanFull();
    }

    /**
     * Da dove si pesca la voce: caffe' e polveri stanno nel listino (e sono
     * due gruppi diversi dello stesso listino), i detergenti nel magazzino.
     * Una tendina sola per sezione, quella giusta.
     */
    protected static function sceltaProdotto(string $gruppo): Forms\Components\Select
    {
        // Detergenti e consumabili si pescano dal magazzino: nel listino
        // caffe' non ci sono ne' le pastiglie ne' i bicchieri.
        if (in_array($gruppo, [NoleggioFornitura::GRUPPO_DETERGENTI, NoleggioFornitura::GRUPPO_CONSUMABILI], true)) {
            return Forms\Components\Select::make('material_id')
                ->label('Dal magazzino materiali')
                // Ricerca sul server: i materiali sono 3.600 e un menu non li
                // tiene. Il codice sta in testa perche' e' quello che si digita.
                ->searchable()
                ->getSearchResultsUsing(fn (string $search) => Material::query()
                    ->where(fn ($q) => $q->where('code', 'like', "%{$search}%")
                        ->orWhere('type', 'like', "%{$search}%")
                        ->orWhere('variant', 'like', "%{$search}%"))
                    ->orderBy('code')->limit(50)->get()
                    ->mapWithKeys(fn (Material $m) => [$m->id => static::etichettaMateriale($m)])
                    ->all())
                ->getOptionLabelUsing(fn ($value) => ($m = Material::find($value))
                    ? static::etichettaMateriale($m)
                    : null)
                ->live()
                ->afterStateUpdated(function (Forms\Set $set, $state) {
                    if (! $state || ! ($m = Material::find($state))) {
                        return;
                    }
                    $set('voce', $m->display_label ?: $m->code);
                    // Due terzi del magazzino non ha un prezzo di listino:
                    // azzerare quello scritto a mano farebbe sparire il costo
                    // dal canone senza dirlo.
                    if ((float) $m->list_price > 0) {
                        $set('prezzo_unitario', (float) $m->list_price);
                    }
                })
                ->columnSpan(3);
        }

        // Nel listino caffe' i liofilizzati (cioccolato, orzo, deca solubile)
        // stanno in un gruppo a parte: ogni sezione vede solo i suoi.
        $gruppoListino = $gruppo === NoleggioFornitura::GRUPPO_POLVERI ? 'liofilizzati' : 'caffe';

        return Forms\Components\Select::make('prodotto_caffe_id')
            ->label('Dal listino')
            ->options(fn () => ProdottoCaffe::query()->where('attivo', true)->where('gruppo', $gruppoListino)
                ->orderBy('ordinamento')->get()
                ->mapWithKeys(fn (ProdottoCaffe $p) => [
                    $p->id => $p->nome.' — '.$p->formato.' — € '.number_format((float) $p->prezzo, 2, ',', '.'),
                ])->all())
            ->searchable()
            ->live()
            // Le righe inserite prima che il riferimento esistesse non ce
            // l'hanno: lo si ritrova dal nome, cosi' la tendina mostra subito
            // la voce giusta invece di sembrare mai compilata.
            ->afterStateHydrated(function (Forms\Components\Select $component, Get $get, $state) {
                if (filled($state) || blank($voce = $get('voce'))) {
                    return;
                }

                $component->state(ProdottoCaffe::where('nome', $voce)->value('id'));
            })
            ->afterStateUpdated(function (Forms\Set $set, $state) {
                if (! $state || ! ($p = ProdottoCaffe::find($state))) {
                    return;
                }
                $set('voce', $p->nome);
                $set('unita', $p->formato);
                $set('prezzo_unitario', (float) $p->prezzo);
            })
            ->columnSpan(3);
    }

    protected static function anteprima(Get $get): string
    {
        $n = new Noleggio([
            'listino' => (float) $get('listino'),
            'costo' => (float) $get('costo'),
            'mesi' => max(1, (int) $get('mesi')),
            'margine' => (float) $get('margine'),
            'detergenti_mese' => (float) $get('detergenti_mese'),
            'ricarico_detergenti' => (float) $get('ricarico_detergenti'),
            'caffe_mese' => (float) $get('caffe_mese'),
            'ricarico_caffe' => (float) $get('ricarico_caffe'),
            'valore_residuo' => (float) $get('valore_residuo'),
            'full_service_percentuale' => (float) ($get('full_service_percentuale') ?: 10),
            'ammortamento_base' => $get('ammortamento_base') ?: 'costo',
            'ammortamento_mesi' => $get('ammortamento_mesi') ?: null,
        ]);
        $eur = fn ($v) => '€ '.number_format((float) $v, 2, ',', '.');

        // I totali per gruppo si leggono dalle righe del ripetitore, cosi' si
        // muovono mentre si digita invece che al salvataggio.
        $perGruppo = collect(array_keys(NoleggioFornitura::gruppiLabels()))
            ->mapWithKeys(function (string $gruppo) use ($get) {
                $righe = collect($get('forniture_'.$gruppo) ?? []);

                // Null, non zero, quando il gruppo non ha righe: cosi' il
                // calcolo ricade sugli importi complessivi di detergenti e
                // caffe', che e' come erano prezzati i noleggi prima del
                // prospetto a voci.
                return [$gruppo => $righe->isEmpty() ? null : $righe
                    // Diviso dodici: le quantita' sono annue e qui si mostra
                    // il mese. Senza, i detergenti uscivano 1.191,69 al posto
                    // di 99,31 — il totale di un anno spacciato per mensile.
                    // Arrotondata riga per riga come fa NoleggioFornitura al
                    // salvataggio: sommando i valori pieni l'anteprima
                    // divergerebbe dal contratto di qualche centesimo.
                    ->sum(fn ($f) => round((float) ($f['quantita'] ?? 0) / 12
                        * (float) ($f['prezzo_unitario'] ?? 0)
                        * (1 + (float) ($f['ricarico'] ?? 0) / 100), 2))];
            });

        // Il totale si calcola con le righe che si stanno scrivendo: prima
        // usava un noleggio senza forniture e il canone dell'anteprima
        // divergeva da quello del contratto (Laura, 07/10/2026).
        $r = $n->ricalcola($perGruppo->filter(fn ($v) => $v !== null)->all());
        $meta = (int) max(1, round($r->mesi / 2));

        $righe = [
            ['Quota macchina', $eur($r->quotaMacchina)],
            ['Full-Service', $eur($r->quotaServizio)],
        ];
        foreach (NoleggioFornitura::gruppiLabels() as $chiave => $etichetta) {
            if (($perGruppo[$chiave] ?? null) > 0) {
                $righe[] = [$etichetta, $eur($perGruppo[$chiave])];
            }
        }
        if ($perGruppo->filter(fn ($v) => $v !== null)->isEmpty()) {
            $righe[] = ['Detergenti', $eur($r->quotaDetergenti)];
            $righe[] = ['Caffè', $eur($r->quotaCaffe)];
        }
        $corpo = '';
        foreach ($righe as [$k, $v]) {
            $corpo .= '<div style="display:flex;justify-content:space-between;padding:2px 0;"><span>'.$k.'</span><span>'.$v.'</span></div>';
        }

        return '<div style="max-width:30rem;">'.$corpo
            .'<div style="display:flex;justify-content:space-between;border-top:2px solid currentColor;margin-top:6px;padding-top:6px;font-weight:700;font-size:1.1em;">'
            .'<span>Canone mensile</span><span>'.$eur($r->canone).'</span></div>'
            .'<div style="margin-top:10px;font-size:.9em;opacity:.85;">'
            .'Incasso totale su '.$r->mesi.' mesi: <strong>'.$eur($r->incassoTotale).'</strong><br>'
            .'In pari dal <strong>'.($r->mesePareggio ? $r->mesePareggio.'° mese' : '—').'</strong>'
            .($r->mesePareggio && $r->mesePareggio > $r->mesi ? ' <strong>(oltre la durata: il contratto non recupera il costo)</strong>' : '').'<br>'
            .'Se disdicono al '.$meta.'° mese restano scoperti <strong>'.$eur($r->scopertoSeDisdettaAl($meta)).'</strong>'
            .'</div></div>';
    }

    public static function table(Table $table): Table
    {
        return $table
            // Come i preventivi: si ordina per numero e non per created_at.
            // Il numero cresce in ordine di creazione ed e' univoco per riga,
            // quindi l'ordinamento e' stabile; created_at su righe create
            // nello stesso minuto non lo e'.
            ->defaultSort('number', 'desc')
            ->columns([
                Tables\Columns\TextColumn::make('number')->label('Numero')
                    ->weight('medium')->searchable()->sortable(),
                Tables\Columns\TextColumn::make('customer.company_name')->wrap()->label('Cliente')
                    ->searchable()->sortable()
                    ->formatStateUsing(fn (?string $state) => DisplayName::titleCase($state)),
                // Come nei preventivi e nelle richieste: la zona e' il primo
                // filtro mentale quando si scorre un elenco di clienti.
                Tables\Columns\TextColumn::make('customer.province')->visibleFrom('md')
                    ->label('Prov.')
                    ->badge()
                    ->color('gray')
                    ->searchable()
                    ->sortable()
                    ->placeholder('—'),
                Tables\Columns\TextColumn::make('data_inizio')->visibleFrom('md')
                    ->label('Decorrenza')->date('d/m/Y')->sortable()->placeholder('—'),
                Tables\Columns\TextColumn::make('stato')->label('Stato')->badge()
                    ->formatStateUsing(fn (string $state) => Noleggio::statiLabels()[$state] ?? $state)
                    ->color(fn (string $state) => match ($state) {
                        Noleggio::STATO_ATTIVO => 'success',
                        Noleggio::STATO_INVIATO => 'info',
                        Noleggio::STATO_CHIUSO => 'gray',
                        default => 'warning',
                    }),
                Tables\Columns\TextColumn::make('canone')->label('Canone')->money('EUR')->sortable()
                    ->description(fn (Noleggio $record) => $record->mesi.' mesi'),
                // La domanda che ci si fa scorrendo l'elenco e' "gliel'ho
                // mandato?": la risposta stava dentro la scheda, una per una.
                Tables\Columns\TextColumn::make('inviato')->visibleFrom('md')
                    ->label('Inviato')
                    ->state(fn (Noleggio $record) => $record->emails->first()?->created_at?->format('d/m/Y'))
                    ->description(fn (Noleggio $record) => $record->emails->first()?->recipient_email)
                    ->color(fn (Noleggio $record) => $record->emails->first() ? 'success' : 'gray')
                    ->placeholder('mai'),
                // Numeri nostri, come "Visto" sui preventivi: utili ma non da
                // tenere sempre a schermo.
                Tables\Columns\TextColumn::make('descrizione')->label('Oggetto')->limit(34)
                    ->searchable()->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('mese_pareggio')->label('In pari dal')
                    ->formatStateUsing(fn (?int $state) => $state ? $state.'°' : '—')
                    ->color(fn (?int $state, Noleggio $record) => $state && $state > $record->mesi ? 'danger' : null)
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('stato')->label('Stato')->options(Noleggio::statiLabels()),
                Tables\Filters\SelectFilter::make('customer_id')
                    ->label('Cliente')
                    ->relationship('customer', 'company_name', modifyQueryUsing: fn ($query) => $query->orderBy('company_name'))
                    ->getOptionLabelFromRecordUsing(fn ($record) => DisplayName::customerOption($record))
                    ->searchable()
                    ->preload(),
                Tables\Filters\Filter::make('data_inizio')
                    ->label('Periodo')
                    ->form([
                        Forms\Components\DatePicker::make('from')->label('Dal'),
                        Forms\Components\DatePicker::make('until')->label('Al'),
                    ])
                    ->query(fn ($query, array $data) => $query
                        ->when($data['from'] ?? null, fn ($q, $d) => $q->whereDate('data_inizio', '>=', $d))
                        ->when($data['until'] ?? null, fn ($q, $d) => $q->whereDate('data_inizio', '<=', $d))),
            ])
            ->actions([
                Tables\Actions\ViewAction::make(),
                Tables\Actions\EditAction::make(),
                static::azioneContratto(Tables\Actions\Action::make('contratto')),
                static::azioneInvio(Tables\Actions\Action::make('invia')),
            ]);
    }

    /**
     * Codice in testa perche' e' quello che si cerca ("23-020"), e il prezzo
     * solo se c'e': scrivere "€ 0,00" su un articolo senza listino sembra un
     * articolo gratis invece che un prezzo da mettere.
     */
    public static function etichettaMateriale(Material $m): string
    {
        $prezzo = (float) $m->list_price > 0
            ? ' — € '.number_format((float) $m->list_price, 2, ',', '.')
            : ' — prezzo da indicare';

        return $m->code.' — '.($m->display_label ?: $m->code).$prezzo;
    }

    /**
     * Il contratto in PDF. La stessa azione serve la tabella e la scheda, cosi'
     * il documento e' uno solo: due generatori divergono al primo cambio di
     * condizioni, e si scopre dal cliente che ne ha ricevuto una versione
     * vecchia.
     */
    public static function azioneContratto($azione)
    {
        return $azione
            ->label('Contratto PDF')
            // Si apre in una scheda nuova invece di scaricarsi: controllando
            // una virgola alla volta, dopo dieci prove i Download sono pieni
            // di contratti uguali e non si sa piu' qual e' l'ultimo
            // (Laura, 07/10/2026). Da li' si stampa o si salva se serve.
            ->icon('heroicon-o-document-magnifying-glass')
            ->color('gray')
            ->url(fn (Noleggio $record) => route('noleggi.contratto', $record), shouldOpenInNewTab: true);
    }

    /**
     * Un generatore solo per lo scarico e per l'allegato della mail: se si
     * sdoppiano, il cliente riceve per posta una versione diversa da quella
     * che si vede premendo "Contratto PDF".
     */
    public static function buildPdf(Noleggio $record)
    {
        return OutsideLivewireRender::run(fn () => Pdf::loadView('pdf.noleggio', [
            'noleggio' => $record->load(['customer', 'machineUnit', 'forniture']),
            'tenant' => $record->tenant,
        ]));
    }

    public static function nomeFile(Noleggio $record): string
    {
        // Dal numero, come "preventivo-PRV-2026-0079.pdf": il nome del
        // cliente faceva file lunghissimi e indistinguibili fra due proposte
        // allo stesso cliente.
        return 'noleggio-'.($record->number ?: str($record->customer?->company_name ?: 'cliente')->slug()).'.pdf';
    }

    /**
     * L'invio al cliente, con le stesse regole delle offerte: si scrive al
     * cliente e non a chi paga, la chiusura e i recapiti sono prestampati
     * nella mail e non si toccano da qui, e di ogni invio resta traccia.
     */
    public static function azioneInvio($azione)
    {
        return $azione
            ->label('Invia al cliente')
            ->icon('heroicon-o-paper-airplane')
            ->modalHeading('Invia il contratto di noleggio')
            ->modalSubmitActionLabel('Invia')
            ->form(static::inviaFormSchema())
            ->action(fn (Noleggio $record, array $data) => static::invia($record, $data));
    }

    /** @return array<Forms\Components\Component> */
    public static function inviaFormSchema(): array
    {
        return [
            Forms\Components\TextInput::make('recipient_email')
                ->label('Email destinatario')
                ->email()->required()
                ->default(fn (Noleggio $record) => $record->customer?->primaryEmail()),
            Forms\Components\TextInput::make('cc_email')
                ->label('CC (opzionale)')
                ->email(),
            Forms\Components\TextInput::make('subject')
                ->label('Oggetto')
                ->required()
                ->default(fn (Noleggio $record) => static::oggettoEmail($record)),
            AllegatiEmail::make(fn (?Noleggio $record) => $record ? [static::nomeFile($record)] : []),
            Forms\Components\RichEditor::make('custom_message')
                ->label('Testo email (modificabile)')
                ->toolbarButtons(['bold', 'italic', 'bulletList', 'orderedList', 'link', 'undo', 'redo'])
                ->default(fn (Noleggio $record) => static::testoEmail($record))
                ->helperText('La chiusura e i recapiti sono prestampati e non si modificano da qui.'),
        ];
    }

    public static function oggettoEmail(Noleggio $record): string
    {
        return 'Contratto di noleggio operativo'
            .($record->number ? ' '.$record->number : '')
            .' — '.(DisplayName::titleCase($record->customer?->company_name) ?: 'proposta');
    }

    public static function testoEmail(Noleggio $record): string
    {
        $nome = DisplayName::titleCase($record->customer?->company_name)
            ?: (DisplayName::titleCase($record->customer?->full_name) ?: 'Cliente');

        return implode('', [
            '<p>Gentile '.e($nome).',</p>',
            '<p>in allegato il contratto di noleggio operativo con il dettaglio di quanto è compreso nel canone.</p>',
            '<p>Se le condizioni sono di suo gradimento, può restituircelo firmato per accettazione.</p>',
        ]);
    }

    public static function invia(Noleggio $record, array $data): void
    {
        $cc = array_values(array_unique(array_filter([
            $data['cc_email'] ?? null,
            // Lista sua, non quella dei preventivi: vedi Impostazioni >
            // Notifiche, voce "Noleggi".
            ...($record->tenant?->notificationRecipients('noleggio') ?? []),
        ])));

        try {
            Mail::to($data['recipient_email'])
                ->cc($cc)
                ->send(new NoleggioMail(
                    $record,
                    static::buildPdf($record)->output(),
                    static::nomeFile($record),
                    $data['custom_message'] ?? null,
                    $data['subject'] ?? static::oggettoEmail($record),
                ));

            static::registraInvio($record, $data);

            // Come sui preventivi: partito il documento, lo stato lo dice.
            // Restava "Bozza" anche dopo averlo mandato al cliente, e
            // l'elenco non distingueva piu' cosa era uscito e cosa no
            // (Laura, 09/10/2026). Solo da bozza: un contratto gia' attivo
            // che si rimanda non torna indietro.
            if ($record->stato === Noleggio::STATO_BOZZA) {
                $record->update(['stato' => Noleggio::STATO_INVIATO]);
            }

            Notification::make()->title('Contratto inviato')->success()->send();
        } catch (\Throwable $e) {
            report($e);
            // Si annota anche il fallimento: un contratto che non e' partito
            // e' il peggiore da scoprire per caso, settimane dopo.
            static::registraInvio($record, $data, errore: $e->getMessage());

            Notification::make()->title('Invio fallito')->body($e->getMessage())->danger()->send();
        }
    }

    public static function registraInvio(Noleggio $record, array $data, ?string $errore = null): void
    {
        $record->emails()->create([
            'user_id' => Auth::id(),
            'recipient_email' => $data['recipient_email'],
            'cc_email' => $data['cc_email'] ?? null,
            'subject' => $data['subject'] ?? static::oggettoEmail($record),
            'message' => $data['custom_message'] ?? null,
            'status' => $errore === null ? 'sent' : 'failed',
            'error_message' => $errore,
        ]);
    }

    /** Gli invii servono alla colonna "Inviato": caricati insieme, non uno per riga. */
    public static function getEloquentQuery(): \Illuminate\Database\Eloquent\Builder
    {
        return parent::getEloquentQuery()->with('emails');
    }

    public static function getPages(): array
    {
        return [
            'index' => NoleggioResource\Pages\ListNoleggi::route('/'),
            // "create" PRIMA di "view": /{record} intercetta qualunque cosa,
            // /create compreso, e la pagina di creazione rispondeva 404
            // cercando un noleggio chiamato "create" (07/10/2026).
            'create' => NoleggioResource\Pages\CreateNoleggio::route('/create'),
            'view' => NoleggioResource\Pages\ViewNoleggio::route('/{record}'),
            'edit' => NoleggioResource\Pages\EditNoleggio::route('/{record}/edit'),
        ];
    }
}
