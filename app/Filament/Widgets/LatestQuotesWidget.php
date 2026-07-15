<?php

namespace App\Filament\Widgets;

use App\Models\Quote;
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget as BaseWidget;

class LatestQuotesWidget extends BaseWidget
{
    protected static ?int $sort = 1;

    protected int|string|array $columnSpan = 1;

    protected static ?string $heading = 'Ultimi preventivi';

    public function table(Table $table): Table
    {
        return $table
            ->query(Quote::query()->latest('created_at')->limit(5))
            ->columns([
                Tables\Columns\TextColumn::make('number')->label('Numero'),
                Tables\Columns\TextColumn::make('customer.company_name')->label('Cliente'),
                Tables\Columns\TextColumn::make('total')->label('Totale')->money('EUR'),
                Tables\Columns\TextColumn::make('status')->label('Stato')->badge(),
            ])
            ->paginated(false);
    }
}
