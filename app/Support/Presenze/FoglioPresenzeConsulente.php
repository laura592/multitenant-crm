<?php

namespace App\Support\Presenze;

use App\Support\DisplayName;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Writer\Xls;

/**
 * Il foglio presenze nel formato dello Studio Menoncello (02/11/2026).
 *
 * Patrizia ha mandato il modello in bianco da usare "per i mesi futuri"
 * (resources/templates/foglio-presenze.xls). Fino a oggi quel foglio si
 * compilava a mano, ed e' la stessa trascrizione che per ottobre ha prodotto
 * un cartellino con il bianchetto sopra una giornata e un 7 scritto a penna.
 *
 * Si riempie il LORO file invece di rifarne uno uguale: cosi' il consulente
 * riceve esattamente il foglio che ha mandato, con le sue colonne e i suoi
 * totali, e non deve fidarsi del fatto che gli assomigli.
 *
 * La mappa del modello:
 *
 *   N2         mese e anno (data vera, non testo)
 *   V2         ragione sociale
 *   riga 4     i giorni 1..31 nelle colonne I..AM, "Totali" in AN
 *   righe 5-7  primo dipendente: cognome in B5, nome in B6, e le tre righe
 *              O (ordinarie, 5), S (straordinario, 6), Altro (7)
 *   poi ogni dipendente occupa tre righe: 8-10, 11-13, 14-16...
 *
 * I totali in colonna AN sono formule del modello: non si toccano, si
 * ricalcolano da soli quando il consulente apre il file.
 */
final class FoglioPresenzeConsulente
{
    /** Colonna I: il giorno 1. */
    private const PRIMA_COLONNA_GIORNO = 9;

    private const PRIMA_RIGA = 5;

    private const RIGHE_PER_DIPENDENTE = 3;

    /** Quanti dipendenti entrano nel modello senza aggiungere righe. */
    public const POSTI = 9;

    /**
     * @param  Collection<int, array<string, mixed>>  $righeGiornaliere  da RiepilogoOre::getDailyDetailRows()
     * @return array{percorso: string, scritti: int, fuori: array<int, string>}
     */
    public static function componi(int $anno, int $mese, Collection $righeGiornaliere, ?string $ragioneSociale): array
    {
        $libro = IOFactory::load(resource_path('templates/foglio-presenze.xls'));
        $foglio = $libro->getSheet(0);

        $foglio->setCellValue('N2', ExcelDate::PHPToExcel(Carbon::create($anno, $mese, 1)));

        if ($ragioneSociale) {
            $foglio->setCellValue('V2', $ragioneSociale);
        }

        $perDipendente = $righeGiornaliere->groupBy('user');
        $fuori = [];
        $scritti = 0;

        foreach ($perDipendente as $nomeCompleto => $giornate) {
            // Il modello ha nove posti. Chi non ci sta non va perso in
            // silenzio: lo diciamo a chi scarica, cosi' aggiunge le righe
            // invece di accorgersene dal cedolino.
            if ($scritti >= self::POSTI) {
                $fuori[] = (string) $nomeCompleto;

                continue;
            }

            self::scriviDipendente(
                $foglio,
                self::PRIMA_RIGA + ($scritti * self::RIGHE_PER_DIPENDENTE),
                (string) $nomeCompleto,
                $giornate,
            );

            $scritti++;
        }

        $percorso = tempnam(sys_get_temp_dir(), 'presenze-').'.xls';
        (new Xls($libro))->save($percorso);

        return ['percorso' => $percorso, 'scritti' => $scritti, 'fuori' => $fuori];
    }

    /** @param  Collection<int, array<string, mixed>>  $giornate */
    private static function scriviDipendente(Worksheet $foglio, int $riga, string $nomeCompleto, Collection $giornate): void
    {
        [$cognome, $nome] = self::cognomeENome($nomeCompleto);

        $foglio->setCellValue('B'.$riga, $cognome);
        $foglio->setCellValue('B'.($riga + 1), $nome);

        foreach ($giornate as $giornata) {
            $colonna = self::PRIMA_COLONNA_GIORNO + ((int) $giornata['date']->format('j')) - 1;

            self::ore($foglio, $colonna, $riga, $giornata['ordinarie'] ?? 0);
            self::ore($foglio, $colonna, $riga + 1, $giornata['straordinario'] ?? 0);

            // La riga "Altro" tiene ferie, permessi, malattia e Legge 104:
            // nel modello ce n'e' una sola, e la sigla sta accanto alle ore
            // perche' una cifra nuda non dice di che assenza si tratta.
            if (! empty($giornata['assenza'])) {
                $foglio->setCellValueExplicit(
                    self::cella($colonna, $riga + 2),
                    self::siglaAssenza((string) $giornata['assenza']),
                    DataType::TYPE_STRING,
                );
            }
        }
    }

    private static function ore(Worksheet $foglio, int $colonna, int $riga, mixed $valore): void
    {
        $valore = (float) $valore;

        // Zero non si scrive: un foglio pieno di zeri non si legge, e la
        // casella vuota e' quello che il consulente si aspetta.
        if ($valore <= 0) {
            return;
        }

        $foglio->setCellValue(self::cella($colonna, $riga), $valore);
    }

    private static function cella(int $colonna, int $riga): string
    {
        return Coordinate::stringFromColumnIndex($colonna).$riga;
    }

    /** "Permesso 104 (7.00 h)" diventa "104 7"; le ferie diventano "F". */
    private static function siglaAssenza(string $assenza): string
    {
        if (preg_match('/\(([\d.,]+)\s*h\)/', $assenza, $m)) {
            $ore = rtrim(rtrim(str_replace(',', '.', $m[1]), '0'), '.');

            return str_contains($assenza, '104') ? "104 {$ore}" : "P {$ore}";
        }

        return match (true) {
            str_contains($assenza, 'Ferie') => 'F',
            str_contains($assenza, 'Malattia') => 'M',
            default => 'A',
        };
    }

    /**
     * Il modello vuole cognome e nome su due righe. Nel CRM il nome e'
     * scritto "Igor Capiotto": l'ultima parola e' il cognome per tutte e
     * cinque le persone in anagrafica. Con un doppio cognome andrebbe
     * corretto a mano, che e' meglio che indovinare.
     *
     * @return array{0: string, 1: string}
     */
    private static function cognomeENome(string $nomeCompleto): array
    {
        $pulito = trim(DisplayName::titleCase($nomeCompleto) ?? $nomeCompleto);
        $parti = array_values(array_filter(preg_split('/\s+/', $pulito) ?: []));

        if (count($parti) < 2) {
            return [mb_strtoupper($pulito), ''];
        }

        $cognome = array_pop($parti);

        return [mb_strtoupper($cognome), mb_strtoupper(implode(' ', $parti))];
    }
}
