<?php

namespace App\Filament\Resources\InformationRequestResource\RelationManagers;

use App\Filament\Resources\QuoteGroupResource;
use App\Filament\Resources\QuoteResource;
use App\Models\InformationRequest;
use App\Models\Quote;
use App\Models\QuoteGroup;
use Filament\Forms;
use Filament\Notifications\Notification;
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
     * Le offerte proponibili: quelle del cliente della richiesta che hanno
     * almeno un preventivo ancora libero. Un'offerta i cui preventivi sono
     * gia' tutti agganciati altrove non ha senso proporla.
     */
    public static function offerteCollegabili(InformationRequest $request): Builder
    {
        return QuoteGroup::query()
            ->where('customer_id', $request->customer_id)
            ->whereHas('quotes', fn (Builder $query) => $query->whereNull('information_request_id'))
            ->withCount(['quotes as collegabili_count' => fn (Builder $query) => $query->whereNull('information_request_id')])
            ->orderByDesc('created_at');
    }

    /**
     * Quanti preventivi di quell'offerta finirebbero collegati: e' l'unico
     * numero che conta al momento di scegliere, perche' quelli gia' agganciati
     * a un'altra richiesta restano dove sono.
     */
    public static function etichettaOfferta(QuoteGroup $group): string
    {
        $collegabili = $group->collegabili_count ?? $group->quotes()->whereNull('information_request_id')->count();

        return implode(' · ', array_filter([
            $group->number,
            $collegabili.' '.($collegabili === 1 ? 'preventivo' : 'preventivi'),
            QuoteGroupResource::statusLabels()[$group->status] ?? $group->status,
            $group->sent_at?->format('d/m/Y'),
        ]));
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
                // Quando i preventivi sono gia' raggruppati in un'offerta si
                // collega quella, non tre preventivi uno per uno: il
                // collegamento resta comunque sui singoli preventivi (e' li'
                // che vive la colonna), l'offerta e' solo il modo di
                // selezionarli tutti insieme.
                Tables\Actions\Action::make('collegaOfferta')
                    ->label('Collega offerta esistente')
                    ->icon('heroicon-o-rectangle-stack')
                    ->color('gray')
                    ->visible(fn () => static::offerteCollegabili($this->getOwnerRecord())->exists())
                    ->modalHeading('Collega un\'offerta gia\' esistente')
                    ->modalDescription('Collega in un colpo solo tutti i preventivi dell\'offerta che non sono gia\' legati a un\'altra richiesta.')
                    ->form([
                        Forms\Components\Select::make('quote_group_id')
                            ->label('Offerta')
                            ->options(fn () => static::offerteCollegabili($this->getOwnerRecord())
                                ->get()
                                ->mapWithKeys(fn (QuoteGroup $group) => [$group->id => static::etichettaOfferta($group)]))
                            ->required(),
                    ])
                    ->action(function (array $data) {
                        $collegati = Quote::query()
                            ->where('quote_group_id', $data['quote_group_id'])
                            ->where('customer_id', $this->getOwnerRecord()->customer_id)
                            ->whereNull('information_request_id')
                            ->update(['information_request_id' => $this->getOwnerRecord()->id]);

                        Notification::make()
                            ->success()
                            ->title($collegati === 1
                                ? '1 preventivo collegato alla richiesta'
                                : "{$collegati} preventivi collegati alla richiesta")
                            ->send();
                    }),
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
