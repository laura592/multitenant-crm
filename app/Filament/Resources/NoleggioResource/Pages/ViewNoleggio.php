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

        return $infolist->schema([
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
                    TextEntry::make('canone')->label('Canone mensile')->money('EUR')
                        ->size(TextEntry\TextEntrySize::Large)->weight('bold')
                        ->columnSpan(['default' => 1, 'lg' => 3]),
                    TextEntry::make('customer.company_name')->label('Cliente')
                        ->formatStateUsing(fn (?string $state) => DisplayName::titleCase($state))
                        ->columnSpan(['default' => 1, 'lg' => 4]),
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
                        ->columnSpan(['default' => 1, 'lg' => 1]),
                    TextEntry::make('attrezzatura')->label('Attrezzatura')
                        ->state(fn ($record) => $record->descrizione
                            ?: ($record->machineUnit?->model_name ?: '—'))
                        ->columnSpan(['default' => 1, 'lg' => 7]),
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

                            $out = '<div style="max-width:34rem;">';
                            foreach ($voci as [$etichetta, $importo, $come]) {
                                // Le voci a zero non si stampano: una riga che
                                // dice "niente" e' una riga in piu' da leggere.
                                if ((float) $importo <= 0) {
                                    continue;
                                }
                                $out .= '<div style="display:flex;justify-content:space-between;gap:1rem;padding:3px 0;">'
                                    .'<span>'.e($etichetta)
                                    .($come ? '<span style="opacity:.6;font-size:.85em;"> — '.e($come).'</span>' : '')
                                    .'</span><span style="white-space:nowrap;">'.$eur($importo).'</span></div>';
                            }

                            $out .= '<div style="display:flex;justify-content:space-between;gap:1rem;border-top:2px solid currentColor;margin-top:6px;padding-top:6px;font-weight:700;">'
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
                ->description('Il full-service non entra nel conto: copre manutenzioni, ricambi e trasferte, non è guadagno.')
                ->schema([
                    TextEntry::make('margine_contratto')
                        ->label('')
                        ->state(function ($record) use ($eur): HtmlString {
                            $mesi = max(1, (int) $record->mesi);

                            // Il costo vero delle forniture e' senza ricarico:
                            // quello che si paga al fornitore.
                            $costoForniture = $record->forniture->sum(
                                fn ($f) => round((float) $f->quantita / 12 * (float) $f->prezzo_unitario, 2)
                            );
                            $ricavoForniture = $record->forniture->sum('costo_mensile');

                            $margineForniture = ($ricavoForniture - $costoForniture) * $mesi;
                            $incassoMacchina = (float) $record->quota_macchina * $mesi;
                            $margineMacchina = $incassoMacchina - (float) $record->costo;

                            $righe = [
                                ['Sulla macchina', $margineMacchina,
                                    $eur($incassoMacchina).' incassati − '.$eur($record->costo).' di costo'],
                                ['Sulle forniture', $margineForniture,
                                    $ricavoForniture > 0
                                        ? $eur($ricavoForniture).'/mese venduti − '.$eur($costoForniture).'/mese di costo'
                                        : 'nessuna fornitura indicata'],
                            ];

                            $out = '<div style="max-width:34rem;">';
                            foreach ($righe as [$etichetta, $importo, $come]) {
                                $colore = $importo < 0 ? 'color:#b91c1c;' : '';
                                $out .= '<div style="display:flex;justify-content:space-between;gap:1rem;padding:3px 0;">'
                                    .'<span>'.e($etichetta).'<span style="opacity:.6;font-size:.85em;"> — '.e($come).'</span></span>'
                                    .'<span style="white-space:nowrap;'.$colore.'">'.$eur($importo).'</span></div>';
                            }

                            $totale = $margineMacchina + $margineForniture;
                            $out .= '<div style="display:flex;justify-content:space-between;gap:1rem;border-top:2px solid currentColor;margin-top:6px;padding-top:6px;font-weight:700;'
                                .($totale < 0 ? 'color:#b91c1c;' : '').'">'
                                .'<span>Margine su '.$mesi.' mesi</span><span>'.$eur($totale).'</span></div>';

                            if ($ricavoForniture > 0 && abs($ricavoForniture - $costoForniture) < 0.01) {
                                $out .= '<div style="margin-top:8px;padding:7px 9px;border-radius:8px;background:rgba(185,28,28,.08);color:#b91c1c;font-size:.9em;">'
                                    .'Le forniture sono vendute al prezzo di costo: il ricarico è a zero su tutte le righe.</div>';
                            }

                            return new HtmlString($out.'</div>');
                        }),
                ]),

            Section::make('Il rischio')
                ->description('Il capitale è nostro: finché non si è in pari, una disdetta costa.')
                ->columns(4)
                ->schema([
                    TextEntry::make('mese_pareggio')->label('In pari dal')
                        ->state(fn ($record) => $record->mese_pareggio ? $record->mese_pareggio.'° mese' : '—')
                        ->color(fn ($record) => $record->mese_pareggio && $record->mese_pareggio > $record->mesi ? 'danger' : 'success'),
                    ...collect([12, 24, 36])->map(fn (int $m) => TextEntry::make('scoperto'.$m)
                        ->label('Scoperto al '.$m.'° mese')
                        ->state(fn ($record) => $m <= $record->mesi ? $eur($record->scopertoAl($m)) : '—'))->all(),
                ]),

            Section::make('Cosa comprende il canone')
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
                                    // La quantita' e' annua, il costo a destra e' mensile:
                                    // senza dirlo la riga sembra sbagliata di dodici volte.
                                    $q = rtrim(rtrim(number_format((float) $v->quantita, 3, ',', '.'), '0'), ',');
                                    $out .= '<div style="padding-left:14px;">'.e($v->voce).' — '.$q.' '.e($v->unita).'/anno'
                                        .' × € '.number_format((float) $v->prezzo_unitario, 4, ',', '.')
                                        .' = <strong>€ '.number_format((float) $v->costo_mensile, 2, ',', '.').'</strong>/mese'
                                        .(filled($v->note) ? '<span style="opacity:.65;"> — '.e($v->note).'</span>' : '')
                                        .'</div>';
                                }
                            }

                            return new HtmlString($out);
                        }),
                ]),

            Section::make('Note')
                ->collapsed()
                ->visible(fn ($record) => filled($record->note))
                ->schema([TextEntry::make('note')->label('')]),
        ]);
    }
}
