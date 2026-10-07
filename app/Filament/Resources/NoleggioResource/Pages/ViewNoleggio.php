<?php

namespace App\Filament\Resources\NoleggioResource\Pages;

use App\Filament\Resources\NoleggioResource;
use App\Models\NoleggioFornitura;
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
            Section::make()
                ->columns(4)
                ->schema([
                    TextEntry::make('canone')->label('Canone mensile')->money('EUR')
                        ->size(TextEntry\TextEntrySize::Large)->weight('bold'),
                    TextEntry::make('mesi')->label('Durata')->suffix(' mesi'),
                    TextEntry::make('customer.company_name')->label('Cliente'),
                    TextEntry::make('stato')->label('Stato')->badge()
                        // Il parametro si deve chiamare $state: Filament
                        // risolve le closure per NOME, non per posizione, e
                        // con un nome inventato la pagina va in errore.
                        ->formatStateUsing(fn (string $state) => \App\Models\Noleggio::statiLabels()[$state] ?? $state),
                ]),

            Section::make('Com\'è composto il canone')
                ->description('Numeri interni: nel contratto il cliente vede solo il totale.')
                ->columns(5)
                ->schema([
                    TextEntry::make('quota_macchina')->label('Macchina')->money('EUR')
                        ->helperText(fn ($record) => $eur($record->costo).' ÷ '.$record->mesi.' mesi'
                            .((float) $record->margine > 0 ? ' + '.rtrim(rtrim((string) $record->margine, '0'), '.').'%' : '')),
                    TextEntry::make('quota_servizio')->label('Full-service')->money('EUR')
                        ->helperText(fn ($record) => $eur($record->listino).' × '
                            .rtrim(rtrim((string) $record->full_service_percentuale, '0'), '.').'% ÷ 12'),
                    TextEntry::make('quota_detergenti')->label('Detergenti')->money('EUR'),
                    TextEntry::make('quota_caffe')->label('Caffè e polveri')->money('EUR'),
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
                                    $q = rtrim(rtrim(number_format((float) $v->quantita, 3, ',', '.'), '0'), ',');
                                    $out .= '<div style="padding-left:14px;">'.e($v->voce).' — '.$q.' '.e($v->unita)
                                        .' × € '.number_format((float) $v->prezzo_unitario, 4, ',', '.')
                                        .' = <strong>€ '.number_format((float) $v->costo_mensile, 2, ',', '.').'</strong>'
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
