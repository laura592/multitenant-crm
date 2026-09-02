<?php

namespace App\Filament\Pages;

use App\Filament\Resources\CustomerResource;
use App\Models\Customer;
use App\Models\EurekaPartitaAperta;
use App\Support\DisplayName;
use BezhanSalleh\FilamentShield\Traits\HasPageShield;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Pages\Page;
use Filament\Tables;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Le fatture scadute di un singolo cliente: la schermata che si tiene aperta
 * mentre si telefona.
 *
 * Pagina propria e non la scheda cliente del CRM: chi sollecita ha bisogno
 * dell'elenco delle fatture con importi e ritardi, non di anagrafica,
 * macchinari e preventivi. Il collegamento alla scheda resta in cima.
 *
 * Indirizzata per gestionale_code e non per id di partita: l'oggetto della
 * pagina è il cliente, mentre le sue partite cambiano a ogni import.
 */
class DettaglioScaduto extends Page implements HasTable
{
    // Senza HasPageShield una Page Filament e' accessibile a chiunque
    // sia autenticato: il permesso page_* non viene applicato da solo.
    // Qui sono dati contabili, quindi l'omissione sarebbe una fuga.
    use HasPageShield, InteractsWithTable;

    protected static bool $shouldRegisterNavigation = false;

    protected static string $view = 'filament.pages.dettaglio-scaduto';

    protected static ?string $slug = 'scaduto/{codice}';

    /**
     * Codice anagrafica Eureka, valorizzato da mount() con il parametro
     * dell'URL. Ha un default perche' la pagina non e' sempre montata:
     * Shield, per costruire la matrice dei permessi della pagina Ruoli,
     * istanzia ogni Page e ne chiama getTitle() SENZA passare da mount()
     * (vedi FilamentShield::getLocalizedPageLabel()). Senza default la
     * proprieta' tipizzata resta non inizializzata e l'accesso solleva un
     * Error che manda in 500 l'INTERA pagina Ruoli, non solo questa.
     * Successo in produzione il 2026-09-02.
     */
    public int $codice = 0;

    public ?Customer $cliente = null;

    public function mount(int $codice): void
    {
        $this->codice = $codice;
        $this->cliente = Customer::query()
            ->where('tenant_id', Filament::getTenant()?->id)
            ->where('gestionale_code', $codice)
            ->first();
    }

    public function getTitle(): string
    {
        // Stesso filtro della tabella sotto, tenant e tipo compresi: senza,
        // il titolo poteva prendere la ragione sociale di un FORNITORE con
        // lo stesso codice, o di un altro tenant quando a guardare e' un
        // super admin (per cui lo scope globale non si applica).
        // Fuori da una richiesta reale non c'e' nessuna anagrafica da
        // nominare: si torna l'etichetta generica, che e' anche quella che
        // Shield mostra nell'elenco dei permessi.
        if ($this->codice === 0) {
            return 'Dettaglio scaduto';
        }

        $nome = $this->cliente?->company_name
            ?: EurekaPartitaAperta::query()
                ->where('tenant_id', Filament::getTenant()?->id)
                ->where('gestionale_code', $this->codice)
                ->where('tipo', EurekaPartitaAperta::TIPO_CLIENTE)
                ->value('ragione_sociale');

        return DisplayName::titleCase($nome) ?: "Anagrafica {$this->codice}";
    }

    /**
     * I tre numeri in chiaro, perché due schermate che mostrano importi
     * diversi per lo stesso cliente sembrano sbagliate anche quando non lo
     * sono: il riepilogo espone lo SCADUTO (solo partite positive già
     * scadute), qui si vede tutto, note di credito comprese. Senza dirlo,
     * la differenza sembra un errore.
     */
    public function getSubheading(): ?string
    {
        $partite = $this->partite();

        $scaduto = $partite
            ->filter(fn (EurekaPartitaAperta $p) => (float) $p->saldo > 0
                && $p->data_scadenza !== null
                && $p->data_scadenza->isPast())
            ->sum('saldo');
        $crediti = $partite->filter(fn (EurekaPartitaAperta $p) => (float) $p->saldo < 0)->sum('saldo');
        $saldo = $partite->sum('saldo');

        $euro = fn ($v) => '€ '.number_format((float) $v, 2, ',', '.');

        $parti = ['Scaduto '.$euro($scaduto)];

        if ((float) $crediti !== 0.0) {
            $parti[] = 'note di credito '.$euro($crediti);
            $parti[] = 'saldo '.$euro($saldo);
        }

        return implode(' · ', $parti).' — codice Eureka '.$this->codice;
    }

