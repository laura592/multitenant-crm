<?php

namespace App\Support\Brevo;

/**
 * Da sigla di provincia a regione, scritta come le liste di Brevo
 * ("Trentino-Alto Adige", "Friuli-Venezia Giulia", "Emilia-Romagna").
 */
final class RegioniProvince
{
    private const REGIONI = [
        'Abruzzo' => ['AQ', 'CH', 'PE', 'TE'],
        'Basilicata' => ['MT', 'PZ'],
        'Calabria' => ['CS', 'CZ', 'KR', 'RC', 'VV'],
        'Campania' => ['AV', 'BN', 'CE', 'NA', 'SA'],
        'Emilia-Romagna' => ['BO', 'FC', 'FE', 'MO', 'PC', 'PR', 'RA', 'RE', 'RN'],
        'Friuli-Venezia Giulia' => ['GO', 'PN', 'TS', 'UD'],
        'Lazio' => ['FR', 'LT', 'RI', 'RM', 'VT'],
        'Liguria' => ['GE', 'IM', 'SP', 'SV'],
        'Lombardia' => ['BG', 'BS', 'CO', 'CR', 'LC', 'LO', 'MB', 'MI', 'MN', 'PV', 'SO', 'VA'],
        'Marche' => ['AN', 'AP', 'FM', 'MC', 'PU'],
        'Molise' => ['CB', 'IS'],
        'Piemonte' => ['AL', 'AT', 'BI', 'CN', 'NO', 'TO', 'VB', 'VC'],
        'Puglia' => ['BA', 'BR', 'BT', 'FG', 'LE', 'TA'],
        'Sardegna' => ['CA', 'NU', 'OR', 'SS', 'SU'],
        'Sicilia' => ['AG', 'CL', 'CT', 'EN', 'ME', 'PA', 'RG', 'SR', 'TP'],
        'Toscana' => ['AR', 'FI', 'GR', 'LI', 'LU', 'MS', 'PI', 'PO', 'PT', 'SI'],
        'Trentino-Alto Adige' => ['BZ', 'TN'],
        'Umbria' => ['PG', 'TR'],
        "Valle d'Aosta" => ['AO'],
        'Veneto' => ['BL', 'PD', 'RO', 'TV', 'VE', 'VI', 'VR'],
    ];

    public static function regione(?string $sigla): ?string
    {
        $sigla = strtoupper(trim((string) $sigla));

        foreach (self::REGIONI as $regione => $sigle) {
            if (in_array($sigla, $sigle, true)) {
                return $regione;
            }
        }

        return null;
    }
}
