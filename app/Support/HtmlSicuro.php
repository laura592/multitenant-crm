<?php

namespace App\Support;

use DOMAttr;
use DOMDocument;
use DOMElement;
use DOMNode;
use DOMXPath;

/**
 * Ripulisce l'HTML scritto nei RichEditor del pannello prima di mostrarlo.
 *
 * Il RichEditor di Filament non sanifica niente lato server: quello che
 * arriva al salvataggio e' l'HTML che il browser ha mandato via Livewire, e
 * un client manomesso puo' mandare qualunque cosa. Quell'HTML poi esce
 * dall'app con {!! !!} in tre posti che contano — la pagina pubblica del
 * preventivo (che apre il CLIENTE, non chi l'ha scritto), il PDF e il corpo
 * delle mail — quindi uno <script> infilato nelle note di un preventivo
 * finiva dritto nel browser del cliente.
 *
 * Allowlist e non blacklist: si tiene solo cio' che il RichEditor produce
 * davvero, tutto il resto viene via. Cosi' un tag o un attributo nuovo (o
 * una codifica furba) e' escluso per default invece che per dimenticanza.
 *
 * I tag non ammessi perdono la marcatura ma NON il testo: se qualcuno ha
 * incollato del markup strano dentro una nota, il cliente continua a
 * leggerne il contenuto. Le sole eccezioni sono i tag il cui contenuto non
 * e' testo da leggere (script, style, ...), che spariscono interi.
 */
class HtmlSicuro
{
    /** Quello che i RichEditor dell'app sanno produrre, piu' il minimo per le mail. */
    private const TAG_AMMESSI = [
        'p', 'br', 'div', 'span',
        'strong', 'b', 'em', 'i', 'u', 's', 'del', 'ins', 'sub', 'sup', 'mark', 'small',
        'ul', 'ol', 'li',
        'h1', 'h2', 'h3', 'h4', 'h5', 'h6',
        'blockquote', 'pre', 'code', 'hr',
        'a', 'img', 'figure', 'figcaption',
        'table', 'thead', 'tbody', 'tfoot', 'tr', 'th', 'td',
    ];

    /** Attributi ammessi, per tag. '*' vale per tutti. */
    private const ATTRIBUTI_AMMESSI = [
        '*' => ['style', 'class', 'dir', 'title'],
        'a' => ['href', 'target', 'rel'],
        'img' => ['src', 'alt', 'width', 'height'],
        'ol' => ['start', 'type'],
        'td' => ['colspan', 'rowspan', 'align'],
        'th' => ['colspan', 'rowspan', 'align', 'scope'],
    ];

    /**
     * Tag da cancellare col loro contenuto: quello che c'e' dentro non e'
     * testo per il lettore, e' codice.
     */
    private const TAG_DA_SVUOTARE = [
        'script', 'style', 'iframe', 'object', 'embed', 'applet', 'noscript',
        'form', 'input', 'button', 'select', 'option', 'textarea', 'template',
        'meta', 'link', 'base', 'svg', 'math', 'frame', 'frameset',
    ];

    /** Gli unici schemi che possono stare in href/src. */
    private const SCHEMI_AMMESSI = ['http', 'https', 'mailto', 'tel'];

    public static function filtra(?string $html): string
    {
        $html = (string) $html;

        if (trim($html) === '') {
            return '';
        }

        $documento = new DOMDocument('1.0', 'UTF-8');

        // LIBXML_NOERROR: l'HTML di un editor non e' mai XHTML valido e i
        // warning di libxml finirebbero nei log a ogni preventivo aperto.
        // Il prefisso XML dichiara l'encoding senza aggiungere nodi: senza,
        // loadHTML legge il UTF-8 come latin1 e le accentate si rompono.
        $caricato = @$documento->loadHTML(
            '<?xml encoding="UTF-8"><div id="radice-html-sicuro">'.$html.'</div>',
            LIBXML_NOERROR | LIBXML_NOWARNING | LIBXML_NONET,
        );

        if (! $caricato) {
            // HTML illeggibile: meglio il solo testo che un HTML che non
            // siamo riusciti a ispezionare.
            return e(strip_tags($html));
        }

        $xpath = new DOMXPath($documento);

        // Commenti: non si vedono, ma possono trasportare markup che un
        // parser permissivo rimette in gioco. Via tutti.
        foreach (iterator_to_array($xpath->query('//comment()') ?: []) as $commento) {
            $commento->parentNode?->removeChild($commento);
        }

        foreach (self::TAG_DA_SVUOTARE as $tag) {
            foreach (iterator_to_array($documento->getElementsByTagName($tag)) as $nodo) {
                $nodo->parentNode?->removeChild($nodo);
            }
        }

        $radice = $documento->getElementById('radice-html-sicuro');

        if (! $radice instanceof DOMElement) {
            return '';
        }

        self::ripulisci($radice);

        $risultato = '';

        foreach ($radice->childNodes as $figlio) {
            $risultato .= $documento->saveHTML($figlio);
        }

        return $risultato;
    }

