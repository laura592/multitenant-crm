<?php

namespace App\Filament\Resources\NoleggioResource\Pages;

use App\Filament\Resources\NoleggioResource;
use App\Models\NoleggioFornitura;
use App\Support\DisplayName;
use Filament\Actions;
use Filament\Infolists\Components\Section;
use Filament\Infolists\Components\TextEntry;
use Filament\Infolists\Infolist;
use Filament\Resources\Pages\ViewRecord;
use Illuminate\Support\HtmlString;

/**
 * La scheda di lettura di un noleggio.
 *
 * Qui sta il dettaglio che nel contratto non c'e': come e' composto il canone,
 * da dove viene ogni quantita', e quanto si resta scoperti se il cliente
 * disdice. Sono numeri nostri, non del cliente.
 */
class ViewNoleggio extends ViewRecord
{
    protected static string $resource = NoleggioResource::class;

    protected function getHeaderActions(): array
    {
        return [
            NoleggioResource::azioneContratto(Actions\Action::make('contratto')),
            NoleggioResource::azioneInvio(Actions\Action::make('invia')),
            Actions\EditAction::make(),
        ];
    }

    public function infolist(Infolist $infolist): Infolist
    {
        $eur = fn ($v) => '€ '.number_format((float) $v, 2, ',', '.');

        return $infolist->columns(2)->schema([
            // La stessa striscia dei rapportini e dei preventivi: quello che
            // serve sapere senza scorrere. Cio' che sta qui non si ripete
            // sotto.
            Section::make('Panoramica rapida')
                ->columns(12)
                ->columnSpanFull()
                ->extraAttributes([
                    'class' => 'fi-quick-overview rounded-2xl border border-slate-200 bg-gradient-to-br from-slate-50 via-white to-sky-50 shadow-sm',
                ])
                ->schema([
                    TextEntry::make('number')->label('Numero')->weight('bold')
                        ->placeholder('—')
                        ->columnSpan(['default' => 1, 'lg' => 2]),
                    TextEntry::make('canone')->label('Canone mensile')->money('EUR')
                        ->size(TextEntry\TextEntrySize::Large)->weight('bold')
                        ->columnSpan(['default' => 1, 'lg' => 3]),
                    TextEntry::make('customer.company_name')->label('Cliente')
                        ->formatStateUsing(fn (?string $state) => DisplayName::titleCase($state))
                        ->columnSpan(['default' => 1, 'lg' => 3]),
                    TextEntry::make('mesi')->label('Durata')->suffix(' mesi')
                        ->columnSpan(['default' => 1, 'lg' => 2]),
                    TextEntry::make('data_inizio')->label('Decorrenza')->date('d/m/Y')
                        ->placeholder('da fissare')
                        ->columnSpan(['default' => 1, 'lg' => 2]),
                    TextEntry::make('stato')->label('Stato')->badge()
                        // Il parametro si deve chiamare $state: Filament
                        // risolve le closure per NOME, non per posizione, e
                        // con un nome inventato la pagina va in errore.
                        ->formatStateUsing(fn (string $state) => \App\Models\Noleggio::statiLabels()[$state] ?? $state)
                        ->columnSpan(['default' => 1, 'lg' => 2]),
                    TextEntry::make('attrezzatura')->label('Attrezzatura')
                        ->state(fn ($record) => $record->descrizione
                            ?: ($record->machineUnit?->model_name ?: '—'))
                        ->columnSpan(['default' => 1, 'lg' => 5]),
                    // Le condizioni di pagamento erano solo nel contratto: per
                    // sapere a quanti giorni si incassa bisognava stampare il
                    // PDF.
                    TextEntry::make('pagamento')->label('Fatturazione e pagamento')
                        ->state(fn ($record) => implode(', ', array_filter([
                            \App\Models\Noleggio::periodicitaLabels()[$record->periodicita_fatturazione] ?? null,
                            \App\Models\Noleggio::modalitaPagamentoLabels()[$record->modalita_pagamento] ?? null,
                            \App\Models\Noleggio::terminiPagamentoLabels()[$record->termini_pagamento] ?? null,
                        ])) ?: '—')
                        ->columnSpan(['default' => 1, 'lg' => 5]),
                ]),

            // Un prospetto a due colonne, voce e importo, invece di sei
            // riquadri affiancati: e' il modo in cui questi numeri si
            // leggono davvero, uno sotto l'altro fino al totale.
            Section::make('Com\'è composto il canone')
                ->columnSpan(1)
                ->description('Numeri interni: nel contratto il cliente vede solo il totale.')
                ->schema([
                    TextEntry::make('composizione')
                        ->label('')
                        ->state(function ($record) use ($eur): HtmlString {
                            $base = $record->ammortamento_base === 'listino' ? $record->listino : $record->costo;
                            $mesiAmm = $record->ammortamento_mesi ?: $record->mesi;

                            $voci = [
                                ['Macchina', $record->quota_macchina,
                                    $eur($base).' ('.($record->ammortamento_base === 'listino' ? 'listino' : 'costo').') ÷ '.$mesiAmm.' mesi'
                                    .((float) $record->margine > 0 ? ' + '.rtrim(rtrim((string) $record->margine, '0'), '.').'%' : '')],
                                ['Full-service', $record->quota_servizio,
                                    $eur($record->listino).' × '.rtrim(rtrim((string) $record->full_service_percentuale, '0'), '.').'% ÷ 12'],
                                ['Caffè e polveri', $record->quota_caffe, null],
                                ['Detergenti', $record->quota_detergenti, null],
                                ['Consumabili', $record->quota_consumabili, null],
                            ];

                            $out = '<div style="width:34rem;max-width:100%;">';
                            foreach ($voci as [$etichetta, $importo, $come]) {
                                // Le voci a zero non si stampano: una riga che
                                // dice "niente" e' una riga in piu' da leggere.
                                if ((float) $importo <= 0) {
                                    continue;
                                }
                                $out .= static::riga($etichetta, $come, $eur($importo));
                            }

                            $out .= '<div style="display:flex;justify-content:space-between;gap:1rem;border-top:2px solid currentColor;margin-top:8px;padding-top:8px;font-weight:700;">'
                                .'<span>Canone mensile</span><span>'.$eur($record->canone).'</span></div>'
                                .'<div style="display:flex;justify-content:space-between;gap:1rem;padding-top:4px;opacity:.75;">'
                                .'<span>Su tutto il contratto ('.$record->mesi.' mesi)</span>'
                                .'<span>'.$eur((float) $record->canone * $record->mesi).'</span></div>'
                                .'</div>';

                            return new HtmlString($out);
                        }),
                ]),

            // La domanda che si fa chi prepara un noleggio e che il prospetto
            // non rispondeva: alla fine, quanto ci resta?
            Section::make('Quanto ci resta')
                ->columnSpan(1)
                ->description('I prezzi delle righe sono di vendita: il margine si calcola solo dove è indicato anche il prezzo d\'acquisto. Il full-service non entra nel conto: copre manutenzioni, ricambi e trasferte, non è guadagno.')
                ->schema([
                    TextEntry::make('margine_contratto')
                        ->label('')
                        ->state(function ($record) use ($eur): HtmlString {
                            $mesi = max(1, (int) $record->mesi);

                            $incassoMacchina = (float) $record->quota_macchina * $mesi;
                            $margineMacchina = $incassoMacchina - (float) $record->costo;

                            // I prezzi delle righe sono di VENDITA: il costo
                            // e' un campo a parte, e se manca non si inventa
                            // un margine -- si dice che non si sa.
                            $senzaCosto = $record->forniture->filter(fn ($f) => blank($f->prezzo_acquisto));
                            $ricavoForniture = $record->forniture->sum('costo_mensile');
                            $costoForniture = $record->forniture->sum(
                                fn ($f) => round((float) $f->quantita / 12 * (float) ($f->prezzo_acquisto ?? 0), 2)
                            );

                            $out = '<div style="width:34rem;max-width:100%;">'.static::riga(
                                'Sulla macchina',
                                $eur($incassoMacchina).' incassati − '.$eur($record->costo).' di costo',
                                $eur($margineMacchina),
                                $margineMacchina < 0 ? 'color:#b91c1c;' : '',
                            );

                            if ($record->forniture->isEmpty()) {
                                $margineForniture = 0.0;
                                $out .= static::riga('Sulle forniture', null, 'nessuna fornitura indicata', 'opacity:.7;');
                            } elseif ($senzaCosto->isNotEmpty()) {
                                $margineForniture = null;
                                $out .= static::riga(
                                    'Sulle forniture',
                                    $eur($ricavoForniture).'/mese venduti',
                                    'non calcolabile',
                                    'opacity:.7;',
                                );
                            } else {
                                $margineForniture = ($ricavoForniture - $costoForniture) * $mesi;
                                $out .= static::riga(
                                    'Sulle forniture',
                                    $eur($ricavoForniture).'/mese venduti − '.$eur($costoForniture).'/mese di costo',
                                    $eur($margineForniture),
                                    $margineForniture < 0 ? 'color:#b91c1c;' : '',
                                );
                            }

                            $totale = $margineMacchina + ($margineForniture ?? 0);
                            $out .= '<div style="display:flex;justify-content:space-between;gap:1rem;border-top:2px solid currentColor;margin-top:8px;padding-top:8px;font-weight:700;'
                                .($totale < 0 ? 'color:#b91c1c;' : '').'">'
                                .'<span>Margine su '.$mesi.' mesi'.($margineForniture === null ? ' (solo macchina)' : '').'</span>'
                                .'<span>'.$eur($totale).'</span></div>';

                            if ($senzaCosto->isNotEmpty()) {
                                $out .= '<div style="margin-top:8px;padding:7px 9px;border-radius:8px;background:rgba(180,83,9,.08);color:#b45309;font-size:.9em;">'
                                    .'I prezzi delle forniture sono di vendita. Per sapere quanto si guadagna serve il '
                                    .'<strong>prezzo d\'acquisto</strong>, che manca su '.$senzaCosto->count().' '
                                    .($senzaCosto->count() === 1 ? 'riga' : 'righe').' su '.$record->forniture->count().'.</div>';
                            }

                            return new HtmlString($out.'</div>');
                        }),
                ]),

            Section::make('Cosa comprende il canone')
                ->columnSpanFull()
                ->schema([
                    // Il presupposto da cui nascono le quantita': si legge
                    // prima dell'elenco, perche' e' quello che lo spiega.
                    TextEntry::make('base_consumo')
                        ->label('Consumi calcolati su')
                        ->placeholder('non indicato')
                        ->columnSpanFull(),
                    TextEntry::make('forniture_dettaglio')
                        ->label('')
                        ->state(function ($record): HtmlString {
                            $righe = $record->forniture;
                            if ($righe->isEmpty()) {
                                return new HtmlString('<span style="opacity:.7;">Nessuna fornitura indicata.</span>');
                            }
                            $out = '';
                            foreach ($righe->groupBy('gruppo') as $g => $voci) {
                                $out .= '<div style="margin-top:8px;font-weight:600;">'
                                    .e(NoleggioFornitura::gruppiLabels()[$g] ?? $g)
                                    .' — € '.number_format((float) $voci->sum('costo_mensile'), 2, ',', '.').'</div>';
                                foreach ($voci as $v) {
                                    // La quantita' e' annua, l'importo a destra e' mensile:
                                    // senza dirlo la riga sembra sbagliata di dodici volte.
                                    $q = rtrim(rtrim(number_format((float) $v->quantita, 3, ',', '.'), '0'), ',');
                                    // Il prezzo unitario sta in colonna a quattro decimali,
                                    // ma "€ 17,0000" si legge come un errore di battitura:
                                    // gli zeri in coda si tolgono e restano i centesimi.
                                    $pu = number_format((float) $v->prezzo_unitario, 4, ',', '.');
                                    $pu = preg_replace('/(,\d\d)0+$/', '$1', $pu);

                                    $conto = $q.' × '.e($v->unita).' all\'anno · € '.$pu.' l\'uno'
                                        .(filled($v->note) ? ' · '.e($v->note) : '');

                                    $out .= static::riga(
                                        e($v->voce),
                                        $conto,
                                        '€ '.number_format((float) $v->costo_mensile, 2, ',', '.').'/mese',
                                        '',
                                        'padding-left:14px;',
                                    );
                                }
                            }

                            return new HtmlString($out);
                        }),
                ]),

            Section::make('Note')
                ->columnSpanFull()
                ->collapsed()
                ->visible(fn ($record) => filled($record->note))
                ->schema([TextEntry::make('note')->label('')]),
        ]);
    }

