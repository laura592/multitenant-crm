<?php

namespace App\Filament\Widgets;

use App\Filament\Resources\QuoteResource;
use App\Models\Quote;
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget as BaseWidget;

class LatestQuotesWidget extends BaseWidget
{
    // Stesso sort di UpcomingDeadlinesWidget cosi le due tabelle (colSpan 1)
    // finiscono sulla stessa riga della griglia a 2 colonne, affiancate.
    protected static ?int $sort = 4;

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
                    ->formatStateUsing(fn (string $state) => QuoteResource::statusLabels()[$state] ?? ucfirst($state))
                    ->color(fn (string $state) => QuoteResource::statusColors()[$state] ?? 'gray'),
            ])
            ->paginated(false);
    }
}
