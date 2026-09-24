<?php

namespace App\Filament\Pages;

use App\Exports\DailyTimeDetailExport;
use App\Exports\MonthlyTimeSummaryExport;
use App\Filament\Concerns\ApreStampeInNuovaScheda;
use App\Filament\Resources\TimeEntryResource;
use App\Models\LeaveRequest;
use App\Models\TimeEntry;
use App\Models\User;
use App\Support\Presenze\Festivi;
use App\Support\Presenze\GiornataLavorativa;
use Barryvdh\DomPDF\Facade\Pdf;
use BezhanSalleh\FilamentShield\Traits\HasPageShield;
use Carbon\Carbon;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\Select;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Pages\Page;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Facades\Excel;

/**
 * Riepilogo mensile ore per il commercialista (docs/architecture.md §12.2).
 * Ordinarie/straordinario derivati per giorno dalle timbrature confrontate
 * col monte ore contrattuale, non salvati per riga (una correzione a una
 * timbratura non lascia disallineamenti da ricalcolare altrove).
 */
class RiepilogoOre extends Page implements HasForms
{
    use ApreStampeInNuovaScheda, HasPageShield, InteractsWithForms;

    protected static ?string $navigationIcon = 'heroicon-o-document-chart-bar';

    protected static ?string $navigationGroup = 'Personale';


    protected static ?int $navigationSort = 2;

    protected static ?string $navigationLabel = 'Riepilogo ore';

    protected static string $view = 'filament.pages.riepilogo-ore';

    public ?int $month = null;

    public ?int $year = null;

    public function mount(): void
    {
        $this->month = (int) now()->month;
        $this->year = (int) now()->year;
    }

    protected function getFormSchema(): array
    {
        return [
            Select::make('month')
                ->label('Mese')
                ->options(collect(range(1, 12))->mapWithKeys(fn ($m) => [$m => Carbon::create(null, $m)->translatedFormat('F')]))
                ->live()
                ->extraAttributes(['data-tour' => 'riepilogo-ore-field-month']),
            Select::make('year')
                ->label('Anno')
                ->options(collect(range(now()->year - 2, now()->year))->mapWithKeys(fn ($y) => [$y => $y]))
                ->live()
                ->extraAttributes(['data-tour' => 'riepilogo-ore-field-year']),
        ];
    }

    /**
     * Ore lavorate per giorno (chiave Y-m-d) nel periodo, dalle timbrature
     * chiuse di TUTTI gli utenti passati, in una sola query (era 1 query per
     * utente: con N dipendenti diventavano N query ad ogni generazione del
     * riepilogo).
     *
     * @return Collection<string, Collection<string, float>> user_id -> (Y-m-d -> ore)
     */
    protected function bulkDailyWorkedHours(Collection $users, Carbon $start, Carbon $end): Collection
    {
        return TimeEntry::whereIn('user_id', $users->pluck('id'))
            ->whereNotNull('clock_out')
            ->whereBetween('clock_in', [$start, $end->copy()->endOfDay()])
            ->get()
            ->groupBy('user_id')
            ->map(fn (Collection $entries) => $entries
                ->groupBy(fn (TimeEntry $e) => $e->clock_in->format('Y-m-d'))
                ->map(fn (Collection $dayEntries) => $dayEntries->sum(
                    fn (TimeEntry $e) => $e->clock_in->diffInMinutes($e->clock_out) / 60
                )));
    }

