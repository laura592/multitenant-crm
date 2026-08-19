<?php

namespace App\Support;

class DisplayName
{
    /**
     * Particelle che restano minuscole a meno che siano la prima parola
     * (es. "Mario De Rossi", ma "Di Marco S.r.l." resta "Di Marco" perché
     * è la prima parola).
     */
    private const LOWERCASE_PARTICLES = ['di', 'de', 'del', "dell'", 'della', 'dei', 'degli', 'delle', 'da', 'van', 'von', 'la', 'lo', 'il'];

    /**
     * Uniforma nomi/ragioni sociali salvati con case incoerente (tutto
     * maiuscolo o tutto minuscolo a seconda di chi/cosa li ha inseriti:
     * operatore da form vs import dal gestionale) in Title Case, senza
     * toccare il dato salvato - solo in visualizzazione.
     */
    public static function titleCase(?string $value): ?string
    {
        if (blank($value)) {
            return $value;
        }

        $words = preg_split('/(\s+)/u', $value, -1, PREG_SPLIT_DELIM_CAPTURE);

        return implode('', array_map(
            fn (string $word, int $i) => trim($word) === '' ? $word : self::capitalizeWord($word, $i === 0),
            $words,
            array_keys($words)
        ));
    }

    private static function capitalizeWord(string $word, bool $isFirst): string
    {
        if (! $isFirst && in_array(mb_strtolower($word), self::LOWERCASE_PARTICLES, true)) {
            return mb_strtolower($word);
        }

        // Spezza anche su "-" e "'" per capitalizzare dopo (es. "Gian-Carlo",
        // "D'Angelo") senza perdere il separatore.
        return preg_replace_callback(
            "/[^-']+/u",
            fn (array $m) => mb_convert_case(mb_strtolower($m[0]), MB_CASE_TITLE),
            $word
        );
    }
}
