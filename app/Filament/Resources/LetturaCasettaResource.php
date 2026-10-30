<?php

namespace App\Filament\Resources;

use App\Exports\LettureCasetteExport;
use App\Filament\Resources\LetturaCasettaResource\Pages;
use App\Models\LetturaCasetta;
use App\Models\MachineUnit;
use App\Models\User;
use App\Support\DisplayName;
use Closure;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Facades\Excel;

/**
 * Le letture delle casette dell'acqua (30/10/2026).
 *
 * Due casette comunali (Cessalto, Casale sul Sile) e un giro nuovo per i
 * tecnici: tre contatori da leggere, quattro operazioni da spuntare, tre
 * display da fotografare. Da qui esce il rendiconto che il Comune riceve.
 *
 * La pagina e' scritta per il telefono, in piedi davanti alla casetta: una
 * colonna sola, i numeri nell'ordine in cui si leggono sulla macchina, e
 * sotto ogni numero quanto fa la differenza con la volta prima. Se il
 * tecnico batte 3490 invece di 349 lo vede subito li', non a fine mese
 * quando il rendiconto e' gia' partito.
 *
 * Le foto stanno dentro la lettura e hanno un nome (contatore, incasso,
 * venduto). Le tre arrivate su WhatsApp il 30/10/2026 non avevano ne' data
 * negli EXIF ne' posizione ne' un nome che dicesse qualcosa: erano
 * attribuibili solo ricordandosi dove fosse stato il tecnico quel giorno.
 */
class LetturaCasettaResource extends Resource
{
    protected static ?string $model = LetturaCasetta::class;

    protected static ?string $navigationIcon = 'heroicon-o-beaker';

    protected static ?string $navigationGroup = 'Interventi tecnici';

    protected static ?int $navigationSort = 4;

    protected static ?string $navigationLabel = 'Casette dell\'acqua';

    protected static ?string $modelLabel = 'lettura';

    protected static ?string $pluralModelLabel = 'letture delle casette';

    protected static bool $hasTitleCaseModelLabel = false;

    protected static ?string $slug = 'casette-acqua';

    /** @var Collection<string, string>|null */
    protected static ?Collection $casetteInCache = null;

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make('Dove e quando')
                ->columns(['default' => 1, 'lg' => 3])
                ->schema([
                    Forms\Components\Select::make('machine_unit_id')
                        ->label('Casetta')
                        ->options(fn () => static::casette())
                        ->default(fn () => static::casette()->count() === 1 ? static::casette()->keys()->first() : null)
                        ->searchable()
                        ->required()
                        ->live()
                        ->helperText('Sono le casette censite come impianto acqua.'),
                    Forms\Components\DatePicker::make('data')
                        ->label('Giorno')
                        ->required()
                        ->native(false)
                        ->displayFormat('d/m/Y')
                        ->default(now())
                        ->live()
                        // Due letture della stessa casetta nello stesso giorno
                        // raddoppiano una riga del rendiconto e azzerano la
                        // differenza di periodo: meglio dirlo subito.
                        ->rule(static fn (Get $get, ?LetturaCasetta $record) => static function (string $attributo, $valore, Closure $errore) use ($get, $record) {
                            $casetta = $get('machine_unit_id');

                            if (! $valore || ! $casetta) {
                                return;
                            }

                            $gia = LetturaCasetta::query()
                                ->where('machine_unit_id', $casetta)
                                ->whereDate('data', $valore)
                                ->when($record, fn (Builder $q) => $q->whereKeyNot($record->getKey()))
                                ->exists();

                            if ($gia) {
                                $errore('Per questa casetta c\'è già una lettura in questa data: correggi quella invece di aggiungerne un\'altra.');
                            }
                        }),
                    Forms\Components\Select::make('rilevata_da')
                        ->label('Chi l\'ha fatta')
                        ->options(fn () => User::query()
                            ->where('is_active', true)
                            ->orderBy('name')
                            ->pluck('name', 'id')
                            ->map(fn (?string $nome): ?string => DisplayName::titleCase($nome))
                            ->all())
                        ->default(fn () => auth()->id())
                        ->searchable()
                        ->preload(),
                ]),

