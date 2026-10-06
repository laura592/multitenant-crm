<?php

namespace App\Filament\Resources;

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
        return $form->schema([
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
                    // Il quantitativo non entra nel calcolo: entra nel
                    // contratto. L'importo dice quanto costa, questo dice
                    // quanta ne spetta — senza, "compreso" non ha confine.
                    Forms\Components\TextInput::make('caffe_kg_mese')
                        ->label('Caffè compreso (kg/mese)')
                        ->helperText('Finisce nel contratto. Oltre questa quantità si fattura a consumo.')
                        ->numeric(),
                    Forms\Components\TextInput::make('detergenti_inclusi')
                        ->label('Detergenti compresi')
                        ->helperText('Es. "25 pastiglie e 1 flacone detergente latte al mese". Finisce nel contratto.')
                        ->maxLength(255),
                    Forms\Components\TextInput::make('valore_residuo')
                        ->label('Valore residuo a fine contratto (€)')
                        ->helperText('Se la macchina torna a voi e vale ancora qualcosa, il canone scende.')
                        ->numeric()->default(0)->live(onBlur: true),
                    Forms\Components\TextInput::make('full_service_percentuale')
                        ->label('Full-Service (% annuo del listino)')
                        ->numeric()->default(10)->live(onBlur: true),
                ]),

            Forms\Components\Section::make('Cosa comprende il canone')
                ->description('Una riga per voce: è quello che il contratto promette al cliente, e il canone la somma. Se ci sono righe, gli importi complessivi di detergenti e caffè qui sopra vengono ignorati.')
                ->schema([
                    Forms\Components\Repeater::make('forniture')
                        ->relationship()
                        ->label('')
                        // Sei colonne in due righe leggibili invece di sette
                        // schiacciate su una: con i campi larghi 1/7 la
                        // tendina del listino si perdeva fra gli altri.
                        ->columns(6)
                        ->schema([
                            Forms\Components\Select::make('gruppo')
                                ->label('Gruppo')->options(NoleggioFornitura::gruppiLabels())
                                ->default(NoleggioFornitura::GRUPPO_DETERGENTI)->required()->columnSpan(3),
                            // Caffè, deca e polveri hanno gia' un listino:
                            // sceglierli da li' evita di ribattere nome e
                            // prezzo, e soprattutto evita che il contratto
                            // prometta un prodotto a un prezzo che il listino
                            // non pratica piu'. I detergenti restano a mano:
                            // non sono in nessun listino.
                            Forms\Components\Select::make('prodotto_caffe_id')
                                ->label('Dal listino caffè')
                                // Si salva: finche' era solo un aiuto alla
                                // compilazione, riaprendo la scheda la tendina
                                // tornava vuota e sembrava che la scelta non
                                // fosse mai stata fatta (Laura, 06/10/2026).
                                ->options(fn () => ProdottoCaffe::query()->where('attivo', true)
                                    ->orderBy('ordinamento')->get()
                                    ->mapWithKeys(fn (ProdottoCaffe $p) => [
                                        $p->id => $p->nome.' — '.$p->formato.' — € '.number_format((float) $p->prezzo, 2, ',', '.'),
                                    ])->all())
                                ->searchable()
                                ->live()
                                // Le righe inserite prima che il riferimento
                                // esistesse non ce l'hanno: lo si ritrova dal
                                // nome, cosi' la tendina mostra subito la voce
                                // giusta invece di sembrare mai compilata. Si
                                // fissa sul serio al primo salvataggio.
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
                                    $set('gruppo', $p->gruppo === 'liofilizzati'
                                        ? NoleggioFornitura::GRUPPO_POLVERI
                                        : NoleggioFornitura::GRUPPO_CAFFE);
                                    // Una riga viene da una fonte sola.
                                    $set('material_id', null);
                                })
                                ->columnSpan(3),
                            // I detergenti e i filtri stanno fra i materiali,
                            // non nel listino caffe'. Stessa etichetta usata
                            // nei rapportini, cosi' si cercano allo stesso
                            // modo in tutto il gestionale.
                            Forms\Components\Select::make('material_id')
                                ->label('Dal magazzino materiali')
                                // Ricerca sul server, non fra opzioni gia'
                                // caricate: i materiali sono 3.600 e un menu
                                // non li tiene. Prima se ne caricavano i primi
                                // 300 per codice e si cercava solo fra quelli,
                                // quindi quasi tutto il magazzino era
                                // irraggiungibile (Laura, 06/10/2026).
                                ->searchable()
                                ->getSearchResultsUsing(fn (string $search) => Material::query()
                                    ->where(fn ($q) => $q->where('code', 'like', "%{$search}%")
                                        ->orWhere('type', 'like', "%{$search}%")
                                        ->orWhere('variant', 'like', "%{$search}%"))
                                    ->orderBy('code')->limit(50)->get()
                                    ->mapWithKeys(fn (Material $m) => [$m->id => static::etichettaMateriale($m)])
                                    ->all())
                                // Serve a mostrare la voce gia' scelta quando
                                // si riapre la scheda: senza, la ricerca lato
                                // server lascerebbe la tendina vuota.
                                ->getOptionLabelUsing(fn ($value) => ($m = Material::find($value))
                                    ? static::etichettaMateriale($m)
                                    : null)
                                ->live()
                                ->afterStateUpdated(function (Forms\Set $set, $state) {
                                    if (! $state || ! ($m = Material::find($state))) {
                                        return;
                                    }
                                    $set('voce', $m->display_label ?: $m->code);
                                    // Due terzi del magazzino non ha un prezzo
                                    // di listino: azzerare quello scritto a
                                    // mano farebbe sparire il costo dal canone
                                    // senza dirlo. Si sovrascrive solo quando
                                    // un prezzo c'e' davvero.
                                    if ((float) $m->list_price > 0) {
                                        $set('prezzo_unitario', (float) $m->list_price);
                                    }
                                    $set('gruppo', NoleggioFornitura::GRUPPO_DETERGENTI);
                                    $set('prodotto_caffe_id', null);
                                })
                                ->columnSpan(3),
                            Forms\Components\TextInput::make('voce')
                                ->label('Voce')->required()->maxLength(255)->columnSpan(3),
                            Forms\Components\TextInput::make('quantita')
                                ->label('Q.tà/mese')->numeric()->required()->live(onBlur: true)->columnSpan(1),
                            Forms\Components\TextInput::make('unita')
                                ->label('Unità')->default('pz')->maxLength(16)->columnSpan(1),
                            Forms\Components\TextInput::make('prezzo_unitario')
                                ->label('€ unitario')->numeric()->required()->live(onBlur: true)->columnSpan(1),
                            Forms\Components\TextInput::make('ricarico')
                                ->label('Ricarico %')->numeric()->default(0)->live(onBlur: true)->columnSpan(1),
                            Forms\Components\TextInput::make('note')
                                ->label('Nota (come si è calcolata la quantità)')->maxLength(255)->columnSpan(6),
                        ])
                        ->itemLabel(fn (array $state): ?string => filled($state['voce'] ?? null)
                            ? $state['voce'].' — '.rtrim(rtrim(number_format((float) ($state['quantita'] ?? 0), 3, ',', '.'), '0'), ',').' '.($state['unita'] ?? '')
                            : null)
                        ->addActionLabel('Aggiungi una voce')
                        ->defaultItems(0)
                        ->columnSpanFull(),
                ]),

            Forms\Components\Section::make('Il canone')
                ->schema([
                    Forms\Components\Placeholder::make('anteprima')
                        ->label('')
                        ->content(fn (Get $get): HtmlString => new HtmlString(static::anteprima($get))),
                ]),

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
        ]);
    }

    /**
     * L'anteprima mostra le tre voci separate e, soprattutto, il mese di
     * pareggio e lo scoperto a meta' contratto: il canone da solo non dice
     * quanto si rischia se il cliente disdice prima.
     */
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
        ]);
        $r = $n->ricalcola();
        $eur = fn ($v) => '€ '.number_format((float) $v, 2, ',', '.');
        $meta = (int) max(1, round($r->mesi / 2));

        // I totali per gruppo si leggono dalle righe del ripetitore, cosi' si
        // muovono mentre si digita invece che al salvataggio.
        $perGruppo = collect($get('forniture') ?? [])
            ->groupBy(fn ($f) => $f['gruppo'] ?? 'consumabili')
            ->map(fn ($g) => $g->sum(fn ($f) => (float) ($f['quantita'] ?? 0)
                * (float) ($f['prezzo_unitario'] ?? 0)
                * (1 + (float) ($f['ricarico'] ?? 0) / 100)));

        $righe = [
            ['Quota macchina', $eur($r->quotaMacchina)],
            ['Full-Service', $eur($r->quotaServizio)],
        ];
        foreach (NoleggioFornitura::gruppiLabels() as $chiave => $etichetta) {
            if (($perGruppo[$chiave] ?? 0) > 0) {
                $righe[] = [$etichetta, $eur($perGruppo[$chiave])];
            }
        }
        if ($perGruppo->isEmpty()) {
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
            ->columns([
                Tables\Columns\TextColumn::make('customer.company_name')->label('Cliente')->searchable()->sortable(),
                Tables\Columns\TextColumn::make('descrizione')->label('Oggetto')->limit(34)->searchable(),
                Tables\Columns\TextColumn::make('canone')->label('Canone')->money('EUR')->sortable(),
                Tables\Columns\TextColumn::make('mesi')->label('Mesi')->sortable(),
                Tables\Columns\TextColumn::make('mese_pareggio')->label('In pari dal')
                    ->formatStateUsing(fn (?int $state) => $state ? $state.'°' : '—')
                    ->color(fn (?int $state, Noleggio $record) => $state && $state > $record->mesi ? 'danger' : null),
                Tables\Columns\TextColumn::make('data_inizio')->label('Decorrenza')->date('d/m/Y')->placeholder('—'),
                Tables\Columns\TextColumn::make('stato')->label('Stato')->badge()
                    ->formatStateUsing(fn (string $state) => Noleggio::statiLabels()[$state] ?? $state)
                    ->color(fn (string $state) => match ($state) {
                        Noleggio::STATO_ATTIVO => 'success',
                        Noleggio::STATO_CHIUSO => 'gray',
                        default => 'warning',
                    }),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('stato')->label('Stato')->options(Noleggio::statiLabels()),
            ])
            ->actions([
                Tables\Actions\ViewAction::make(),
                Tables\Actions\EditAction::make(),
                static::azioneContratto(Tables\Actions\Action::make('contratto')),
                static::azioneInvio(Tables\Actions\Action::make('invia')),
            ])
            ->defaultSort('created_at', 'desc');
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
            ->icon('heroicon-o-document-arrow-down')
            ->color('gray')
            ->action(function (Noleggio $record) {
                $pdf = static::buildPdf($record);
                $nome = static::nomeFile($record);

                return response()->streamDownload(fn () => print($pdf->output()), $nome);
            });
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
        return 'noleggio-'.str($record->customer?->company_name ?: 'cliente')->slug().'.pdf';
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
            Forms\Components\RichEditor::make('custom_message')
                ->label('Testo email (modificabile)')
                ->toolbarButtons(['bold', 'italic', 'bulletList', 'orderedList', 'link', 'undo', 'redo'])
                ->default(fn (Noleggio $record) => static::testoEmail($record))
                ->helperText('La chiusura e i recapiti sono prestampati e non si modificano da qui.'),
        ];
    }

    public static function oggettoEmail(Noleggio $record): string
    {
        return 'Contratto di noleggio operativo — '
            .(DisplayName::titleCase($record->customer?->company_name) ?: 'proposta');
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
            ...($record->tenant?->notificationRecipients('quote') ?? []),
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

    public static function getPages(): array
    {
        return [
            'index' => NoleggioResource\Pages\ListNoleggi::route('/'),
            'view' => NoleggioResource\Pages\ViewNoleggio::route('/{record}'),
            'create' => NoleggioResource\Pages\CreateNoleggio::route('/create'),
            'edit' => NoleggioResource\Pages\EditNoleggio::route('/{record}/edit'),
        ];
    }
}
