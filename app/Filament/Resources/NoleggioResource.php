<?php

namespace App\Filament\Resources;

use App\Models\Customer;
use App\Models\MachineUnit;
use App\Models\Noleggio;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Resources\Resource;
use Filament\Support\RawJs;
use Filament\Tables;
use Filament\Tables\Table;
use App\Support\OutsideLivewireRender;
use Barryvdh\DomPDF\Facade\Pdf;
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
                    Forms\Components\Select::make('customer_id')
                        ->label('Cliente')
                        ->relationship('customer', 'company_name')
                        ->searchable()->preload()->required(),
                    Forms\Components\TextInput::make('descrizione')
                        ->label('Oggetto del noleggio')
                        ->helperText('Come comparira\' sul contratto, es. "Franke A600 FM Plus con unita\' di raffreddamento".')
                        ->required()->maxLength(255),
                    Forms\Components\Select::make('machine_unit_id')
                        ->label('Macchina (se gia\' individuata)')
                        ->options(fn (Get $get) => $get('customer_id')
                            ? MachineUnit::query()->where('current_customer_id', $get('customer_id'))
                                ->whereNull('deleted_at')->pluck('serial_number', 'id')->all()
                            : [])
                        ->searchable(),
                    Forms\Components\Select::make('quote_id')
                        ->label('Preventivo di riferimento')
                        ->relationship('quote', 'number')
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
                    Forms\Components\TextInput::make('valore_residuo')
                        ->label('Valore residuo a fine contratto (€)')
                        ->helperText('Se la macchina torna a voi e vale ancora qualcosa, il canone scende.')
                        ->numeric()->default(0)->live(onBlur: true),
                    Forms\Components\TextInput::make('full_service_percentuale')
                        ->label('Full-Service (% annuo del listino)')
                        ->numeric()->default(10)->live(onBlur: true),
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
            'valore_residuo' => (float) $get('valore_residuo'),
            'full_service_percentuale' => (float) ($get('full_service_percentuale') ?: 10),
        ]);
        $r = $n->ricalcola();
        $eur = fn ($v) => '€ '.number_format((float) $v, 2, ',', '.');
        $meta = (int) max(1, round($r->mesi / 2));

        $righe = [
            ['Quota macchina', $eur($r->quotaMacchina)],
            ['Full-Service', $eur($r->quotaServizio)],
            ['Detergenti', $eur($r->quotaDetergenti)],
        ];
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
                Tables\Actions\EditAction::make(),
                static::azioneContratto(Tables\Actions\Action::make('contratto')),
            ])
            ->defaultSort('created_at', 'desc');
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
                $pdf = OutsideLivewireRender::run(fn () => Pdf::loadView('pdf.noleggio', [
                    'noleggio' => $record->load(['customer', 'machineUnit']),
                    'tenant' => $record->tenant,
                ]));

                $nome = 'noleggio-'.str($record->customer?->company_name ?: 'cliente')->slug().'.pdf';

                return response()->streamDownload(fn () => print($pdf->output()), $nome);
            });
    }

    public static function getPages(): array
    {
        return [
            'index' => NoleggioResource\Pages\ListNoleggi::route('/'),
            'create' => NoleggioResource\Pages\CreateNoleggio::route('/create'),
            'edit' => NoleggioResource\Pages\EditNoleggio::route('/{record}/edit'),
        ];
    }
}
