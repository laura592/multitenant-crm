<?php

namespace App\Filament\Widgets;

use App\Filament\Resources\QuoteResource;
use App\Models\Quote;
use App\Support\DisplayName;
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget as BaseWidget;
use Illuminate\Support\Facades\Auth;

class LatestQuotesWidget extends BaseWidget
{
    // Chiude la sezione "Andamento commerciale", sotto i suoi numeri.
    protected static ?int $sort = 6;

    // Chi non ha accesso ai preventivi (dipendente/amministrazione) non deve
    // vederli nemmeno riassunti qui.
    public static function canView(): bool
    {
        return Auth::user()->can('view_any_quote');
    }

    // Riga intera: non e' piu' affiancata a Prossime scadenze (finita nella
    // sezione "Da fare adesso"), da sola a meta' riga lasciava mezzo schermo
    // vuoto.
    protected int|string|array $columnSpan = 'full';

    protected static ?string $heading = 'Ultimi preventivi';

    public function table(Table $table): Table
    {
        return $table
            ->query(Quote::query()->with('customer')->latest('created_at')->limit(5))
            ->columns([
                Tables\Columns\TextColumn::make('number')->label('Numero'),
                Tables\Columns\TextColumn::make('customer.company_name')->label('Cliente')
                    ->formatStateUsing(fn (?string $state) => DisplayName::titleCase($state)),
                Tables\Columns\TextColumn::make('total')->label('Totale')->money('EUR'),
                Tables\Columns\TextColumn::make('status')
                    ->label('Stato')
                    ->badge()
                    ->formatStateUsing(fn (string $state) => QuoteResource::statusLabels()[$state] ?? ucfirst($state))
                    ->color(fn (string $state) => QuoteResource::statusColors()[$state] ?? 'gray'),
            ])
            ->recordUrl(fn (Quote $record) => QuoteResource::getUrl('view', ['record' => $record]))
            ->emptyStateHeading('Nessun preventivo')
            ->emptyStateDescription('I preventivi creati piu di recente compariranno qui.')
            ->emptyStateIcon('heroicon-o-document-text')
            ->paginated(false);
    }
}
