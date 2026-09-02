<?php

namespace App\Filament\Widgets\Contabilita;

use App\Models\EurekaPartitaAperta;
use Filament\Facades\Filament;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Carbon;

/**
 * Il riquadro in testa alle partite aperte, per chi deve incassare.
 *
 * Solo CLIENTI: è la schermata di chi sollecita, e mescolarci il debito
 * verso i fornitori renderebbe i totali illeggibili.
 *
 * Debiti e crediti NON si compensano. Sommando anche le partite negative
 * (note di credito e simili) si ottiene un totale piu' basso dello scaduto
 * stesso — sui dati reali l'esposizione netta risultava 93.824 contro
 * 115.697 di scaduto, un confronto senza senso. Chi telefona al cliente
 * deve sapere quanto c'e' da incassare; i crediti sono una voce a parte,
 * perche' richiedono un'azione diversa (compensarli o stornarli).
 *
 * Escluse le scritture di apertura come nella tabella sotto: non hanno
 * numero di fattura e non corrispondono a un credito verso un documento
 * preciso.
 */
class ScadutoOverviewWidget extends StatsOverviewWidget
{
    protected static ?string $pollingInterval = null;

    /**
     * NON lazy, di proposito.
     *
     * Un StatsOverviewWidget di default si carica con una seconda richiesta
     * Livewire. Ma Filament registra come componenti Livewire solo i widget
     * dichiarati in Panel::widgets() o Resource::getWidgets(), mai quelli
     * usati soltanto dentro una Page: quella seconda richiesta non trovava
     * il componente e tornava 419, che resources/js/app.js interpreta come
     * sessione scaduta e reindirizza. Era esattamente il rimbalzo su
     * /sessione-scaduta visto dall'utente il 2026-09-01.
     *
     * Reso sincrono il widget viene renderizzato con la pagina: nessun
     * secondo giro, nessuna registrazione necessaria. In alternativa si
     * potrebbe elencarlo in AdminPanelProvider::widgets(), ma finirebbe
     * anche in Dashboard, che non e' il suo posto.
     */
    protected static bool $isLazy = false;

    /** Percentuale che non arrotonda mai a 0% o 100% ciò che non lo e'. */
    private function quotaScaduta(float $scaduto, float $totale): string
    {
        if ($totale <= 0) {
            return '—';
        }

        if ($scaduto <= 0) {
            return 'niente ancora scaduto';
        }

        return ($scaduto >= $totale ? 100 : max(1, min(99, (int) round($scaduto / $totale * 100)))).'% del totale';
    }

    protected function getStats(): array
    {
        $partite = EurekaPartitaAperta::query()
            ->where('tenant_id', Filament::getTenant()?->id)
            ->where('tipo', EurekaPartitaAperta::TIPO_CLIENTE)
            // Stesso criterio della tabella sotto: fuori le scritture di
            // apertura, riconosciute dal numero di fattura mancante.
            ->whereNotNull('numero_fattura')
            ->where('numero_fattura', '<>', '')
            ->get(['saldo', 'data_scadenza', 'anno']);

        $oggi = Carbon::today();
        $daIncassare = $partite->filter(fn (EurekaPartitaAperta $p) => (float) $p->saldo > 0);
        $crediti = $partite->filter(fn (EurekaPartitaAperta $p) => (float) $p->saldo < 0);

        $scadutoOltre = fn (int $giorni) => $daIncassare
            ->filter(fn (EurekaPartitaAperta $p) => $p->data_scadenza !== null
                && $p->data_scadenza->lt($oggi->copy()->subDays($giorni)))
            ->sum('saldo');

        $euro = fn (float $v) => '€ '.number_format(abs($v), 2, ',', '.');

        $primoAnno = (int) $daIncassare->min('anno') ?: null;
        $totale = (float) $daIncassare->sum('saldo');
        $scaduto = (float) $scadutoOltre(0);
        $vecchio = (float) $scadutoOltre(90);

        return [
            Stat::make('Da incassare', $euro($totale))
                // L'anno piu' vecchio si legge dai dati e non si scrive a
                // mano: era fisso a "dal 2024" mentre in elenco ci sono
                // fatture del 2023 (la 513 di Pasti Fabio, per dirne una),
                // e un riquadro che si smentisce da solo toglie fiducia a
                // tutti gli altri numeri della pagina.
                ->description($daIncassare->count().' fatture aperte'.($primoAnno ? " dal {$primoAnno}" : ''))
                ->color('gray'),

            Stat::make('Di cui scaduto', $euro($scaduto))
                // "100%" solo se lo e' davvero. Con 119.654 su 120.065 un
                // round() arrivava a 100 e il riquadro dichiarava che TUTTO
                // e' scaduto mentre 411 euro non lo erano: chi legge si fida
                // della percentuale, non ricalcola la divisione.
                ->description($this->quotaScaduta($scaduto, $totale))
                ->color($scaduto > 0 ? 'warning' : 'success'),

            Stat::make('Oltre 90 giorni', $euro($vecchio))
                ->description('il ritardo su cui intervenire per primo')
                ->color('danger'),

            Stat::make('Crediti al cliente', $euro((float) $crediti->sum('saldo')))
                ->description($crediti->count().' note di credito da compensare')
                ->color('info'),
        ];
    }
}
