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

            [$meta, $data] = explode(',', $state, 2);
            $extension = str_contains($meta, 'png') ? 'png' : 'jpg';
            $path = "{$this->signatureDirectory}/".Str::uuid().".{$extension}";

            Storage::disk($this->signatureDisk)->put($path, base64_decode($data));

            return $path;
        });
    }
}