    /**
     * Una riga dei prospetti: voce a sinistra, importo a destra, e sotto la
     * voce -- piu' piccolo e in grigio -- come quell'importo e' venuto fuori.
     *
     * Prima il "come" stava in mezzo, fra l'etichetta e la cifra: la riga
     * diventava un periodo da leggere ("Macchina — € 18.500,00 (listino) ÷
     * 36 mesi + 15%   € 543,06") invece di una voce da scorrere, e le cifre
     * a destra non si incolonnavano piu' con l'occhio. Su una riga sua il
     * conto resta -- serve, e' la ricostruibilita' -- ma non si mette in
     * mezzo (Laura, 03/11/2026: "dati troppo macchinosi").
     *
     * $come arriva gia' scappato dove contiene dati; qui non si ri-escapa,
     * altrimenti i '×' e i '·' tornerebbero entita'.
     */
    protected static function riga(string $etichetta, ?string $come, string $valore, string $stileValore = '', string $stileRiga = ''): string
    {
        return '<div style="display:flex;justify-content:space-between;gap:1rem;padding:5px 0;'.$stileRiga.'">'
            .'<span>'.$etichetta
            .($come ? '<br><span style="opacity:.55;font-size:.82em;">'.$come.'</span>' : '')
            .'</span>'
            .'<span style="white-space:nowrap;'.$stileValore.'">'.$valore.'</span>'
            .'</div>';
    }
}
