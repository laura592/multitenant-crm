<?php

namespace App\Support;

class PhoneNumber
{
    /**
     * Normalizza un numero italiano nel formato "+39" seguito dalla cifre,
     * senza spazi ne' separatori (es. "0438 486794" -> "+390438486794").
     * Se il numero ha gia' un prefisso internazionale (+.. o 00..) viene
     * lasciato cosi' com'e', a parte lo scorporo degli spazi.
     */
    public static function normalizeItalian(?string $value): ?string
    {
        if (blank($value)) {
            return $value;
        }

        $digits = preg_replace('/[^\d+]/', '', $value);

        if (str_starts_with($digits, '+')) {
            return $digits;
        }

        // Prefisso internazionale "00" (es. "0041..." Svizzera, "0049..."
        // Germania, non solo "0039" Italia): va convertito in "+", non
        // ignorato - altrimenti finiva forzato "+39" davanti a un numero
        // gia' completo di un altro paese (bug reale: numeri di clienti
        // svizzeri/tedeschi/croati salvati come "+390041...").
        if (str_starts_with($digits, '00')) {
            return '+'.substr($digits, 2);
        }

        return '+39'.$digits;
    }
}