    /**
     * I giorni di trasferta del periodo, per tutti gli utenti in una query.
     *
     * La trasferta si segna sul turno ma vale per la giornata: basta un turno
     * in trasferta perche' lo sia il giorno. I turni ancora aperti contano:
     * chi e' in trasferta oggi e non ha ancora timbrato l'uscita lo e' gia'.
     * Se due turni dello stesso giorno portano due destinazioni diverse, si
     * tengono entrambe.
     *
     * @return Collection<string, Collection<string, string>> user_id -> (Y-m-d -> destinazione)
     */
    protected function bulkTrasferte(Collection $users, Carbon $start, Carbon $end): Collection
    {
        return TimeEntry::whereIn('user_id', $users->pluck('id'))
            ->where('trasferta', true)
            ->whereBetween('clock_in', [$start, $end->copy()->endOfDay()])
            ->get()
            ->groupBy('user_id')
            ->map(fn (Collection $entries) => $entries
                ->groupBy(fn (TimeEntry $e) => $e->clock_in->format('Y-m-d'))
                ->map(fn (Collection $dayEntries) => $dayEntries
                    ->pluck('destinazione_trasferta')
                    ->filter()
                    ->unique()
                    ->implode(', ')));
    }

    /**
     * Ferie/permessi/malattie approvati che si sovrappongono al periodo, per
     * TUTTI gli utenti passati, in una sola query.
     *
     * @return Collection<string, Collection<int, LeaveRequest>> user_id -> richieste
     */
    protected function bulkApprovedLeaveRequests(Collection $users, Carbon $start, Carbon $end): Collection
    {
        return LeaveRequest::whereIn('user_id', $users->pluck('id'))
            ->where('status', 'approvato')
            ->where('date_from', '<=', $end)->where('date_to', '>=', $start)
            ->get()
            ->groupBy('user_id');
    }

    /**
     * Utenti visibili nel riepilogo. Un dipendente vede SOLO la propria riga:
     * la pagina e' nel suo perimetro (page_RiepilogoOre in RolePermissions)
     * perche' deve poter controllare le proprie ore, ma le ore/ferie/malattie
     * dei colleghi sono dati altrui e non deve ne' vederle a schermo ne'
     * scaricarle nei PDF/Excel. Stessa regola di visibilita' di
     * ScopesToOwnUserUnlessResponsabile (usata da TimeEntryResource e
     * LeaveRequestResource): tutto il tenant per responsabile
     * (is_super_admin/admin) e per "amministrazione", che il riepilogo lo
     * compila per il commercialista.
     *
     * @return Collection<int, User>
     */
    protected function visibleUsers(): Collection
    {
        $tenant = Filament::getTenant();
        $query = User::query()->where('tenant_id', $tenant?->id);
        $user = auth()->user();

        if ($user && ! TimeEntryResource::isResponsabile($user) && ! $user->hasRole('amministrazione')) {
            $query->whereKey($user->id);
        }

        return $query->get();
    }

    public function getRows(): Collection
    {
        $start = Carbon::create($this->year, $this->month, 1)->startOfMonth();
        $end = $start->copy()->endOfMonth();

        $users = $this->visibleUsers();
        $workedByUser = $this->bulkDailyWorkedHours($users, $start, $end);
        $leaveByUser = $this->bulkApprovedLeaveRequests($users, $start, $end);
        $trasferteByUser = $this->bulkTrasferte($users, $start, $end);

        return $users->map(fn (User $user) => $this->summaryRowFor(
            $user,
            $start,
            $end,
            $workedByUser->get($user->id, collect()),
            $leaveByUser->get($user->id, collect()),
            $trasferteByUser->get($user->id, collect()),
        ));
    }

