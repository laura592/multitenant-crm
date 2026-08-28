<?php

namespace App\Filament\Resources\InformationRequestResource\RelationManagers;

use App\Filament\Resources\QuoteResource;
use App\Models\InformationRequest;
use App\Models\Quote;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * I preventivi nati da questa richiesta. Sola lettura: un preventivo si
 * modifica dalla sua pagina, qui serve solo vedere a che punto e' — che e'
 * poi il modo in cui la richiesta "sa" di essere stata preventivata, senza
 * uno stato parallelo da tenere aggiornato a mano.
 */
class QuotesRelationManager extends RelationManager
{
    protected static string $relationship = 'quotes';

    protected static ?string $title = 'Preventivi';

    protected static ?string $modelLabel = 'Preventivo';

    /**
     * I preventivi proponibili: stesso cliente della richiesta e non ancora
     * collegati a nessuna richiesta. Metodo a parte perche' e' la regola che
     * decide cosa si vede nella select, ed e' l'unica cosa che vale la pena
     * verificare con un test.
     */
    public static function collegabili(Builder $query, InformationRequest $request): Builder
    {
        return $query
            ->where('customer_id', $request->customer_id)
            ->whereNull('information_request_id')
            ->orderByDesc('date');
    }

    /**
     * Il solo numero non basta a riconoscere il preventivo giusto quando un
     * cliente ne ha piu' d'uno: data, stato e totale sono quello che si guarda.
     */
    public static function etichetta(Quote $quote): string
    {
        return implode(' · ', array_filter([
            $quote->number,
            $quote->date?->format('d/m/Y'),
            QuoteResource::statusLabels()[$quote->status] ?? $quote->status,
            $quote->total ? number_format((float) $quote->total, 2, ',', '.').' €' : null,
        ]));
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('number')
            ->defaultSort('created_at', 'desc')
            ->headerActions([
                Tables\Actions\Action::make('crea_preventivo')
                    ->label('Nuovo preventivo')
                    ->icon('heroicon-o-plus')
                    ->url(fn () => QuoteResource::getUrl('create', [
                        'customer_id' => $this->getOwnerRecord()->customer_id,
                        'information_request_id' => $this->getOwnerRecord()->id,
                    ], tenant: $this->getOwnerRecord()->tenant)),
                // I preventivi fatti prima che esistesse questo collegamento
                // (o partiti da Preventivi invece che da qui) si agganciano da
                // qui. La scelta e' limitata ai preventivi dello STESSO cliente
                // e non ancora collegati: agganciare il preventivo di un altro
                // cliente sarebbe sempre un errore, e ricollegarne uno gia'
                // agganciato lo staccherebbe in silenzio dalla sua richiesta.
                Tables\Actions\AssociateAction::make()
                    ->label('Collega preventivo esistente')
                    ->icon('heroicon-o-link')
                    ->color('gray')
                    ->recordSelectOptionsQuery(fn (Builder $query) => static::collegabili($query, $this->getOwnerRecord()))
                    // Senza preload la select di Filament e' solo cercabile e
                    // parte VUOTA: si vedeva un elenco senza risultati finche'
                    // non si digitava il numero del preventivo — che e'
                    // esattamente quello che non si ricorda a memoria. I
                    // preventivi di un singolo cliente sono pochi, tanto vale
                    // mostrarli tutti.
                    ->preloadRecordSelect()
                    ->recordTitle(fn (Quote $record) => static::etichetta($record))
                    ->modalHeading('Collega un preventivo gia\' esistente')
                    ->modalDescription('Vengono proposti i preventivi di questo cliente non ancora collegati a una richiesta.')
                    ->successNotificationTitle('Preventivo collegato alla richiesta'),
            ])
            ->columns([
                Tables\Columns\TextColumn::make('number')
                    ->label('Numero')
                    ->url(fn (Quote $record) => QuoteResource::getUrl('edit', ['record' => $record->id], tenant: $record->tenant))
                    ->color('primary'),
                Tables\Columns\TextColumn::make('date')->label('Data')->date('d/m/Y')->placeholder('—')->sortable(),
                Tables\Columns\TextColumn::make('status')
                    ->label('Stato')
                    ->badge()
                    ->formatStateUsing(fn (string $state) => QuoteResource::statusLabels()[$state] ?? ucfirst($state))
                    ->color(fn (string $state) => QuoteResource::statusColors()[$state] ?? 'gray'),
                Tables\Columns\TextColumn::make('total')->label('Totale')->money('EUR')->placeholder('—'),
                // L'offerta esiste solo quando i preventivi sono stati
                // raggruppati: senza gruppo la colonna resta vuota, non e' un
                // dato mancante.
                Tables\Columns\TextColumn::make('quoteGroup.number')->label('Offerta')->placeholder('—'),
            ])
            ->actions([
                // Scollega, non cancella: il preventivo resta dov'e', perde
                // solo il filo con la richiesta.
                Tables\Actions\DissociateAction::make()
                    ->label('Scollega')
                    ->modalHeading('Scollegare il preventivo dalla richiesta?')
                    ->successNotificationTitle('Preventivo scollegato'),
            ])
            ->emptyStateHeading('Nessun preventivo da questa richiesta')
            ->emptyStateDescription('Usa "Nuovo preventivo": cliente e prodotti di interesse vengono portati dentro da soli.');
    }
}
