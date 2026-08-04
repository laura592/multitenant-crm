<?php

namespace App\Console\Commands;

use App\Models\Tenant;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

/**
 * I loghi caricati prima del resize lato client su TenantResource (vedi
 * imageResizeTargetWidth/Height) possono essere a risoluzione fotocamera pur
 * essendo mostrati nei PDF a 180x60px al massimo: questo comando li
 * ricomprime una tantum a una bounding box di stampa (600x600) per far
 * dimagrire i PDF generati da Pdf::loadView, senza toccare l'aspetto.
 */
class OptimizeTenantLogos extends Command
{
    protected $signature = 'tenants:optimize-logos {--max-dimension=600}';

    protected $description = 'Ricomprime i loghi tenant già caricati che superano la risoluzione necessaria per i PDF';

    public function handle(): int
    {
        $maxDimension = (int) $this->option('max-dimension');
        $disk = Storage::disk('public');
        $optimized = 0;

        foreach (Tenant::query()->whereNotNull('logo_path')->get() as $tenant) {
            $path = $tenant->logo_path;

            if (! $disk->exists($path)) {
                continue;
            }

            $absolutePath = $disk->path($path);
            [$width, $height, $type] = @getimagesize($absolutePath) ?: [0, 0, null];

            if (! $width || ! $height || $width <= $maxDimension && $height <= $maxDimension) {
                continue;
            }

            $source = match ($type) {
                IMAGETYPE_PNG => imagecreatefrompng($absolutePath),
                IMAGETYPE_JPEG => imagecreatefromjpeg($absolutePath),
                IMAGETYPE_WEBP => imagecreatefromwebp($absolutePath),
                default => null,
            };

            if (! $source) {
                $this->warn("Formato non gestito, salto: {$path}");

                continue;
            }

            $scale = min($maxDimension / $width, $maxDimension / $height);
            $newWidth = max(1, (int) round($width * $scale));
            $newHeight = max(1, (int) round($height * $scale));

            $resized = imagecreatetruecolor($newWidth, $newHeight);
            imagealphablending($resized, false);
            imagesavealpha($resized, true);
            imagecopyresampled($resized, $source, 0, 0, 0, 0, $newWidth, $newHeight, $width, $height);

            $before = filesize($absolutePath);

            match ($type) {
                IMAGETYPE_PNG => imagepng($resized, $absolutePath, 9),
                IMAGETYPE_JPEG => imagejpeg($resized, $absolutePath, 85),
                IMAGETYPE_WEBP => imagewebp($resized, $absolutePath, 85),
                default => null,
            };

            imagedestroy($source);
            imagedestroy($resized);

            $after = filesize($absolutePath);
            $optimized++;

            $this->info(sprintf(
                '%s: %dx%d -> %dx%d, %s -> %s',
                $tenant->name,
                $width,
                $height,
                $newWidth,
                $newHeight,
                $this->formatBytes($before),
                $this->formatBytes($after),
            ));
        }

        $this->info("Loghi ottimizzati: {$optimized}.");

        return self::SUCCESS;
    }

    private function formatBytes(int $bytes): string
    {
        return $bytes >= 1024 * 1024
            ? round($bytes / (1024 * 1024), 2).' MB'
            : round($bytes / 1024, 1).' KB';
    }
}
