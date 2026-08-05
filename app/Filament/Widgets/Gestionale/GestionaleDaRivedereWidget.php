<?php

namespace App\Filament\Widgets\Gestionale;

use App\Models\Customer;
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget as BaseWidget;

class GestionaleDaRivedereWidget extends BaseWidget
{
    protected static ?string $heading = 'Da rivedere';

    protected int|string|array $columnSpan = 1;

    // Filament carica i widget "lazy" (via Intersection Observer) di default:
    // su questa pagina, una sola colonna con 5 tabelle impilate, quelle sotto
    // la piega restano un riquadro vuoto — nemmeno l'intestazione — finche'
    // non arrivano in vista. Per una pagina di controllo va tutto visibile
    // subito, altrimenti sembra rotta.
    protected static bool $isLazy = false;

    public function table(Table $table): Table
    {
        return $table
            // Senza questo, tutte le tabelle della pagina "Sync Eureka"
            // condividono lo stesso parametro ?page= nell'URL: sfogliare una
            // tabella lunga (es. le macchine nuove) spingeva anche le altre
            // alla stessa pagina, che per loro poteva non esistere
            // ("Nessun risultato" su dati che c'erano). Nota:
            // getTableQueryStringIdentifier() (sul widget) esiste nel
            // pacchetto Filament ma non e' mai invocato da nessuna parte in
            // questa versione — l'unico modo che funziona davvero e'
            // ->queryStringIdentifier() qui sulla Table stessa.
            ->queryStringIdentifier('daRivedere')
            ->query(
                Customer::query()
                    ->whereNotNull('gestionale_review_flagged_at')
                    // Le segnalazioni generate solo da campi autocompilati (nessuna
                    // vera differenza da decidere, vedi GestionaleSyncRunner) sono
                    // gia' state applicate da sole: mostrarle qui è solo rumore,
                    // l'utente non deve fare nulla. Restano visibili le altre note
                    // (es. da notifyGestionaleReviewIfLinked(), che non iniziano con
                    // questo prefisso) e quelle con anche una vera differenza.
                    ->where(function ($query) {
                        $query->where('gestionale_review_note', 'not like', 'Compilati automaticamente:%')
                            ->orWhere('gestionale_review_note', 'like', '%Da rivedere:%');
                    })
                    ->orderByDesc('gestionale_review_flagged_at')
            )
            ->columns([
                Tables\Columns\TextColumn::make('full_name')->label('Cliente'),
                Tables\Columns\TextColumn::make('gestionale_review_note')->label('Nota')->wrap(),
                Tables\Columns\TextColumn::make('gestionale_review_flagged_at')->label('Dal')->date(),
            ])
            ->actions([
                Tables\Actions\Action::make('segna_aggiornato_gestionale')
                    ->label('Segna come controllato')
                    ->icon('heroicon-o-check-circle')
                    ->color('success')
                    ->requiresConfirmation()
                    ->modalDescription('Non scrive nulla su Eureka: toglie solo la segnalazione da questo cliente, per dire "ho controllato".')
                    ->action(fn (Customer $record) => $record->dismissGestionaleReview()),
            ])
            ->paginated([10, 25, 50]);
    }
}