            Forms\Components\Section::make('I tre contatori')
                ->description('Nell\'ordine in cui si leggono sulla casetta. Sotto ogni numero, quanto fa dalla volta prima.')
                ->columns(['default' => 1, 'lg' => 3])
                ->schema([
                    Forms\Components\TextInput::make('contatore_acqua')
                        ->label('Acqua erogata (contatore)')
                        ->numeric()
                        ->step('0.01')
                        ->minValue(0)
                        ->suffix(fn (Get $get) => static::unitaDi($get('machine_unit_id')))
                        ->live(onBlur: true)
                        ->helperText(fn (Get $get, ?LetturaCasetta $record) => static::differenza($get, $record, 'contatore_acqua', static::unitaDi($get('machine_unit_id')))),
                    Forms\Components\TextInput::make('incasso_totale')
                        ->label('Incasso totale')
                        ->numeric()
                        ->step('0.01')
                        ->minValue(0)
                        ->prefix('€')
                        ->live(onBlur: true)
                        ->helperText(fn (Get $get, ?LetturaCasetta $record) => static::differenza($get, $record, 'incasso_totale', '€')),
                    Forms\Components\TextInput::make('valore_vendite')
                        ->label('Venduto totale')
                        ->numeric()
                        ->step('0.01')
                        ->minValue(0)
                        ->prefix('€')
                        ->live(onBlur: true)
                        ->helperText(fn (Get $get, ?LetturaCasetta $record) => static::differenza($get, $record, 'valore_vendite', '€')),
                ]),

            Forms\Components\Section::make('Che cosa è stato fatto')
                ->columns(['default' => 1, 'lg' => 2])
                ->schema(
                    collect(LetturaCasetta::OPERAZIONI)
                        ->map(fn (string $etichetta, string $colonna) => Forms\Components\Toggle::make($colonna)
                            ->label($etichetta)
                            ->inline(false))
                        ->values()
                        ->all()
                ),

            Forms\Components\Section::make('Le foto')
                ->description('Scattate davanti alla casetta. Ognuna sta sotto il numero che documenta: una foto di un display senza sapere quale vale poco.')
                ->columns(['default' => 1, 'lg' => 3])
                ->schema([
                    static::foto('foto_contatore', 'Contatore dell\'acqua'),
                    static::foto('foto_incasso', 'Display incasso'),
                    static::foto('foto_vendite', 'Display venduto'),
                    Forms\Components\FileUpload::make('foto_altre')
                        ->label('Altre foto')
                        ->helperText('Filtro cambiato, guasto, cartello: quello che serve a far capire.')
                        ->image()
                        ->multiple()
                        ->disk('local')
                        ->directory('letture-casette')
                        ->maxSize(20480)
                        ->columnSpanFull(),
                ]),

