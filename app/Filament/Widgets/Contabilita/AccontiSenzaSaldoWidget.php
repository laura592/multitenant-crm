<?php

namespace App\Filament\Widgets\Contabilita;

use App\Filament\Resources\CustomerResource;
use App\Models\EurekaFattura;
use App\Support\DisplayName;
use Filament\Facades\Filament;
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;
use Illuminate\Database\Eloquent\Builder;

/**
 * Fatture di acconto che nessun documento successivo detrae.
 *
 * Vendete le macchine con acconto del 50% all'ordine e saldo alla consegna,
 * e la fattura di saldo porta sempre una riga "A DETRARRE FATTURA DI ACCONTO
 * NR X". Un acconto che quella riga non la riceve mai significa, quando la
 * merce e' stata consegnata, che il saldo non e' mai stato fatturato.
 *
 * E' una LISTA DA VERIFICARE, non un elenco di errori certi: l'abbinamento
 * si basa sul testo della riga, quindi una dicitura scritta diversamente
 * produce un falso positivo, e un acconto recente puo' semplicemente
 * attendere una consegna non ancora avvenuta. Per questo si ordina dal piu'
 * vecchio: piu' tempo e' passato, meno e' probabile che sia normale.
 */
class AccontiSenzaSaldoWidget extends TableWidget
{
    protected static ?string $heading = 'Acconti senza fattura di saldo';

    protected static ?string $description = 'Casi da verificare, non errori certi: l\'abbinamento si legge dal testo delle righe documento.';

    protected int|string|array $columnSpan = 'full';

    // Vedi ScadutoOverviewWidget: un widget usato solo dentro una Page non
    // viene registrato come componente Livewire, e il caricamento lazy
    // fallirebbe con un 419.
    protected static bool $isLazy = false;

    public function table(Table $table): Table
    {
        return $table
            ->query(fn (): Builder => EurekaFattura::query()
                ->where('tenant_id', Filament::getTenant()?->id)
                ->where('tipo', EurekaFattura::TIPO_CLIENTE)
                ->where('e_acconto', true)
                // Prima i casi netti, poi quelli con una detrazione senza
                // numero: quelli si controllano a mano e non sono un buco di
                // fatturato accertato.
                ->orderBy('detrazione_ambigua')
                ->orderBy('data_doc'))
            ->columns([
                Tables\Columns\TextColumn::make('data_doc')
                    ->label('Emesso il')
                    ->date('d/m/Y')
                    ->sortable(),

                Tables\Columns\TextColumn::make('eta')
                    ->label('Da')
                    // Cast a int per la stessa ragione dei giorni altrove:
                    // le diff di Carbon tornano float.
                    ->getStateUsing(fn (EurekaFattura $r) => $r->data_doc === null
                        ? null
                        : (int) $r->data_doc->diffInMonths(now()))
                    // Il parametro DEVE chiamarsi $state: Filament risolve gli
                    // argomenti delle closure per nome, non per posizione.
                    ->formatStateUsing(fn (?int $state) => $state === null ? '—' : "{$state} mesi")
                    ->badge()
                    ->color(fn (?int $state) => match (true) {
                        $state === null => 'gray',
                        $state > 12 => 'danger',
                        $state > 6 => 'warning',
                        default => 'info',
                    }),

                Tables\Columns\TextColumn::make('numero_doc')
                    ->label('Acconto n.')
                    // Una detrazione senza il numero dell'acconto dice che
                    // qualcosa e' stato detratto ma non cosa: l'acconto non
                    // si puo' dichiarare saldato, e nemmeno mai fatturato.
                    // Detto in chiaro perche' i due casi si guardano in modo
                    // diverso — questo si controlla a mano, gli altri sono
                    // fatturato che manca.
                    ->description(fn (EurekaFattura $r) => $r->detrazione_ambigua
                        ? 'una detrazione c\'è, ma senza numero'
                        : null),

                Tables\Columns\TextColumn::make('ragione_sociale')
                    ->label('Cliente')
                    ->searchable()
                    ->formatStateUsing(fn (?string $state) => DisplayName::titleCase($state))
                    ->url(fn (EurekaFattura $r) => $r->customer_id
                        ? CustomerResource::getUrl('view', ['record' => $r->customer_id])
                        : null),

                Tables\Columns\TextColumn::make('totale_doc')
                    ->label('Acconto incassato')
                    ->money('EUR')
                    ->alignEnd()
                    ->summarize(Tables\Columns\Summarizers\Sum::make()->label('Totale')->money('EUR')),
            ])
            ->defaultPaginationPageOption(25)
            ->emptyStateHeading('Nessun acconto in sospeso')
            ->emptyStateDescription('Ogni fattura di acconto risulta detratta da una fattura di saldo.');
    }
}
