<?php

namespace App\Filament\Pages;

use App\Models\Customer;
use App\Models\MachineUnit;
use App\Support\DisplayName;
use Filament\Pages\Page;
use Filament\Tables;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Chi paga per chi: i torrefattori e i gestori che si accollano il costo
 * delle macchine, e presso quali clienti stanno quelle macchine.
 *
 * Serve a due cose che oggi si fanno a mano. La prima e' mandare a
 * Martellozzo l'elenco di quello per cui gli si fattura — 29 clienti e 33
 * macchine — invece di ricostruirlo cliente per cliente. La seconda e'
 * accorgersi degli errori: e' guardando questa lista che si vede che
 * "Biennale Giardini Chiosco" e "Biennale Giardini Terrazza" hanno i paganti
 * incrociati fra loro.
 *
 * Si legge dalle MACCHINE, non dai clienti: li' il pagante viene dagli
 * installati di Eureka ed e' un dato, mentre sul cliente era un'inferenza
 * (vedi clienti:pulisci-pagante). Ed e' anche la verita' commerciale: il
 * torrefattore paga per la macchina che ha dato in comodato, non per tutto
 * quello che si fa da quel cliente.
 *
 * Pagina e non Resource: la tabella e' un'aggregazione (GROUP BY pagante) e
 * l'astrazione Resource assume una riga per record.
 */
class PagantiMacchine extends Page implements HasTable
{
    use InteractsWithTable;

    protected static ?string $navigationIcon = 'heroicon-o-building-office-2';

    protected static ?string $navigationGroup = 'Interventi tecnici';

    protected static ?int $navigationSort = 9;

    protected static ?string $navigationLabel = 'Chi paga per chi';

    protected static ?string $title = 'Chi paga per chi';

    protected static string $view = 'filament.pages.paganti-macchine';

    protected static ?string $slug = 'chi-paga';

    public static function canAccess(): bool
    {
        return auth()->user()?->can('viewAny', MachineUnit::class) ?? false;
    }

    public static function shouldRegisterNavigation(): bool
    {
        return static::canAccess();
    }

    public function getSubheading(): ?string
    {
        return 'Torrefattori e gestori che pagano per le macchine installate presso i clienti. Il dato viene dalla singola macchina, non dall\'anagrafica.';
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(fn (): Builder => Customer::query()
                ->whereIn('id', MachineUnit::query()
                    ->whereNotNull('billing_customer_id')
                    ->whereNull('deleted_at')
                    ->select('billing_customer_id'))
                ->withCount([
                    'macchinePagate as macchine_count',
                    'macchinePagate as clienti_count' => fn ($q) => $q->select(
                        \Illuminate\Support\Facades\DB::raw('count(distinct current_customer_id)'),
                    ),
                ]))
            ->defaultSort('macchine_count', 'desc')
            ->emptyStateHeading('Nessun pagante')
            ->emptyStateDescription('Nessuna macchina risulta pagata da un soggetto diverso dal cliente presso cui si trova.')
            ->columns([
                Tables\Columns\TextColumn::make('company_name')
                    ->label('Paga')
                    ->formatStateUsing(fn (?string $state) => DisplayName::titleCase($state))
                    ->searchable()
                    ->weight('medium'),

                Tables\Columns\TextColumn::make('clienti_count')
                    ->label('Clienti')
                    ->sortable()
                    ->alignRight(),

                Tables\Columns\TextColumn::make('macchine_count')
                    ->label('Macchine')
                    ->sortable()
                    ->alignRight(),
            ])
            ->actions([
                Tables\Actions\Action::make('dettaglio')
                    ->label('Dettaglio')
                    ->icon('heroicon-o-eye')
                    ->color('gray')
                    ->url(fn (Customer $record) => DettaglioPagante::getUrl(['pagante' => $record->getKey()])),

                Tables\Actions\Action::make('stampa')
                    ->label('Stampa')
                    ->icon('heroicon-o-printer')
                    ->color('gray')
                    ->url(fn (Customer $record) => route('paganti.stampa', [
                        'pagante' => $record->getKey(),
                        'tenant' => \Filament\Facades\Filament::getTenant()?->getKey(),
                    ]))
                    ->openUrlInNewTab(),
            ])
            ->paginated([25, 50, 100]);
    }
}
