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

            Section::make('Com\'è composto il canone')
                ->description('Numeri interni: nel contratto il cliente vede solo il totale.')
                ->columns(5)
                ->schema([
                    // La didascalia deve dire il calcolo VERO: diceva sempre
                    // "costo diviso durata del contratto" anche quando si
                    // ammortizzava il listino in tre anni, quindi spiegava un
                    // numero diverso da quello stampato sopra (Laura,
                    // 07/10/2026).
                    TextEntry::make('quota_macchina')->label('Macchina')->money('EUR')
                        ->helperText(function ($record) use ($eur) {
                            $base = $record->ammortamento_base === 'listino' ? $record->listino : $record->costo;
                            $mesi = $record->ammortamento_mesi ?: $record->mesi;

                            return $eur($base).' ('.($record->ammortamento_base === 'listino' ? 'listino' : 'costo').')'
                                .' ÷ '.$mesi.' mesi'
                                .((float) $record->margine > 0 ? ' + '.rtrim(rtrim((string) $record->margine, '0'), '.').'%' : '');
                        }),
                    TextEntry::make('quota_servizio')->label('Full-service')->money('EUR')
                        ->helperText(fn ($record) => $eur($record->listino).' × '
                            .rtrim(rtrim((string) $record->full_service_percentuale, '0'), '.').'% ÷ 12'),
                    TextEntry::make('quota_detergenti')->label('Detergenti')->money('EUR')
                        ->visible(fn ($record) => (float) $record->quota_detergenti > 0),
                    TextEntry::make('quota_consumabili')->label('Consumabili')->money('EUR')
                        ->visible(fn ($record) => (float) $record->quota_consumabili > 0),
                    TextEntry::make('quota_caffe')->label('Caffè e polveri')->money('EUR')
                        ->visible(fn ($record) => (float) $record->quota_caffe > 0),
                    TextEntry::make('incasso')->label('Totale sul contratto')
                        ->state(fn ($record) => $eur((float) $record->canone * $record->mesi)),
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