    protected function summaryRowFor(User $user, Carbon $start, Carbon $end, Collection $dailyHours, Collection $leaveRequests, ?Collection $trasferte = null): array
    {
        $dailyContract = (float) $user->daily_contract_hours;
        $trasferte ??= collect();

        $ordinarie = 0.0;
        $straordinarioGiornaliero = 0.0;
        $ordinarieByWeek = [];

        foreach ($dailyHours as $day => $totalDay) {
            $giornata = GiornataLavorativa::ripartisci((float) $totalDay, $dailyContract, $trasferte->has($day));

            $ordinarie += $giornata->ordinarie;
            $straordinarioGiornaliero += $giornata->straordinario;
            $week = Carbon::parse($day)->isoWeek;
            $ordinarieByWeek[$week] = ($ordinarieByWeek[$week] ?? 0) + $giornata->ordinarie;
        }

        // Straordinario settimanale (docs/architecture.md §12.1): un dipendente
        // che ogni giorno resta esattamente nel monte giornaliero non genera
        // mai straordinario "giornaliero", ma se la somma settimanale supera
        // weekly_contract_hours e' comunque straordinario. Le ore eccedenti si
        // spostano da ordinarie a straordinario (non si aggiungono, altrimenti
        // si conterebbero due volte le stesse ore).
        $weeklyContract = (float) $user->weekly_contract_hours;
        $straordinarioSettimanale = 0.0;

        if ($weeklyContract > 0) {
            foreach ($ordinarieByWeek as $weekTotal) {
                $straordinarioSettimanale += max(0, $weekTotal - $weeklyContract);
            }
            $ordinarie -= $straordinarioSettimanale;
        }

        $straordinario = $straordinarioGiornaliero + $straordinarioSettimanale;

        $ferieGiorni = $leaveRequests->where('type', 'ferie')
            ->sum(fn (LeaveRequest $lr) => $lr->daysWithin($start, $end));

        // Malattia mancava dal riepilogo mensile inviato al commercialista:
        // il tipo esiste ed e' richiedibile ma non veniva mai sommato qui.
        $malattiaGiorni = $leaveRequests->where('type', 'malattia')
            ->sum(fn (LeaveRequest $lr) => $lr->daysWithin($start, $end));

        $permessiOre = (float) $leaveRequests->where('type', 'permesso')
            ->filter(fn (LeaveRequest $lr) => $lr->date_from->between($start, $end))
            ->sum('hours');

        return [
            'user' => $user->name,
            'ordinarie' => round($ordinarie, 2),
            'straordinario' => round($straordinario, 2),
            'ferie_giorni' => $ferieGiorni,
            'malattia_giorni' => $malattiaGiorni,
            'permessi_ore' => round($permessiOre, 2),
            'trasferta_giorni' => $trasferte->count(),
        ];
    }

    /**
     * Totale di riga in fondo alla tabella: utile al commercialista per un
     * controllo rapido senza dover sommare a mano tutte le righe.
     */
    public function getTotals(Collection $rows): array
    {
        return [
            'ordinarie' => round($rows->sum('ordinarie'), 2),
            'straordinario' => round($rows->sum('straordinario'), 2),
            'ferie_giorni' => round($rows->sum('ferie_giorni'), 2),
            'malattia_giorni' => round($rows->sum('malattia_giorni'), 2),
            'permessi_ore' => round($rows->sum('permessi_ore'), 2),
            'trasferta_giorni' => $rows->sum('trasferta_giorni'),
        ];
    }

