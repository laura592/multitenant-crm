<?php

namespace App\Filament\Resources\QuoteGroupResource\RelationManagers;

use App\Filament\Resources\QuoteGroupResource;
use App\Models\QuoteGroupEmail;
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
 * Resta un relation manager perche' e' la scheda accanto a "Dati offerta"
 * di EditQuoteGroup, ed e' la forma che gli invii hanno gia' nell'offerta
 * caffe': lo stesso storico si legge allo stesso modo in due posti invece
 * che in due modi.
 *
 * Dal 27/11/2026 l'offerta ha anche una pagina "Vedi"
 * (QuoteGroupResource::infolist), e li' gli invii sono una sezione
 * dell'infolist: ViewQuoteGroup non carica i relation manager di proposito,
 * altrimenti la stessa tabella comparirebbe due volte sulla stessa scheda.
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
                // Qui dentro non c'e' solo l'invio dell'offerta: dal
                // 27/11/2026 c'e' anche il sollecito, che e' un'altra mail
                // con un altro testo e altri pulsanti. Senza questa colonna
                // le due si leggono uguali.
                Tables\Columns\TextColumn::make('tipo')
                    ->label('Che cosa')
                    ->badge()
                    ->formatStateUsing(fn (?string $state) => $state === QuoteGroupEmail::TIPO_SOLLECITO ? 'Sollecito' : 'Offerta')
                    ->color(fn (?string $state) => $state === QuoteGroupEmail::TIPO_SOLLECITO ? 'warning' : 'gray'),
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
                        '<iframe srcdoc="'.e(QuoteGroupResource::corpoInviato($record)).'" style="width:100%;height:70vh;border:0;border-radius:0.5rem;background:#fff;"></iframe>'
                    ))
                    ->modalSubmitAction(false)
                    ->modalCancelActionLabel('Chiudi')
                    ->modalWidth('4xl'),
            ]);
    }
}