    /** @return Collection<int, EurekaPartitaAperta> */
    private function partite(): Collection
    {
        return EurekaPartitaAperta::query()
            ->where('tenant_id', Filament::getTenant()?->id)
            ->where('gestionale_code', $this->codice)
            ->where('tipo', EurekaPartitaAperta::TIPO_CLIENTE)
            ->get();
    }

    protected function getHeaderActions(): array
    {
        return array_values(array_filter([
            Action::make('indietro')
                ->label('Torna allo scaduto')
                ->icon('heroicon-m-arrow-left')
                ->color('gray')
                ->url(ScadutoClienti::getUrl()),
            $this->cliente
                ? Action::make('scheda_cliente')
                    ->label('Scheda cliente')
                    ->icon('heroicon-m-user')
                    ->color('gray')
                    ->url(CustomerResource::getUrl('view', ['record' => $this->cliente]))
                : null,
        ]));
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(fn (): Builder => EurekaPartitaAperta::query()
                ->where('tenant_id', Filament::getTenant()?->id)
                ->where('gestionale_code', $this->codice)
                ->where('tipo', EurekaPartitaAperta::TIPO_CLIENTE)
                // Qui NON si filtra nulla: davanti al cliente serve il suo
                // partitario intero — note di credito comprese, altrimenti
                // gli si chiede una cifra che lui sa di aver compensato, e
                // scritture di apertura comprese, perche' concorrono al
                // saldo che lui vede sul suo estratto conto.
            )
            ->columns([
                Tables\Columns\TextColumn::make('numero_fattura')
                    ->label('Fattura')
                    ->placeholder('senza numero')
                    ->weight('medium'),

                Tables\Columns\TextColumn::make('data_fattura')
                    ->label('Emessa')
                    ->date('d/m/Y')
                    ->placeholder('—'),

                Tables\Columns\TextColumn::make('data_scadenza')
                    ->label('Scadenza')
                    ->date('d/m/Y')
                    ->sortable()
                    ->placeholder('—'),

                Tables\Columns\TextColumn::make('ritardo')
                    ->label('Ritardo')
                    // Il testo si compone QUI e non in formatStateUsing.
                    // Filament salta il formatter quando lo stato e' null e
                    // mostra il placeholder al suo posto: i due casi che
                    // proprio in questa colonna andavano spiegati — la nota
                    // di credito e la partita senza scadenza — sono
                    // esattamente quelli in cui giorniDiRitardo() torna
                    // null, quindi le loro celle restavano vuote e le due
                    // diciture non comparivano mai.
                    ->getStateUsing(function (EurekaPartitaAperta $record): string {
                        if ((float) $record->saldo < 0) {
                            return 'nota di credito';
                        }

                        $giorni = $record->giorniDiRitardo();

                        return $giorni === null ? 'senza scadenza' : "{$giorni} giorni";
                    })
                    ->badge()
                    ->color(function (EurekaPartitaAperta $record): string {
                        if ((float) $record->saldo < 0) {
                            return 'success';
                        }

                        return match (true) {
                            // Senza data di scadenza non si sa se e' in
                            // ritardo: grigio, non verde — non e' una buona
                            // notizia, e' un dato che manca.
                            $record->giorniDiRitardo() === null => 'gray',
                            $record->giorniDiRitardo() > 180 => 'danger',
                            $record->giorniDiRitardo() > 60 => 'warning',
                            default => 'info',
                        };
                    }),

                Tables\Columns\TextColumn::make('tipo_pagamento')
                    ->label('Come si paga')
                    ->badge()
                    ->color('gray')
                    ->placeholder('non indicato'),

                Tables\Columns\TextColumn::make('saldo')
                    ->label('Importo')
                    ->money('EUR')
                    ->alignEnd()
                    ->color(fn (EurekaPartitaAperta $record) => (float) $record->saldo < 0 ? 'success' : null)
                    ->summarize(Tables\Columns\Summarizers\Sum::make()->label('Totale')->money('EUR')),
            ])
            ->defaultSort('data_scadenza')
            ->paginated(false);
    }
}
