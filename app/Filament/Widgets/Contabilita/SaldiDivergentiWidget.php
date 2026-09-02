<?php

namespace App\Filament\Widgets\Contabilita;

use App\Filament\Pages\DettaglioScaduto;
use App\Models\EurekaPartitaAperta;
use App\Models\EurekaSaldoAnagrafica;
use App\Support\DisplayName;
use Filament\Facades\Filament;
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;
use Illuminate\Database\Eloquent\Builder;

/**
 * I clienti per cui il saldo dichiarato da Eureka e la somma delle nostre
 * partite non coincidono.
 *
 * È il controllo che finora mancava. Le partite aperte hanno problemi di
 * affidabilità noti — divergono dall'estratto conto del gestionale, non
 * vedono il portafoglio RiBa — ma la divergenza si scopriva solo aprendo
 * Eureka a mano, cliente per cliente. Qui è scritta, e costa zero chiamate:
 * l'elenco dei saldi lo scarichiamo già per sapere a chi chiedere il
 * dettaglio, e finora ne buttavamo via l'importo.
 *
 * Non è un elenco di errori nostri: sui dati reali due delle sette righe
 * sono anagrafiche il cui dettaglio Eureka restituisce vuoto pur
 * dichiarando un saldo. È l'elenco dei clienti su cui NON fidarsi del
 * numero prima di aver telefonato.
 */
class SaldiDivergentiWidget extends TableWidget
{
    protected static ?string $heading = 'Clienti dove Eureka e le partite non tornano';

    protected static ?string $description = 'Prima di chiamarli, il numero va verificato sul gestionale.';

    protected int|string|array $columnSpan = 'full';

    // Vedi AppServiceProvider: registrato con Livewire perche' vive dentro
    // una Page e la tabella e' interattiva.
    protected static bool $isLazy = false;

    public function table(Table $table): Table
    {
        return $table
            ->query(fn (): Builder => $this->query())
            ->columns([
                Tables\Columns\TextColumn::make('ragione_sociale')
                    ->label('Cliente')
                    ->searchable()
                    ->formatStateUsing(fn (?string $state) => DisplayName::titleCase($state))
                    ->weight('medium'),

                Tables\Columns\TextColumn::make('saldo')
                    ->label('Secondo Eureka')
                    ->money('EUR')
                    ->alignEnd(),

                Tables\Columns\TextColumn::make('saldo_partite')
                    ->label('Somma delle partite')
                    ->money('EUR')
                    ->alignEnd(),

                Tables\Columns\TextColumn::make('scarto')
                    ->label('Scarto')
                    ->money('EUR')
                    ->alignEnd()
                    ->weight('bold')
                    ->color(fn ($record) => abs((float) $record->scarto) >= 100 ? 'danger' : 'warning'),
            ])
            ->actions([
                Tables\Actions\Action::make('dettaglio')
                    ->label('Vedi partite')
                    ->icon('heroicon-m-arrow-right')
                    ->url(fn ($record) => DettaglioScaduto::getUrl(['codice' => $record->gestionale_code])),
            ])
            ->defaultPaginationPageOption(10)
            ->emptyStateHeading('Tutto torna')
            ->emptyStateDescription('Per ogni cliente il saldo di Eureka coincide con la somma delle sue partite.');
    }

    /**
     * Lo scarto si calcola nel DATABASE, non in PHP: serve a ordinare e
     * paginare, e farlo dopo il fetch ordinerebbe solo la pagina corrente.
     *
     * La somma delle partite arriva da una sottoquery correlata invece che
     * da una join: le anagrafiche senza nessuna partita (Eureka dichiara un
     * saldo ma il dettaglio torna vuoto) devono comparire, e sono proprio i
     * casi piu' interessanti — con una join interna sparirebbero.
     */
    private function query(): Builder
    {
        $tenantId = Filament::getTenant()?->id;

        $sommaPartite = '(SELECT COALESCE(SUM(p.saldo), 0) FROM eureka_partite_aperte p'
            .' WHERE p.tenant_id = eureka_saldi_anagrafiche.tenant_id'
            .' AND p.tipo = eureka_saldi_anagrafiche.tipo'
            .' AND p.gestionale_code = eureka_saldi_anagrafiche.gestionale_code)';

        return EurekaSaldoAnagrafica::query()
            ->where('tenant_id', $tenantId)
            ->where('tipo', EurekaPartitaAperta::TIPO_CLIENTE)
            ->select('*')
            ->selectRaw("{$sommaPartite} as saldo_partite")
            ->selectRaw("saldo - {$sommaPartite} as scarto")
            // Sotto il centesimo e' arrotondamento, non una divergenza.
            ->whereRaw("ABS(saldo - {$sommaPartite}) > ?", [EurekaSaldoAnagrafica::TOLLERANZA])
            ->orderByRaw("ABS(saldo - {$sommaPartite}) DESC");
    }
}
