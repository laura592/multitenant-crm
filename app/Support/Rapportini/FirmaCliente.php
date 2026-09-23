<?php

namespace App\Support\Rapportini;

use Illuminate\Support\Facades\Storage;

/**
 * Dove sta la firma di un rapportino e come leggerla.
 *
 * Le firme nascevano sul disco "public" (storage/app/public, esposto da
 * /storage): chiunque avesse l'indirizzo apriva la firma autografa di un
 * cliente senza essere loggato. Il nome del file e' un UUID, quindi non si
 * indovina, ma "difficile da indovinare" non e' un controllo d'accesso — e
 * una firma autografa e' un dato personale a tutti gli effetti. La firma di
 * accettazione dei preventivi stava gia' sul disco privato per questa
 * ragione (vedi QuoteClientController::storeSignature): qui si allinea.
 *
 * Da ora si scrive solo su "local". Si continua a LEGGERE anche da "public"
 * perche' le firme gia' raccolte stanno ancora li' finche' non gira
 * `php artisan firme:porta-al-privato` (che le sposta e ripulisce il
 * pubblico): fino ad allora un rapportino vecchio deve continuare a
 * mostrare la sua firma.
 */
class FirmaCliente
{
    public const DISCO = 'local';

    /** Il disco su cui questa firma si trova davvero, o null se il file non c'e'. */
    public static function disco(?string $path): ?string
    {
        if (blank($path)) {
            return null;
        }

        foreach ([self::DISCO, 'public'] as $disco) {
            if (Storage::disk($disco)->exists($path)) {
                return $disco;
            }
        }

        return null;
    }

    public static function esiste(?string $path): bool
    {
        return self::disco($path) !== null;
    }

    /**
     * Il percorso sul filesystem, per dompdf: legge il file direttamente,
     * non passa da una URL (e con enable_remote a false non potrebbe).
     */
    public static function percorsoFile(?string $path): ?string
    {
        $disco = self::disco($path);

        return $disco ? Storage::disk($disco)->path($path) : null;
    }

    /** I byte della firma, per chi la deve servire o riscrivere altrove. */
    public static function contenuto(?string $path): ?string
    {
        $disco = self::disco($path);

        return $disco ? Storage::disk($disco)->get($path) : null;
    }
}
