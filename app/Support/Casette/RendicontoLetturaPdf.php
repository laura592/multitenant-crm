<?php

namespace App\Support\Casette;

use App\Models\LetturaCasetta;
use Barryvdh\DomPDF\Facade\Pdf;
use Barryvdh\DomPDF\PDF as DomPdf;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Il rendiconto di una singola lettura: la data, i tre contatori e le foto
 * dei display (16/11/2026).
 *
 * Nasce per l'amministrazione che fattura le casette ai Comuni. Finora le
 * foto le arrivavano su WhatsApp, senza data, senza posizione e senza un
 * nome che dicesse quale display fossero: erano attribuibili solo
 * ricordandosi dove fosse stato il tecnico quel giorno. Qui ogni foto sta
 * sotto il numero che documenta, e la data della lettura e' in testa al
 * foglio.
 */
final class RendicontoLetturaPdf
{
    /**
     * Oltre questa larghezza le foto si rimpiccioliscono. Un display
     * fotografato col telefono arriva a 4000px: tre foto cosi' fanno un
     * allegato che il server di posta rifiuta, e a 1200px il numero sul
     * display si legge lo stesso.
     */
    private const LARGHEZZA_MAX = 1200;

    public static function per(LetturaCasetta $lettura): DomPdf
    {
        $lettura->loadMissing(['machineUnit', 'customer', 'rilevataDa', 'tenant']);

        return Pdf::loadView('pdf.lettura-casetta', [
            'lettura' => $lettura,
            'tenant' => $lettura->tenant,
            'foto' => self::foto($lettura),
        ]);
    }

    public static function nomeFile(LetturaCasetta $lettura): string
    {
        $casetta = Str::slug((string) ($lettura->machineUnit?->serial_number ?: 'casetta'));

        return "lettura-{$casetta}-{$lettura->data->format('Y-m-d')}.pdf";
    }

    public static function oggetto(LetturaCasetta $lettura): string
    {
        $casetta = $lettura->machineUnit?->serial_number
            ?: $lettura->customer?->company_name
            ?: 'casetta dell\'acqua';

        return "Lettura {$casetta} del {$lettura->data->format('d/m/Y')}";
    }

    /**
     * Le foto nell'ordine in cui si leggono sulla casetta, ognuna con il
     * numero che documenta. Una foto che non esiste piu' sul disco viene
     * saltata: meglio un rendiconto con due foto che un PDF che non si
     * genera.
     *
     * @return array<int, array{titolo: string, valore: ?string, src: string, w: int, h: int}>
     */
    private static function foto(LetturaCasetta $lettura): array
    {
        $attese = [
            ['Contatore dell\'acqua', $lettura->foto_contatore, self::numero($lettura->contatore_acqua, $lettura->unitaBreve())],
            ['Display incasso', $lettura->foto_incasso, self::numero($lettura->incasso_totale, '€')],
            ['Display venduto', $lettura->foto_vendite, self::numero($lettura->valore_vendite, '€')],
        ];

        $foto = [];

        foreach ($attese as [$titolo, $percorso, $valore]) {
            if ($immagine = self::immagine($percorso)) {
                $foto[] = ['titolo' => $titolo, 'valore' => $valore] + $immagine;
            }
        }

        foreach ($lettura->foto_altre ?? [] as $percorso) {
            if ($immagine = self::immagine(is_string($percorso) ? $percorso : null)) {
                $foto[] = ['titolo' => 'Altra foto', 'valore' => null] + $immagine;
            }
        }

        return $foto;
    }

    private static function numero(mixed $valore, string $unita): ?string
    {
        return $valore === null ? null : number_format((float) $valore, 2, ',', '.').' '.$unita;
    }

    /**
     * La foto pronta per il PDF, con le misure finali: senza quelle il
     * foglio non sa quanto spazio riservarle, e una foto verticale esce
     * alta due pagine.
     *
     * @return array{src: string, w: int, h: int}|null
     */
    private static function immagine(?string $percorso): ?array
    {
        if (! $percorso) {
            return null;
        }

        $disco = Storage::disk('local');

        if (! $disco->exists($percorso)) {
            return null;
        }

        $file = $disco->path($percorso);

        if ($ridotta = self::ridotta($file)) {
            return $ridotta;
        }

        // Ripiego: il percorso nudo basta a dompdf, perche' storage/ sta
        // dentro il suo chroot (config/dompdf.php). La foto e' solo piu'
        // pesante.
        $misure = @getimagesize($file);

        return $misure === false
            ? null
            : ['src' => $file, 'w' => (int) $misure[0], 'h' => (int) $misure[1]];
    }

    /**
     * La foto riscritta in JPEG, raddrizzata e larga al massimo
     * LARGHEZZA_MAX, come data URI. Null a ogni intoppo: chi chiama ripiega
     * sull'originale, che e' solo piu' pesante.
     *
     * @return array{src: string, w: int, h: int}|null
     */
    private static function ridotta(string $file): ?array
    {
        if (! function_exists('imagecreatefromstring')) {
            return null;
        }

        $dati = @file_get_contents($file);

        if ($dati === false) {
            return null;
        }

        $sorgente = @imagecreatefromstring($dati);

        if (! $sorgente) {
            return null;
        }

        $sorgente = self::raddrizzata($sorgente, $file);

        $larghezza = imagesx($sorgente);
        $altezza = imagesy($sorgente);
        $scala = $larghezza > self::LARGHEZZA_MAX ? self::LARGHEZZA_MAX / $larghezza : 1.0;
        $finaleL = max(1, (int) round($larghezza * $scala));
        $finaleA = max(1, (int) round($altezza * $scala));

        // Tela bianca e non trasparente: un PNG con trasparenza riscritto in
        // JPEG diventerebbe nero dove era trasparente.
        $tela = imagecreatetruecolor($finaleL, $finaleA);
        imagefill($tela, 0, 0, imagecolorallocate($tela, 255, 255, 255));
        imagealphablending($tela, true);
        imagecopyresampled($tela, $sorgente, 0, 0, 0, 0, $finaleL, $finaleA, $larghezza, $altezza);
        imagedestroy($sorgente);

        ob_start();
        $riuscito = imagejpeg($tela, null, 72);
        $jpeg = ob_get_clean();
        imagedestroy($tela);

        if (! $riuscito || ! $jpeg) {
            return null;
        }

        return [
            'src' => 'data:image/jpeg;base64,'.base64_encode($jpeg),
            'w' => $finaleL,
            'h' => $finaleA,
        ];
    }

    /**
     * Le foto scattate col telefono portano l'orientamento negli EXIF invece
     * che nei pixel: senza questo, il display fotografato in verticale esce
     * coricato nel rendiconto.
     *
     * @param  \GdImage  $immagine
     * @return \GdImage
     */
    private static function raddrizzata($immagine, string $file)
    {
        if (! function_exists('exif_read_data') || ! function_exists('imagerotate')) {
            return $immagine;
        }

        $exif = @exif_read_data($file);
        $gradi = match ((int) ($exif['Orientation'] ?? 1)) {
            3 => 180,
            6 => -90,
            8 => 90,
            default => 0,
        };

        if ($gradi === 0) {
            return $immagine;
        }

        $ruotata = @imagerotate($immagine, $gradi, 0);

        if (! $ruotata) {
            return $immagine;
        }

        imagedestroy($immagine);

        return $ruotata;
    }
}