            Forms\Components\Textarea::make('note')
                ->label('Note')
                ->rows(3)
                ->columnSpanFull(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('data', 'desc')
            ->emptyStateHeading('Nessuna lettura')
            ->emptyStateDescription('Le letture delle casette comunali: contatore, incasso, venduto, più le foto dei display.')
            ->columns([
                Tables\Columns\TextColumn::make('data')
                    ->label('Giorno')
                    ->date('d/m/Y')
                    ->sortable(),
                Tables\Columns\TextColumn::make('machineUnit.serial_number')
                    ->label('Casetta')
                    ->description(fn (LetturaCasetta $r) => $r->customer?->company_name)
                    ->wrap()
                    ->searchable(),
                Tables\Columns\TextColumn::make('contatore_acqua')
                    ->label('Contatore')
                    ->numeric(decimalPlaces: 2, decimalSeparator: ',', thousandsSeparator: '.')
                    ->suffix(fn (LetturaCasetta $r) => ' '.$r->unitaBreve())
                    ->description(fn (LetturaCasetta $r) => static::descrizionePeriodo($r->erogato(), $r->unitaBreve()))
                    ->placeholder('—'),
                Tables\Columns\TextColumn::make('incasso_totale')
                    ->label('Incasso')
                    ->money('EUR')
                    ->description(fn (LetturaCasetta $r) => static::descrizionePeriodo($r->incassoPeriodo(), '€'))
                    ->placeholder('—'),
                Tables\Columns\TextColumn::make('valore_vendite')
                    ->label('Venduto')
                    ->money('EUR')
                    ->description(fn (LetturaCasetta $r) => static::descrizionePeriodo($r->vendutoPeriodo(), '€'))
                    ->placeholder('—')
                    ->toggleable(),
                // I due contatori dicono la stessa cosa da due parti: se non
                // coincidono c'e' qualcosa da spiegare prima di mandare il
                // rendiconto.
                Tables\Columns\TextColumn::make('scarto')
                    ->label('Scarto')
                    ->state(fn (LetturaCasetta $r) => $r->scarto())
                    ->formatStateUsing(fn (?float $s) => $s === null ? null : number_format($s, 2, ',', '.').' €')
                    ->badge()
                    ->color(fn (?float $s) => $s === null || abs($s) < 0.01 ? 'gray' : 'warning')
                    ->tooltip('Incasso del periodo meno venduto del periodo')
                    ->placeholder('—'),
                Tables\Columns\TextColumn::make('operazioni')
                    ->label('Fatto')
                    ->state(fn (LetturaCasetta $r) => static::operazioniBrevi($r))
                    ->badge()
                    ->placeholder('—'),
                Tables\Columns\TextColumn::make('filtro')
                    ->label('Filtro')
                    ->state(fn (LetturaCasetta $r) => static::statoFiltro($r))
                    ->badge()
                    ->color(fn (LetturaCasetta $r) => static::coloreFiltro($r))
                    ->placeholder('—')
                    ->toggleable(),
                Tables\Columns\TextColumn::make('foto')
                    ->label('Foto')
                    ->state(fn (LetturaCasetta $r) => $r->quanteFoto() ?: null)
                    ->icon('heroicon-m-camera')
                    ->placeholder('nessuna')
                    ->color(fn (LetturaCasetta $r) => $r->quanteFoto() ? null : 'warning'),
                Tables\Columns\TextColumn::make('rilevataDa.name')
                    ->label('Tecnico')
                    ->formatStateUsing(fn (?string $n) => DisplayName::titleCase($n))
                    ->placeholder('—')
                    ->toggleable(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('machine_unit_id')
                    ->label('Casetta')
                    ->options(fn () => static::casette()),
                Tables\Filters\Filter::make('periodo')
                    ->form([
                        Forms\Components\DatePicker::make('dal')->label('Dal')->native(false)->displayFormat('d/m/Y'),
                        Forms\Components\DatePicker::make('al')->label('Al')->native(false)->displayFormat('d/m/Y'),
                    ])
                    // Il parametro si DEVE chiamare $data: Filament risolve le
                    // dipendenze di queste closure per nome, e con $dati
                    // l'elenco andava in errore 500 appena aperto
                    // (30/10/2026, in produzione).
                    ->query(fn (Builder $q, array $data) => $q
                        ->when($data['dal'] ?? null, fn (Builder $q, string $dal) => $q->whereDate('data', '>=', $dal))
                        ->when($data['al'] ?? null, fn (Builder $q, string $al) => $q->whereDate('data', '<=', $al)))
                    ->indicateUsing(function (array $data): ?string {
                        $dal = ($data['dal'] ?? null) ? Carbon::parse($data['dal'])->format('d/m/Y') : null;
                        $al = ($data['al'] ?? null) ? Carbon::parse($data['al'])->format('d/m/Y') : null;

                        return match (true) {
                            $dal && $al => "Dal {$dal} al {$al}",
                            (bool) $dal => "Dal {$dal}",
                            (bool) $al => "Fino al {$al}",
                            default => null,
                        };
                    }),
                Tables\Filters\Filter::make('senza_foto')
                    ->label('Senza foto')
                    ->query(fn (Builder $q) => $q
                        ->whereNull('foto_contatore')
                        ->whereNull('foto_incasso')
                        ->whereNull('foto_vendite')),
                Tables\Filters\Filter::make('filtro_cambiato')
                    ->label('Con cambio filtro')
                    ->query(fn (Builder $q) => $q->where('sostituzione_filtro', true)),
            ])
            ->headerActions([
                // Il rendiconto che va al Comune: un foglio per casetta, con
                // le differenze di periodo al posto dei contatori progressivi.
                Tables\Actions\Action::make('esporta')
                    ->label('Rendiconto Excel')
                    ->icon('heroicon-o-table-cells')
                    ->action(fn ($livewire) => Excel::download(
                        new LettureCasetteExport(
                            $livewire->getFilteredSortedTableQuery()
                                ->with(['machineUnit', 'customer', 'rilevataDa'])
                                ->get()
                        ),
                        'rendiconto-casette-'.now()->format('Y-m-d').'.xlsx',
                    )),
            ])
            ->actions([
                Tables\Actions\ActionGroup::make([
                    Tables\Actions\EditAction::make(),
                    Tables\Actions\DeleteAction::make(),
                ]),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ]);
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with(['machineUnit', 'customer']);
    }

    /**
     * Le casette censite: MachineUnit di tipo impianto acqua, col Comune
     * accanto -- in un elenco di matricole tutte uguali
     * (CASETTA-ACQUA-Y5GBSF) il nome del cliente e' l'unica cosa che si
     * riconosce.
     *
     * @return Collection<string, string>
     */
    public static function casette(): Collection
    {
        // Memorizzata: il form la chiede tre volte per disegnare un solo
        // campo (opzioni, valore di default, filtro dell'elenco).
        if (static::$casetteInCache instanceof Collection) {
            return static::$casetteInCache;
        }

        return static::$casetteInCache = MachineUnit::query()
            ->where('machine_units.type', MachineUnit::TYPE_IMPIANTO_ACQUA)
            ->leftJoin('customers', 'customers.id', '=', 'machine_units.current_customer_id')
            ->orderBy('machine_units.serial_number')
            ->get(['machine_units.id', 'machine_units.serial_number', 'customers.company_name as comune'])
            ->mapWithKeys(fn (MachineUnit $m) => [
                (string) $m->getKey() => trim($m->serial_number.' — '.($m->comune ?? 'senza cliente')),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListLettureCasette::route('/'),
            'create' => Pages\CreateLetturaCasetta::route('/create'),
            'edit' => Pages\EditLetturaCasetta::route('/{record}/edit'),
        ];
    }

    protected static function foto(string $campo, string $etichetta): Forms\Components\FileUpload
    {
        // Disco `local` (storage/app/private): non sono documenti riservati ma
        // non hanno motivo di stare a un URL indovinabile. Si aprono dal
        // pannello.
        return Forms\Components\FileUpload::make($campo)
            ->label($etichetta)
            ->image()
            ->disk('local')
            ->directory('letture-casette')
            ->maxSize(20480);
    }

    /**
     * Quanto fa la differenza con la lettura precedente, mentre il tecnico
     * sta ancora battendo il numero. E' il controllo che manca ai fogli di
     * calcolo: uno zero di troppo si vede qui, non a fine mese.
     */
    protected static function differenza(Get $get, ?LetturaCasetta $record, string $colonna, string $unita): ?string
    {
        $casetta = $get('machine_unit_id');
        $valore = $get($colonna);

        if (! $casetta || $valore === null || $valore === '') {
            return null;
        }

        $precedente = LetturaCasetta::query()
            ->where('machine_unit_id', $casetta)
            ->whereNotNull($colonna)
            // In modifica il confronto non va fatto con se stessa: senza
            // questo, correggere una lettura mostrava sempre "+0".
            ->when($record, fn (Builder $q) => $q->whereKeyNot($record->getKey()))
            ->when($get('data'), fn (Builder $q, $data) => $q->whereDate('data', '<=', $data))
            ->orderByDesc('data')
            ->orderByDesc('created_at')
            ->first();

        if (! $precedente) {
            return 'Prima lettura di questa casetta: nessun confronto.';
        }

        $differenza = (float) $valore - (float) $precedente->{$colonna};
        $quando = $precedente->data->format('d/m/Y');

        if ($differenza < 0) {
            return "Attenzione: meno del {$quando} (".number_format($precedente->{$colonna}, 2, ',', '.')." {$unita}). Contatore azzerato o numero sbagliato?";
        }

        return '+'.number_format($differenza, 2, ',', '.')." {$unita} dal {$quando}.";
    }

    protected static function unitaDi(?string $machineUnitId): string
    {
        if (! $machineUnitId) {
            return 'm³';
        }

        $unita = MachineUnit::query()->whereKey($machineUnitId)->value('unita_contatore');

        return $unita === LetturaCasetta::UNITA_LITRI ? 'l' : 'm³';
    }

    protected static function descrizionePeriodo(?float $valore, string $unita): ?string
    {
        if ($valore === null) {
            return null;
        }

        return ($valore >= 0 ? '+' : '').number_format($valore, 2, ',', '.').' '.$unita.' nel periodo';
    }

    /** @return array<int, string> */
    protected static function operazioniBrevi(LetturaCasetta $lettura): array
    {
        return collect([
            'controllo_pulizia' => 'Pulizia',
            'sanificazione' => 'Sanificazione',
            'ricarica_co2' => 'CO2',
            'sostituzione_filtro' => 'Filtro',
        ])
            ->filter(fn (string $breve, string $colonna) => (bool) $lettura->{$colonna})
            ->values()
            ->all();
    }

    protected static function statoFiltro(LetturaCasetta $lettura): ?string
    {
        $manca = $lettura->mancaAlFiltro();

        if ($manca === null) {
            return null;
        }

        $unita = $lettura->unitaBreve();

        return $manca <= 0
            ? 'da cambiare ('.number_format(abs($manca), 0, ',', '.')." {$unita} oltre)"
            : 'fra '.number_format($manca, 0, ',', '.')." {$unita}";
    }

    protected static function coloreFiltro(LetturaCasetta $lettura): ?string
    {
        $manca = $lettura->mancaAlFiltro();
        $autonomia = (float) ($lettura->machineUnit->filtro_ogni ?? 0);

        if ($manca === null) {
            return null;
        }

        if ($manca <= 0) {
            return 'danger';
        }

        // L'ultimo decimo dell'autonomia: il momento di caricare il filtro
        // sul furgone prima di partire, non quello di tornare indietro.
        return $autonomia > 0 && $manca <= $autonomia / 10 ? 'warning' : 'success';
    }
}
