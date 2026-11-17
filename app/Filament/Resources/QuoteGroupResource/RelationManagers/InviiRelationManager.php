<?php

namespace App\Filament\Resources\QuoteGroupResource\RelationManagers;

use App\Mail\QuoteGroupMail;
use App\Models\QuoteGroup;
use App\Models\QuoteGroupEmail;
use App\Support\OutsideLivewireRender;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\HtmlString;

/**
 * Lo storico degli invii di un'offerta: si legge soltanto (17/11/2026).
 *
 * Il preventivo lo mostrava gia' — e' la sezione "Storico invii email"
 * nell'infolist di QuoteResource — l'offerta no, pur registrando ogni invio
 * in `quote_group_emails` da sempre: il dato c'era e non si vedeva da
 * nessuna parte (Laura, 17/11/2026).
 *
 * Qui e' un relation manager e non una sezione di infolist perche'
 * l'offerta non ha una pagina "Vedi": la sua scheda e' EditQuoteGroup, che
 * mostra i relation manager come schede accanto a "Dati offerta". E' anche
 * la forma che gli invii hanno gia' nell'offerta caffe', quindi lo stesso
 * storico si legge allo stesso modo in due posti invece che in due modi.
 */
class InviiRelationManager extends RelationManager
{
    protected static string $relationship = 'emails';

    protected static ?string $title = 'Invii';

    protected static ?string $icon = 'heroicon-o-paper-airplane';

    public function isReadOnly(): bool
    {
        return true;
    }

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return true;
    }

    public function table(Table $table): Table
    {
        return $table
            // QuoteGroup::emails() non ha ->latest() come Quote::emails():
            // l'ordine lo mette la tabella, e l'ultimo invio sta in cima
            // perche' e' quello che si va a cercare.
            ->defaultSort('created_at', 'desc')
            ->emptyStateHeading('Offerta non ancora inviata')
            ->emptyStateDescription('Qui compaiono destinatario, data ed esito di ogni invio, riuscito o fallito.')
            ->columns([
                Tables\Columns\TextColumn::make('created_at')->label('Inviata il')->dateTime('d/m/Y H:i'),
                Tables\Columns\TextColumn::make('recipient_email')->label('A'),
                Tables\Columns\TextColumn::make('cc_email')->label('CC')->placeholder('—'),
                Tables\Columns\TextColumn::make('user.name')->label('Da')->placeholder('—'),
                Tables\Columns\TextColumn::make('status')
                    ->label('Esito')
                    ->badge()
                    ->formatStateUsing(fn (string $state) => $state === 'sent' ? 'Inviata' : 'Fallita')
                    ->color(fn (string $state) => $state === 'sent' ? 'success' : 'danger')
                    ->tooltip(fn (QuoteGroupEmail $record) => $record->error_message),
                Tables\Columns\TextColumn::make('error_message')
                    ->label('Errore')
                    ->placeholder('—')
                    ->wrap()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->actions([
                Tables\Actions\Action::make('preview')
                    ->label('Anteprima')
                    ->icon('heroicon-o-eye')
                    ->color('gray')
                    ->modalHeading(fn (QuoteGroupEmail $record) => "Anteprima email — {$record->recipient_email}")
                    ->modalContent(fn (QuoteGroupEmail $record) => new HtmlString(
                        '<iframe srcdoc="'.e(static::corpoInviato($record)).'" style="width:100%;height:70vh;border:0;border-radius:0.5rem;background:#fff;"></iframe>'
                    ))
                    ->modalSubmitAction(false)
                    ->modalCancelActionLabel('Chiudi')
                    ->modalWidth('4xl'),
            ]);
    }

    /**
     * L'email com'era quando e' partita: testo e oggetto sono quelli
     * salvati sulla riga, non quelli che si genererebbero oggi.
     *
     * Vedi App\Support\OutsideLivewireRender: senza, il rendering parte da
     * dentro una richiesta Livewire e i commenti <!--[if BLOCK]><![endif]-->
     * finiscono visibili nel corpo (bug del 10/11/2026 sull'anteprima del
     * preventivo all'Hotel Alemagna).
     */
    protected static function corpoInviato(QuoteGroupEmail $record): string
    {
        $gruppo = $record->quoteGroup;

        // La relazione e' dichiarata su Model: senza il controllo l'analisi
        // statica non sa che e' un'offerta. Un invio senza offerta non
        // esiste (FK), ma un'anteprima vuota e' meglio di un errore.
        if (! $gruppo instanceof QuoteGroup) {
            return '';
        }

        // Stesso eager load di QuoteGroupResource::sendGroupEmail(): il
        // corpo elenca le proposte con le loro righe.
        $quotes = $gruppo->quotes()->with(['quoteProducts.product', 'quoteProducts.options.product'])->get();

        return OutsideLivewireRender::run(fn () => (new QuoteGroupMail(
            $gruppo,
            $quotes,
            // Nessun PDF: l'anteprima e' il corpo, gli allegati non entrano
            // nel render().
            [],
            $record->message,
            $record->subject,
            clientUrl: $gruppo->public_token ? $gruppo->clientUrl() : null,
        ))->render());
    }
}
