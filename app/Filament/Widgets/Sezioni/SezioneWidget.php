<?php

namespace App\Filament\Widgets\Sezioni;

use Filament\Widgets\Widget;

/**
 * Titolo di sezione della dashboard: non mostra dati, separa i widget per
 * argomento (cosa fare adesso, andamento commerciale, magazzino...). Prima
 * erano una sequenza piatta di card e tabelle in cui un numero commerciale e
 * un avviso da lavorare avevano lo stesso peso visivo.
 *
 * Ogni sezione dichiara in contenuto() i widget che intestera': se il ruolo
 * non ne vede nessuno (i permessi sono per widget, vedi i canView() dei
 * singoli) il titolo sparisce con loro, altrimenti resterebbe appeso sopra
 * uno spazio vuoto - succede davvero, es. "Andamento commerciale" per il
 * dipendente, che sui preventivi non ha nessun permesso.
 */
abstract class SezioneWidget extends Widget
{
    protected static string $view = 'filament.widgets.sezione';

    // I widget Filament sono lazy di default: caricati dopo il primo paint,
    // con uno scheletro grigio al posto loro. Qui non c'e' niente da
    // caricare (nessuna query, solo un titolo): lazy voleva dire far
    // lampeggiare quattro placeholder e far scendere il contenuto sotto.
    protected static bool $isLazy = false;

    protected int|string|array $columnSpan = 'full';

    abstract public static function titolo(): string;

    abstract public static function sottotitolo(): string;

    abstract public static function icona(): string;

    /**
     * I widget che stanno sotto questo titolo, nell'ordine in cui compaiono.
     *
     * @return array<int, class-string<Widget>>
     */
    abstract public static function contenuto(): array;

    public static function canView(): bool
    {
        foreach (static::contenuto() as $widget) {
            if ($widget::canView()) {
                return true;
            }
        }

        return false;
    }

    /** @return array<string, mixed> */
    protected function getViewData(): array
    {
        return [
            'titolo' => static::titolo(),
            'sottotitolo' => static::sottotitolo(),
            'icona' => static::icona(),
        ];
    }
}
