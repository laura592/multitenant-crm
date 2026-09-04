<?php

namespace App\Filament\Pages;

use App\Models\Customer;
use App\Models\MachineUnit;
use App\Support\DisplayName;
use Filament\Actions;
use Filament\Facades\Filament;
use Filament\Pages\Page;
use Filament\Tables;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Le macchine che un singolo pagante si accolla, cliente per cliente.
 *
 * E' l'elenco da mandare al torrefattore quando chiede "per cosa mi
 * fatturate", e quello su cui si scoprono gli errori: le macchine di due
 * sedi vicine finite sotto il gestore sbagliato si vedono qui e non altrove.
 */
class DettaglioPagante extends Page implements HasTable
{
    use InteractsWithTable;

    protected static bool $shouldRegisterNavigation = false;

    protected static string $view = 'filament.pages.paganti-macchine';

    protected static ?string $slug = 'chi-paga/{pagante}';

    /**
     * Non si chiama $pagante come il parametro di rotta: Livewire assegna i
     * parametri alle proprieta' pubbliche omonime, e qui arriverebbe la
     * stringa dell'id su una proprieta' tipizzata Customer.
     */
    public ?Customer $soggetto = null;

    public function mount(string $pagante): void
    {
        abort_unless(PagantiMacchine::canAccess(), 403);

        $this->soggetto = Customer::query()->whereKey($pagante)->firstOrFail();
    }

    public static function canAccess(): bool
    {
        return PagantiMacchine::canAccess();
    }

    public function getTitle(): string
    {
        return DisplayName::titleCase($this->soggetto?->company_name)
            ?: (DisplayName::titleCase($this->soggetto?->full_name) ?? 'Pagante');
    }

    public function getSubheading(): ?string
    {
        $macchine = $this->soggetto?->macchinePagate()->count() ?? 0;
        $clienti = $this->soggetto?->macchinePagate()->distinct('current_customer_id')->count('current_customer_id') ?? 0;

        return sprintf(
            'Paga %d %s presso %d %s.',
            $macchine, $macchine === 1 ? 'macchina' : 'macchine',
            $clienti, $clienti === 1 ? 'cliente' : 'clienti',
        );
    }

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('indietro')
                ->label('Tutti i paganti')
                ->icon('heroicon-o-arrow-left')
                ->color('gray')
                ->url(fn () => PagantiMacchine::getUrl()),
            Actions\Action::make('stampa')
                ->label('Stampa')
                ->icon('heroicon-o-printer')
                ->color('gray')
                ->url(fn () => route('paganti.stampa', [
                    'pagante' => $this->soggetto?->getKey(),
                    'tenant' => Filament::getTenant()?->getKey(),
                ]))
                ->openUrlInNewTab(),
        ];
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(fn (): Builder => MachineUnit::query()
                ->where('billing_customer_id', $this->soggetto?->getKey())
                ->with('currentCustomer'))
            ->defaultSort('current_customer_id')
            ->emptyStateHeading('Nessuna macchina')
            ->columns([
                Tables\Columns\TextColumn::make('currentCustomer.company_name')
                    ->label('Cliente')
                    ->formatStateUsing(fn (?string $state) => DisplayName::titleCase($state))
                    ->placeholder('— nessun cliente')
                    ->searchable()
                    ->sortable(),

                Tables\Columns\TextColumn::make('serial_number')
                    ->label('Matricola')
                    ->searchable()
                    ->copyable(),

                Tables\Columns\TextColumn::make('model_name')
                    ->label('Macchina')
                    ->placeholder('—')
                    ->searchable(),

                Tables\Columns\TextColumn::make('status')
                    ->label('Stato')
                    ->badge()
                    ->placeholder('—'),
            ])
            ->paginated([25, 50, 100]);
    }
}
