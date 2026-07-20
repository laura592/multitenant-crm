<?php

namespace App\Filament\Widgets;

use App\Models\Quote;
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget as BaseWidget;

class LatestQuotesWidget extends BaseWidget
{
    // Stesso sort di UpcomingDeadlinesWidget cosi le due tabelle (colSpan 1)
    // finiscono sulla stessa riga della griglia a 2 colonne, affiancate.
    // Prima era 1 come QuotesChartWidget (colSpan 'full'): a parita di sort
    // vince l'ordine alfabetico di discovery (LatestQuotesWidget prima di
    // QuotesChartWidget), quindi questa tabella finiva da sola su una riga
    // con meta griglia vuota, e "Prossime scadenze" restava isolata piu sotto.
    protected static ?int $sort = 2;

    protected int|string|array $columnSpan = 1;

    protected static ?string $heading = 'Ultimi preventivi';

    public function table(Table $table): Table
    {
        return $table
            ->query(Quote::query()->with('customer')->latest('created_at')->limit(5))
            ->columns([
                Tables\Columns\TextColumn::make('number')->label('Numero'),
                Tables\Columns\TextColumn::make('customer.company_name')->label('Cliente'),
                Tables\Columns\TextColumn::make('total')->label('Totale')->money('EUR'),
                Tables\Columns\TextColumn::make('status')
                    ->label('Stato')
                    ->badge()
                    ->formatStateUsing(fn (string $state) => ucfirst($state))
                    ->color(fn (string $state) => match ($state) {
                        'accettato' => 'success',
                        'rifiutato' => 'danger',
                        'inviato' => 'warning',
                        default => 'gray',
                    }),
            ])
            ->paginated(false);
    }
}
