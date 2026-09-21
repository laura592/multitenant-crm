<?php

namespace App\Support;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;

/**
 * I listini forniti dai fornitori sono spesso PDF scansionati a piena
 * risoluzione (es. 45MB per un catalogo): nessuna libreria PHP ricomprime
 * le immagini incorporate in un PDF, serve Ghostscript come binario di
 * sistema (vedi docker/8.4/Dockerfile). Il preset /ebook ricampiona le
 * immagini a 150dpi: illeggibile per la prestampa ma ampiamente sufficiente
 * per consultare un catalogo a schermo o in stampa da ufficio.
 */
class PdfCompressor
{
    /**
     * Sull'hosting cPanel di produzione proc_open e' disabilitato: senza
     * questo controllo salvare un documento andava in errore (21/09/2026)
     * invece di saltare la compressione, che e' solo un di piu'.
     */
    public static function isAvailable(): bool
    {
        $disabilitate = array_map('trim', explode(',', (string) ini_get('disable_functions')));

        if (! function_exists('proc_open') || in_array('proc_open', $disabilitate, true)) {
            return false;
        }

        try {
            return Process::run('which gs')->successful();
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Ricomprime il PDF sul posto se Ghostscript e' disponibile e il
     * risultato e' effettivamente piu' piccolo dell'originale. Ritorna true
     * se il file e' stato sostituito.
     */
    public static function compressInPlace(string $absolutePath): bool
    {
        if (! self::isAvailable() || ! is_file($absolutePath)) {
            return false;
        }

        $output = $absolutePath.'.'.Str::random(8).'.tmp';

        $result = Process::timeout(120)->run([
            'gs', '-q', '-dNOPAUSE', '-dBATCH', '-dSAFER',
            '-sDEVICE=pdfwrite',
            '-dCompatibilityLevel=1.4',
            '-dPDFSETTINGS=/ebook',
            '-sOutputFile='.$output,
            $absolutePath,
        ]);

        if (! $result->successful() || ! is_file($output) || filesize($output) === 0) {
            @unlink($output);

            Log::warning('PdfCompressor: compressione fallita', [
                'path' => $absolutePath,
                'error' => $result->errorOutput(),
            ]);

            return false;
        }

        if (filesize($output) >= filesize($absolutePath)) {
            @unlink($output);

            return false;
        }

        rename($output, $absolutePath);

        return true;
    }
}
