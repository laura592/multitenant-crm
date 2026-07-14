<?php

namespace App\Filament\Pages;

use App\Exports\MonthlyTimeSummaryExport;
use App\Models\LeaveRequest;
use App\Models\TimeEntry;
use App\Models\User;
use Barryvdh\DomPDF\Facade\Pdf;
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
    use InteractsWithForms;

    protected static ?string $navigationIcon = 'heroicon-o-document-chart-bar';

    protected static ?string $navigationGroup = 'Gestione';

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
                ->live(),
            Select::make('year')
                ->label('Anno')
                ->options(collect(range(now()->year - 2, now()->year))->mapWithKeys(fn ($y) => [$y => $y]))
                ->live(),
        ];
    }

    public function getRows(): Collection
    {
        $start = Carbon::create($this->year, $this->month, 1)->startOfMonth();
        $end = $start->copy()->endOfMonth();
        $tenant = Filament::getTenant();

        $users = User::query()->where('tenant_id', $tenant?->id)->get();

        return $users->map(function (User $user) use ($start, $end) {
            $entriesByDay = TimeEntry::where('user_id', $user->id)
                ->whereNotNull('clock_out')
                ->whereBetween('clock_in', [$start, $end->copy()->endOfDay()])
                ->get()
                ->groupBy(fn (TimeEntry $e) => $e->clock_in->format('Y-m-d'));

            $ordinarie = 0.0;
            $straordinario = 0.0;

            foreach ($entriesByDay as $dayEntries) {
                $totalDay = $dayEntries->sum(fn (TimeEntry $e) => $e->clock_in->diffInMinutes($e->clock_out) / 60);
                $ordinarie += min($totalDay, (float) $user->daily_contract_hours);
                $straordinario += max(0, $totalDay - (float) $user->daily_contract_hours);
            }

            $ferieGiorni = LeaveRequest::where('user_id', $user->id)
                ->where('type', 'ferie')->where('status', 'approvato')
                ->where('date_from', '<=', $end)->where('date_to', '>=', $start)
                ->get()
                ->sum(fn (LeaveRequest $lr) => $lr->days);

            $permessiOre = (float) LeaveRequest::where('user_id', $user->id)
                ->where('type', 'permesso')->where('status', 'approvato')
                ->whereBetween('date_from', [$start, $end])
                ->sum('hours');

            return [
                'user' => $user->name,
                'ordinarie' => round($ordinarie, 2),
                'straordinario' => round($straordinario, 2),
                'ferie_giorni' => $ferieGiorni,
                'permessi_ore' => round($permessiOre, 2),
            ];
        });
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('exportExcel')
                ->label('Esporta Excel')
                ->icon('heroicon-o-table-cells')
                ->action(fn () => Excel::download(
                    new MonthlyTimeSummaryExport($this->getRows()),
                    "riepilogo-ore-{$this->year}-{$this->month}.xlsx"
                )),
            Action::make('exportPdf')
                ->label('Esporta PDF')
                ->icon('heroicon-o-document-arrow-down')
                ->action(function () {
                    $pdf = Pdf::loadView('pdf.riepilogo-ore', [
                        'rows' => $this->getRows(),
                        'month' => $this->month,
                        'year' => $this->year,
                    ]);

                    return response()->streamDownload(
                        fn () => print ($pdf->output()),
                        "riepilogo-ore-{$this->year}-{$this->month}.pdf"
                    );
                }),
        ];
    }
}
