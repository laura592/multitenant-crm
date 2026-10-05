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

    /**
     * Disco privato, non "public": una firma autografa e' un dato personale
     * e da /storage si apriva senza login, a chiunque avesse l'indirizzo.
     * Si serve dalla rotta service-reports.firma, che chiede il permesso.
     * Vedi App\Support\Rapportini\FirmaCliente.
     */
    protected string $signatureDisk = 'local';

    protected string $signatureDirectory = 'signatures';

    /**
     * ~1.5MB decodificati (base64 e' ~33% piu' grande): ampiamente sufficiente per
     * un tratto di firma disegnato su canvas, previene un payload Livewire manomesso
     * usato per scrivere file enormi su disco.
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
            // un'immagine vera e non un payload arbitrario.
            $imageInfo = @getimagesizefromstring($decoded);

            if ($imageInfo === false || ! in_array($imageInfo[2], [IMAGETYPE_PNG, IMAGETYPE_JPEG], true)) {
                return null;
            }

            $extension = $imageInfo[2] === IMAGETYPE_PNG ? 'png' : 'jpg';
            $path = "{$this->signatureDirectory}/".Str::uuid().".{$extension}";

            // Il risultato della scrittura va guardato: put() non lancia
            // eccezioni, torna false e basta (i dischi Laravel hanno
            // 'throw' => false). Prima lo si ignorava e si restituiva il path
            // lo stesso, quindi un rapportino poteva risultare firmato con il
            // percorso di un file mai scritto: nessun errore a schermo, niente
            // nei log, e il disegno del cliente perso per sempre. E' successo
            // dal 02/10 al 05/10/2026 su 39 rapportini, perche' la cartella
            // privata non era scrivibile da www-data dopo il trasloco sul VPS.
            //
            // Meglio fermarsi rumorosamente: il tecnico ha ancora il cliente
            // davanti e puo' far rifirmare, mentre una firma fantasma la si
            // scopre mesi dopo, quando serve.
            if (Storage::disk($this->signatureDisk)->put($path, $decoded) === false) {
                report(new \RuntimeException(
                    'Firma non salvata: scrittura fallita su disco "'.$this->signatureDisk.'" in "'.$path.'".'
                ));

                throw new \RuntimeException(
                    'Non sono riuscito a salvare la firma. Riprova: se il problema si ripete, '
                    .'avvisa chi segue il gestionale senza chiudere il rapportino.'
                );
            }

            return $path;
        });
    }
}
