<?php

namespace App\Filament\Widgets\Contabilita;

use App\Models\EurekaPartitaAperta;
use App\Models\EurekaSaldoAnagrafica;
use Filament\Facades\Filament;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Il riquadro in testa alle partite aperte, per chi deve incassare.
 *
 * Solo CLIENTI, saldo compreso: è la schermata di chi sollecita, e
 * mescolarci il debito verso i fornitori renderebbe i totali illeggibili.
 *
 * Due letture diverse, e la differenza e' il punto del riquadro.
 *
 * "Saldo clienti" e' il numero del GESTIONALE: tutte le partite aperte,
 * scritture di apertura comprese e note di credito gia' sottratte. Serve a
 * riconciliare — finche' non c'era, chi confrontava la pagina con Eureka
 * trovava 135.167 dove il gestionale diceva 146.787 e non aveva modo di
 * capire da dove nascesse lo scarto (indicazione dell'utente, 2026-09-23).
 *
 * Tutte le altre voci sono la lettura di CHI SOLLECITA, e li' debiti e
 * crediti NON si compensano: sommando anche le partite negative si
 * otteneva un totale piu' basso dello scaduto stesso — sui dati reali
 * l'esposizione netta risultava 93.824 contro 115.697 di scaduto, un
 * confronto senza senso. Chi telefona deve sapere quanto c'e' da
 * incassare; i crediti sono una voce a parte, perche' richiedono un'azione
 * diversa (compensarli o stornarli).
 *
 * Le partite senza numero di fattura — riporti di apertura e incassi non
 * imputati — ci sono invece in tutte le voci, come nella tabella sotto e
 * nel dettaglio cliente: il perche' e' scritto una volta sola, nel
 * commento di App\Filament\Pages\ScadutoClienti.
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

    /**
     * @param  Collection<int, EurekaPartitaAperta>  $crediti
     */
    private function vociACredito(Collection $crediti): string
    {
        $note = $crediti->filter(fn (EurekaPartitaAperta $p) => filled($p->numero_fattura))->count();
        $incassi = $crediti->count() - $note;

        $parti = [];

        if ($note > 0 || $incassi === 0) {
            $parti[] = $note.($note === 1 ? ' nota di credito' : ' note di credito');
        }

        if ($incassi > 0) {
            $parti[] = $incassi.($incassi === 1 ? ' incasso non imputato' : ' incassi non imputati');
        }

        return implode(' e ', $parti).' da chiudere';
    }

    protected function getStats(): array
    {
        $tenantId = Filament::getTenant()?->id;

        $partite = EurekaPartitaAperta::query()
            ->where('tenant_id', $tenantId)
            ->where('tipo', EurekaPartitaAperta::TIPO_CLIENTE)
            ->get(['saldo', 'data_fattura', 'data_scadenza', 'anno', 'numero_fattura']);

        $oggi = Carbon::today();
        $daIncassare = $partite->filter(fn (EurekaPartitaAperta $p) => (float) $p->saldo > 0);
        $crediti = $partite->filter(fn (EurekaPartitaAperta $p) => (float) $p->saldo < 0);

        // La scadenza di un riporto di apertura e' la sua data: il saldo e'
        // stato riportato quel giorno e da quel giorno e' dovuto. Vedi
        // ScadutoClienti::SCADENZA_EFFETTIVA, che fa lo stesso in SQL.
        $scadenza = fn (EurekaPartitaAperta $p) => $p->data_scadenza ?? $p->data_fattura;

        $scadutoOltre = fn (int $giorni) => $daIncassare
            ->filter(fn (EurekaPartitaAperta $p) => $scadenza($p) !== null
                && $scadenza($p)->lt($oggi->copy()->subDays($giorni)))
            ->sum('saldo');

        // Il segno si conserva: un totale negativo dev'essere leggibile come
        // tale, e dov'e' sempre negativo per costruzione (i crediti) e' la
        // chiamata a passare il valore assoluto.
        $euro = fn (float $v) => '€ '.number_format($v, 2, ',', '.');

        $primoAnno = (int) $daIncassare->min('anno') ?: null;
        $totale = (float) $daIncassare->sum('saldo');
        $scaduto = (float) $scadutoOltre(0);
        $vecchio = (float) $scadutoOltre(90);

        // Il saldo che il gestionale mostra per i clienti: TUTTE le partite,
        // riporti di apertura compresi e note di credito gia' sottratte.
        $saldo = (float) $partite->sum('saldo');
        $riporti = (float) $partite->reject(fn (EurekaPartitaAperta $p) => filled($p->numero_fattura))->sum('saldo');

        // Eureka dichiara anche un saldo per anagrafica, che scarichiamo
        // insieme alle partite. Se i due numeri non tornano lo dice il
        // riquadro, invece di lasciare che sia chi legge ad accorgersene
        // confrontando col gestionale: il dettaglio cliente per cliente e'
        // in Analisi contabili (SaldiDivergentiWidget).
        $dichiarati = EurekaSaldoAnagrafica::query()
            ->where('tenant_id', $tenantId)
            ->where('tipo', EurekaPartitaAperta::TIPO_CLIENTE)
            ->get(['saldo']);
        $dichiarato = (float) $dichiarati->sum('saldo');
        $diverge = $dichiarati->isNotEmpty() && abs($dichiarato - $saldo) > EurekaSaldoAnagrafica::TOLLERANZA;

        return [
            Stat::make('Saldo clienti', $euro($saldo))
                ->description($diverge
                    ? 'Eureka ne dichiara '.$euro($dichiarato).': lo scarto è in Analisi contabili'
                    : 'tutte le partite aperte, note di credito sottratte')
                ->color($diverge ? 'warning' : 'primary'),

            Stat::make('Da incassare', $euro($totale))
                // L'anno piu' vecchio si legge dai dati e non si scrive a
                // mano: era fisso a "dal 2024" mentre in elenco ci sono
                // fatture del 2023 (la 513 di Pasti Fabio, per dirne una),
                // e un riquadro che si smentisce da solo toglie fiducia a
                // tutti gli altri numeri della pagina.
                //
                // "Partite" e non "fatture": qui dentro ci sono anche i
                // riporti di apertura, che una fattura da citare non ce
                // l'hanno.
                ->description($daIncassare->count().' partite aperte'.($primoAnno ? " dal {$primoAnno}" : '')
                    .($riporti > 0.0 ? ', riporti compresi' : ''))
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

            Stat::make('Crediti al cliente', $euro(abs((float) $crediti->sum('saldo'))))
                // Da quando entrano anche le partite senza numero, qui
                // dentro ci sono due cose che si chiudono in modo diverso:
                // una nota di credito si compensa, un incasso non imputato
                // si abbina alla sua fattura in Eureka. Dirle entrambe
                // "note di credito" manderebbe a cercare documenti che non
                // esistono.
                ->description($this->vociACredito($crediti))
                ->color('info'),
        ];
    }
}