    /**
     * Dettaglio giorno per giorno (docs/architecture.md §12.1): richiesto per
     * poter verificare una singola giornata invece del solo aggregato
     * mensile. Lo straordinario settimanale resta solo nel riepilogo mensile
     * (getRows/summaryRowFor): attribuirlo a un giorno specifico qui sarebbe
     * arbitrario, quindi ogni riga mostra lo straordinario "giornaliero" puro.
     */
    public function getDailyDetailRows(): Collection
    {
        $start = Carbon::create($this->year, $this->month, 1)->startOfMonth();
        $end = $start->copy()->endOfMonth();

        $users = $this->visibleUsers();
        $workedByUser = $this->bulkDailyWorkedHours($users, $start, $end);
        $leaveByUser = $this->bulkApprovedLeaveRequests($users, $start, $end);
        $trasferteByUser = $this->bulkTrasferte($users, $start, $end);

        $rows = collect();

        foreach ($users as $user) {
            $dailyHours = $workedByUser->get($user->id, collect());
            $dailyContract = (float) $user->daily_contract_hours;
            $leaveRequests = $leaveByUser->get($user->id, collect());
            $trasferte = $trasferteByUser->get($user->id, collect());

            for ($day = $start->copy(); $day->lte($end); $day->addDay()) {
                $key = $day->format('Y-m-d');
                $worked = (float) ($dailyHours[$key] ?? 0);

                $leave = $leaveRequests->first(
                    fn (LeaveRequest $lr) => $day->between($lr->date_from, $lr->date_to)
                );

                $inTrasferta = $trasferte->has($key);

                // Sabati, domeniche e festivi non si scalano dalle ferie (vedi
                // LeaveRequest::daysWithin()): nel dettaglio non vanno segnati.
                if ($leave?->type === LeaveRequest::TYPE_FERIE && Festivi::isNonLavorativo($day)) {
                    $leave = null;
                }

                if ($worked <= 0 && ! $leave && ! $inTrasferta) {
                    continue;
                }

                $giornata = GiornataLavorativa::ripartisci($worked, $dailyContract, $inTrasferta);

                $rows->push([
                    'user' => $user->name,
                    'date' => $day->copy(),
                    'ore_lavorate' => round($worked, 2),
                    'ordinarie' => round($giornata->ordinarie, 2),
                    'straordinario' => round($giornata->straordinario, 2),
                    // null = niente trasferta. "Sì" quando la trasferta c'e' ma
                    // la destinazione no: il campo e' obbligatorio solo dal form.
                    'trasferta' => $inTrasferta ? ($trasferte[$key] ?: 'Sì') : null,
                    'assenza' => match ($leave?->type) {
                        'ferie' => 'Ferie',
                        'permesso' => 'Permesso ('.number_format((float) $leave->hours, 2).' h)',
                        'malattia' => 'Malattia',
                        default => null,
                    },
                ]);
            }
        }

        return $rows->sortBy(['user', 'date'])->values();
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('exportExcel')
                ->label('Esporta Excel (riepilogo)')
                ->icon('heroicon-o-table-cells')
                ->extraAttributes(['data-tour' => 'riepilogo-ore-export'])
                ->action(fn () => Excel::download(
                    new MonthlyTimeSummaryExport($this->getRows()),
                    "riepilogo-ore-{$this->year}-{$this->month}.xlsx"
                )),
            Action::make('exportPdf')
                ->label('Esporta PDF (riepilogo)')
                ->icon('heroicon-o-document-arrow-down')
                ->action(fn () => static::apriPdfInNuovaScheda(
                    fn () => Pdf::loadView('pdf.riepilogo-ore', [
                        'rows' => $this->getRows(),
                        'month' => $this->month,
                        'year' => $this->year,
                        'tenant' => Filament::getTenant(),
                    ]),
                    "riepilogo-ore-{$this->year}-{$this->month}.pdf",
                    $this,
                )),
            // Dettaglio giorno per giorno, in aggiunta al riepilogo mensile
            // aggregato: serve per verificare una singola giornata invece di
            // dover ricostruirla a mano dalle timbrature.
            Action::make('exportDetailExcel')
                ->label('Esporta Excel (dettaglio giorni)')
                ->color('gray')
                ->icon('heroicon-o-table-cells')
                ->action(fn () => Excel::download(
                    new DailyTimeDetailExport($this->getDailyDetailRows()),
                    "dettaglio-ore-{$this->year}-{$this->month}.xlsx"
                )),
            Action::make('exportDetailPdf')
                ->label('Esporta PDF (dettaglio giorni)')
                ->color('gray')
                ->icon('heroicon-o-document-arrow-down')
                ->action(fn () => static::apriPdfInNuovaScheda(
                    fn () => Pdf::loadView('pdf.dettaglio-ore', [
                        'rows' => $this->getDailyDetailRows(),
                        'month' => $this->month,
                        'year' => $this->year,
                        'tenant' => Filament::getTenant(),
                    ]),
                    "dettaglio-ore-{$this->year}-{$this->month}.pdf",
                    $this,
                )),
        ];
    }
}
