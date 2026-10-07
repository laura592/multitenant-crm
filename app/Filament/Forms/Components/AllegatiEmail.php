<?php

namespace App\Filament\Forms\Components;

use Closure;
use Filament\Forms\Components\Placeholder;
use Illuminate\Support\HtmlString;

/**
 * Cosa parte insieme alla mail, scritto nel modulo d'invio.
 *
 * L'anteprima mostrava il testo ma non i file: si premeva "Invia" senza
 * sapere se l'offerta caffe' era inclusa o quale copia del rapportino
 * stesse partendo, e lo si scopriva dalla mail del cliente (Laura,
 * 07/10/2026). Un posto solo per tutti i moduli, cosi' si presentano
 * uguali e nessuno se ne dimentica quando ne nasce uno nuovo.
 */
final class AllegatiEmail
{
    /**
     * @param  Closure|array<int, string>  $nomi  i nomi dei file allegati
     */
    public static function make(Closure|array $nomi): Placeholder
    {
        return Placeholder::make('allegati_email')
            ->label('In allegato')
            ->content(function ($record, $livewire) use ($nomi): HtmlString {
                $elenco = array_values(array_filter(
                    $nomi instanceof Closure ? ($nomi($record, $livewire) ?? []) : $nomi
                ));

                if ($elenco === []) {
                    return new HtmlString('<span style="opacity:.7;">Nessun allegato.</span>');
                }

                $out = '';
                foreach ($elenco as $nome) {
                    $out .= '<div style="display:flex;align-items:center;gap:.4rem;padding:1px 0;">'
                        .'<span style="opacity:.55;">PDF</span>'
                        .'<span style="font-weight:600;">'.e($nome).'</span></div>';
                }

                return new HtmlString($out);
            })
            ->columnSpanFull();
    }
}
