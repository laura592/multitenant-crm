<?php

namespace App\Filament\Forms\Components;

use Filament\Forms\Components\Field;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Campo firma touch (docs/architecture.md §10.3): canvas + Alpine.js, gia'
 * parte dello stack Filament/Livewire, nessuna libreria JS esterna. Lo stato
 * Livewire e' temporaneamente una data URL PNG; al dehydrate viene salvata
 * su disco e sostituita dal path, cosi il resto del form/model vede sempre
 * e solo un path, come un FileUpload qualsiasi.
 */
class SignaturePad extends Field
{
    protected string $view = 'filament.forms.components.signature-pad';

    protected string $signatureDisk = 'public';

    protected string $signatureDirectory = 'signatures';

    /**
     * ~1.5MB decodificati (base64 e' ~33% piu' grande): ampiamente sufficiente per
     * un tratto di firma disegnato su canvas, previene un payload Livewire manomesso
     * usato per scrivere file enormi su disco pubblico.
     */
    private const MAX_BASE64_LENGTH = 2 * 1024 * 1024;

    public function disk(string $disk): static
    {
        $this->signatureDisk = $disk;

        return $this;
    }

    public function directory(string $directory): static
    {
        $this->signatureDirectory = $directory;

        return $this;
    }

    public function getDisk(): string
    {
        return $this->signatureDisk;
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->dehydrateStateUsing(function (?string $state) {
            if (! $state || ! str_starts_with($state, 'data:image')) {
                return $state; // nessuna nuova firma tracciata: lascia il path esistente (o null)
            }

            if (! str_contains($state, ',')) {
                return null;
            }

            [, $data] = explode(',', $state, 2);

            // Payload molto piu' grande di qualsiasi firma disegnata su canvas: il
            // client Livewire e' stato manomesso, scarta prima di decodificare.
            if (strlen($data) > self::MAX_BASE64_LENGTH) {
                return null;
            }

            $decoded = base64_decode($data, strict: true);

            if ($decoded === false) {
                return null;
            }

            // Verifica sui byte decodificati, non sul prefisso "data:image/..." del
            // client (falsificabile): solo cosi' siamo certi di scrivere su disco
            // pubblico un'immagine vera e non un payload arbitrario.
            $imageInfo = @getimagesizefromstring($decoded);

            if ($imageInfo === false || ! in_array($imageInfo[2], [IMAGETYPE_PNG, IMAGETYPE_JPEG], true)) {
                return null;
            }

            $extension = $imageInfo[2] === IMAGETYPE_PNG ? 'png' : 'jpg';
            $path = "{$this->signatureDirectory}/".Str::uuid().".{$extension}";

            Storage::disk($this->signatureDisk)->put($path, $decoded);

            return $path;
        });
    }
}
