<?php

namespace App\Support;

class LavaggioDescrizione
{
    /**
     * Suffissi digitati in decine di varianti diverse nel corso degli anni
     * (AP/APE/APER/APERTURA, CHIUS/CHIU/CHIUSURA, ...) verso un'unica forma
     * canonica.
     */
    private const SUFFIXES = [
        'AP' => 'Apertura', 'APE' => 'Apertura', 'APER' => 'Apertura', 'APERTURA' => 'Apertura',
        'CHIUS' => 'Chiusura', 'CHIU' => 'Chiusura', 'CHIUSURA' => 'Chiusura',
        'AC' => 'Acqua', 'ACQ' => 'Acqua', 'ACQUA' => 'Acqua',
        'DISIN' => 'Disinfezione', 'DISINFEZIONE' => 'Disinfezione',
    ];

    /**
     * Normalizza le descrizioni libere dei lavaggi (es. "2 V IE", "5VIE+AP.",
     * "chius. 2vie") in una forma coerente ("2 Vie", "5 Vie + Apertura",
     * "2 Vie + Chiusura"). Non tocca le stringhe generate automaticamente da
     * ServiceReport::syncMaintenanceSchedule()/ReconstructLavaggiHistory
     * ("Generato da rapportino ..."): non sono digitate a mano e alterarle
     * romperebbe la convenzione che quelle due classi si aspettano.
     */
    public static function normalize(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = trim(preg_replace('/\s+/', ' ', $value));

        if ($value === '' || str_starts_with(strtolower($value), 'generato da rapportino')) {
            return $value;
        }

        $value = self::reglueLetters($value);

        $segments = array_map('trim', explode('+', $value));
        $normalized = array_map(fn (string $s) => self::normalizeSegment($s)[0], $segments);

        return implode(' + ', array_filter($normalized, fn ($s) => $s !== ''));
    }

    /**
     * True se ogni segmento e' stato riconosciuto con certezza (conteggio
     * vie/via o suffisso noto), false se almeno uno e' finito nel fallback di
     * pura capitalizzazione. Usato dal comando di bonifica per segnalare le
     * righe da rivedere a mano invece di fidarsi ciecamente del risultato.
     */
    public static function isFullyRecognized(?string $value): bool
    {
        if ($value === null || trim($value) === '') {
            return true;
        }

        $value = self::reglueLetters(trim(preg_replace('/\s+/', ' ', $value)));

        if (str_starts_with(strtolower($value), 'generato da rapportino')) {
            return true;
        }

        foreach (explode('+', $value) as $segment) {
            $segment = trim($segment);

            if ($segment === '') {
                continue;
            }

            if (! self::normalizeSegment($segment)[1]) {
                return false;
            }
        }

        return true;
    }

    /**
     * Refusi da digitazione lettera per lettera ("2 V IE", "V I A") vanno
     * ricomposti prima di spezzare in segmenti, altrimenti nessun pattern
     * sotto li riconoscerebbe.
     */
    private static function reglueLetters(string $value): string
    {
        $value = preg_replace('/\bV\s*I\s*E\b/i', 'VIE', $value);

        return preg_replace('/\bV\s*I\s*A\b/i', 'VIA', $value);
    }

    /**
     * @return array{0: string, 1: bool} valore normalizzato e se il pattern
     *                                    e' stato riconosciuto con certezza
     */
    private static function normalizeSegment(string $segment): array
    {
        if ($segment === '') {
            return ['', true];
        }

        // "2 imp x 1 via" in tutte le grafie (spazi/maiuscole libere).
        if (preg_match('/^(\d+)\s*imp\s*[xX]\s*(\d+)\s*(VIE|VIA)\.?$/i', $segment, $m)) {
            return [$m[1].' Imp x '.$m[2].' '.ucfirst(strtolower($m[3])), true];
        }

        // "1 via 2 imp" (macchina condivisa da piu' impianti).
        if (preg_match('/^(\d+)\s*(VIE|VIA)\s*(\d+)\s*imp\.?$/i', $segment, $m)) {
            return [$m[1].' '.ucfirst(strtolower($m[2])).' '.$m[3].' Imp', true];
        }

        // "2x1via" / "2 X 2VIE" (moltiplicazione senza la parola "imp").
        if (preg_match('/^(\d+)\s*[xX]\s*(\d+)\s*(VIE|VIA)\.?$/', $segment, $m)) {
            return [$m[1].'x '.$m[2].' '.ucfirst(strtolower($m[3])), true];
        }

        // Il caso di gran lunga piu' comune: "2VIE" / "1 VIA" / "5VIE.".
        if (preg_match('/^(\d+)\s*(VIE|VIA)\.?$/i', $segment, $m)) {
            return [$m[1].' '.ucfirst(strtolower($m[2])), true];
        }

        // "LAV7" / "LAV2VIE" (prefisso ridondante visto che siamo gia' dentro
        // un lavaggio: "vie" e' implicito quando non specificato).
        if (preg_match('/^LAV\.?\s*(\d+)\s*(VIE|VIA)?\.?$/i', $segment, $m)) {
            $unit = ($m[2] ?? '') !== '' ? $m[2] : 'VIE';

            return [$m[1].' '.ucfirst(strtolower($unit)), true];
        }

        $suffixPattern = implode('|', array_keys(self::SUFFIXES));

        // Suffisso e conteggio vie/via nello stesso segmento, in un ordine o
        // nell'altro, con o senza spazio/punto separatore (es. "AP.4VIE",
        // "CHIUS2VIE", "CHIUS. 1 VIA", "8VIE AP.").
        if (preg_match('/^('.$suffixPattern.')\.?\s*(\d+)\s*(VIE|VIA)\.?$/i', $segment, $m)) {
            return [$m[2].' '.ucfirst(strtolower($m[3])).' + '.self::SUFFIXES[strtoupper($m[1])], true];
        }

        if (preg_match('/^(\d+)\s*(VIE|VIA)\.?\s*('.$suffixPattern.')\.?$/i', $segment, $m)) {
            return [$m[1].' '.ucfirst(strtolower($m[2])).' + '.self::SUFFIXES[strtoupper($m[3])], true];
        }

        $key = strtoupper(rtrim($segment, '.'));

        if (isset(self::SUFFIXES[$key])) {
            return [self::SUFFIXES[$key], true];
        }

        // Sigla/refuso non riconosciuto (es. "4C", "f3", "CUA"): non si tenta
        // di indovinarne il significato, si applica solo una capitalizzazione
        // pulita e si segnala come non riconosciuto per la revisione manuale.
        return [mb_convert_case(mb_strtolower($segment), MB_CASE_TITLE), false];
    }
}
