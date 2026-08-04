<?php

namespace App\Console\Commands;

use App\Models\PriceList;
use App\Support\PdfCompressor;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

/**
 * Backfill una tantum per i listini caricati prima che PriceList::booted()
 * ricomprimesse automaticamente i PDF con Ghostscript (vedi PdfCompressor).
 * Da rilanciare anche in produzione dopo il deploy dell'immagine Docker con
 * Ghostscript incluso.
 */
class CompressPriceListPdfs extends Command
{
    protected $signature = 'price-lists:compress';

    protected $description = 'Ricomprime con Ghostscript i PDF dei listini gia caricati';

    public function handle(): int
    {
        if (! PdfCompressor::isAvailable()) {
            $this->error('Ghostscript (gs) non e\' disponibile su questo sistema: nessun file e\' stato toccato.');

            return self::FAILURE;
        }

        $disk = Storage::disk('public');
        $compressed = 0;

        foreach (PriceList::query()->whereNotNull('file_path')->get() as $priceList) {
            if (! $disk->exists($priceList->file_path)) {
                continue;
            }

            $absolutePath = $disk->path($priceList->file_path);
            $before = filesize($absolutePath);

            if (! PdfCompressor::compressInPlace($absolutePath)) {
                $this->line("{$priceList->name}: nessun guadagno, lasciato invariato ({$this->formatBytes($before)}).");

                continue;
            }

            clearstatcache(true, $absolutePath);
            $after = filesize($absolutePath);
            $compressed++;

            $this->info("{$priceList->name}: {$this->formatBytes($before)} -> {$this->formatBytes($after)}");
        }

        $this->info("Listini ricompressi: {$compressed}.");

        return self::SUCCESS;
    }

    private function formatBytes(int $bytes): string
    {
        return $bytes >= 1024 * 1024
            ? round($bytes / (1024 * 1024), 2).' MB'
            : round($bytes / 1024, 1).' KB';
    }
}