    /** Scende nell'albero: prima i figli, poi il nodo, cosi' nessuno sfugge. */
    private static function ripulisci(DOMNode $nodo): void
    {
        foreach (iterator_to_array($nodo->childNodes) as $figlio) {
            self::ripulisci($figlio);
        }

        if (! $nodo instanceof DOMElement) {
            return;
        }

        if ($nodo->getAttribute('id') === 'radice-html-sicuro') {
            return;
        }

        if (! in_array(strtolower($nodo->nodeName), self::TAG_AMMESSI, true)) {
            self::scarta($nodo);

            return;
        }

        self::ripulisciAttributi($nodo);
    }

    /** Toglie il tag ma lascia al suo posto quello che conteneva. */
    private static function scarta(DOMElement $elemento): void
    {
        $padre = $elemento->parentNode;

        if (! $padre) {
            return;
        }

        while ($elemento->firstChild) {
            $padre->insertBefore($elemento->firstChild, $elemento);
        }

        $padre->removeChild($elemento);
    }

    private static function ripulisciAttributi(DOMElement $elemento): void
    {
        $tag = strtolower($elemento->nodeName);
        $ammessi = array_merge(self::ATTRIBUTI_AMMESSI['*'], self::ATTRIBUTI_AMMESSI[$tag] ?? []);

        /** @var array<int, DOMAttr> $attributi */
        $attributi = iterator_to_array($elemento->attributes ?? []);

        foreach ($attributi as $attributo) {
            $nome = strtolower($attributo->nodeName);

            // Ogni on* (onclick, onerror, onload...) se ne va sempre, anche
            // se un giorno finisse per sbaglio negli ammessi.
            if (str_starts_with($nome, 'on') || ! in_array($nome, $ammessi, true)) {
                $elemento->removeAttribute($attributo->nodeName);

                continue;
            }

            if (in_array($nome, ['href', 'src'], true) && ! self::urlAmmessa($attributo->nodeValue)) {
                $elemento->removeAttribute($attributo->nodeName);

                continue;
            }

            if ($nome === 'style' && ! self::stileAmmesso($attributo->nodeValue)) {
                $elemento->removeAttribute($attributo->nodeName);
            }
        }

        // Un link che si apre in una scheda nuova senza rel="noopener" da'
        // alla pagina di destinazione un riferimento a questa.
        if ($tag === 'a' && $elemento->getAttribute('target') !== '') {
            $elemento->setAttribute('rel', 'noopener noreferrer');
        }
    }

    private static function urlAmmessa(?string $valore): bool
    {
        $valore = trim((string) $valore);

        if ($valore === '') {
            return false;
        }

        // I caratteri di controllo servono a spezzare "java\0script:" in modo
        // che il confronto non veda lo schema ma il browser si'.
        $valore = preg_replace('/[\x00-\x20]/', '', $valore) ?? '';

        // Relativa o ancora: nessuno schema da valutare.
        if (str_starts_with($valore, '/') || str_starts_with($valore, '#')) {
            return true;
        }

        if (! str_contains($valore, ':')) {
            return true;
        }

        $schema = strtolower(strstr($valore, ':', true) ?: '');

        return in_array($schema, self::SCHEMI_AMMESSI, true);
    }

    /**
     * Lo stile in linea non esegue codice sui browser di oggi, ma puo'
     * ancora caricare roba da fuori (url(...)) o coprire la pagina: si
     * tiene solo se non contiene ne' l'uno ne' i soliti nomi sospetti.
     */
    private static function stileAmmesso(?string $valore): bool
    {
        $valore = strtolower((string) $valore);

        foreach (['url(', 'expression', 'javascript:', '@import', 'behavior:', 'position:fixed'] as $sospetto) {
            if (str_contains($valore, $sospetto)) {
                return false;
            }
        }

        return true;
    }
}
