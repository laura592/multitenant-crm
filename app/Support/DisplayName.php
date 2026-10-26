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
     * Articoli elisi attaccati alla parola dopo: in "dell'Alto Adige" sono
     * un token solo, quindi la lista qui sopra non li intercettava e ne
     * usciva "Dell'Alto Adige" — stampato nell'oggetto della mail, nel
     * destinatario e nel "Gentile ..." dell'offerta (26/10/2026).
     *
     * Niente "d'" e "l'": "D'Angelo" e "L'Aquila" vogliono la maiuscola, e
     * sono quasi sempre inizio di cognome o di nome proprio.
     */
    private const ELIDED_ARTICLES = ["dell'", "dall'", "all'", "nell'", "sull'"];

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

    /**
     * Etichetta di un cliente in una select: ragione sociale normalizzata piu'
     * il paese fra parentesi. Tante ragioni sociali sono identiche fra clienti
     * diversi (catene e franchising con piu' punti vendita) e senza il paese
     * nell'elenco si vedono tutte uguali. Stessa forma ovunque: preventivi,
     * rapportini, richieste informazioni.
     */
    public static function customerOption(?object $customer): ?string
    {
        if (! $customer) {
            return null;
        }

        $name = self::titleCase($customer->full_name);

        return $customer->city ? "{$name} ({$customer->city})" : $name;
    }

    private static function capitalizeWord(string $word, bool $isFirst): string
    {
        if (! $isFirst && in_array(mb_strtolower($word), self::LOWERCASE_PARTICLES, true)) {
            return mb_strtolower($word);
        }

        if (! $isFirst) {
            foreach (self::ELIDED_ARTICLES as $articolo) {
                if (str_starts_with(mb_strtolower($word), $articolo) && mb_strlen($word) > mb_strlen($articolo)) {
                    return $articolo.self::capitalizeWord(mb_substr($word, mb_strlen($articolo)), false);
                }
            }
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
