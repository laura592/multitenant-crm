<?php

namespace App\Support;

/**
 * Il filtro antispam dell'intake dei lead.
 *
 * Il 19/10/2026 due submission identiche sono entrate come RI-2026-0235 e
 * RI-2026-0236 con la ragione sociale piena di HTML ("$3,222 payment
 * available" con link a hotelgrandsharda.com), email usa-e-getta e sede
 * "jhquz0": l'honeypot del form non le ha fermate perche' il bot compila i
 * campi veri. Da fuori sembrava che il CRM fosse stato bucato.
 *
 * Il filtro guarda SOLO i campi dell'identita' (ragione sociale, nome,
 * cognome, sede): un cliente vero non ci scrive mai un link ne' un tag HTML.
 * Le note libere restano intoccate — chi chiede un preventivo ha tutto il
 * diritto di incollarci l'indirizzo del suo sito, e una richiesta vera non
 * si butta mai (regola dell'ufficio: nessun contatto va bloccato).
 */
class LeadSpam
{
    /** Campi in cui un link o un tag HTML non ha nessun motivo di esistere. */
    private const CAMPI_IDENTITA = ['ragione_sociale', 'nome', 'cognome', 'sede'];

    /** Domini usa-e-getta visti nello spam: niente lead veri, solo bot. */
    private const DOMINI_BRUCIATI = [
        'merepost.com',
    ];

    /**
     * @param  array<string, mixed>  $dati
     * @return string|null  il motivo, se e' spam; null se il lead e' buono
     */
    public static function motivo(array $dati): ?string
    {
        foreach (self::CAMPI_IDENTITA as $campo) {
            $valore = (string) ($dati[$campo] ?? '');

            if ($valore === '') {
                continue;
            }

            if (preg_match('~<[a-z/!]|https?://|\[url~i', $valore)) {
                return "campo {$campo} con HTML o link: ".mb_substr($valore, 0, 120);
            }
        }

        $email = mb_strtolower(trim((string) ($dati['email'] ?? '')));
        $dominio = (string) mb_substr($email, (int) mb_strpos($email, '@') + 1);

        if (in_array($dominio, self::DOMINI_BRUCIATI, true)) {
            return "email usa-e-getta: {$email}";
        }

        return null;
    }
}
