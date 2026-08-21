<?php

namespace App\Support;

use Livewire\Mechanisms\ExtendBlade\ExtendBlade;
use ReflectionProperty;

/**
 * Genera PDF/email da dentro un'azione Filament (bottone "Invia", download
 * rapportino ecc.) e' un rendering Blade che parte MENTRE Livewire sta gia'
 * gestendo la richiesta della pagina che ha lanciato l'azione: lo stack
 * interno di Livewire (ExtendBlade::$livewireComponents) risulta quindi
 * ancora non vuoto, e ExtendBlade::isRenderingLivewireComponent() torna
 * true anche per questo rendering "esterno" (PDF/mail), che non ha nulla a
 * che fare col DOM diffing di Livewire. Il compilatore Blade avvolge allora
 * ogni @if/@endif in commenti HTML <!--[if BLOCK]><![endif]--> usati da
 * Livewire per tracciare i confini dei blocchi condizionali: innocui in un
 * HTML vero (un commento non si vede), ma il convertitore automatico
 * HTML->testo di Laravel Mail (Markdown mailable, generazione della parte
 * text/plain) non li riconosce come commenti e li lascia visibili come
 * testo grezzo nell'email — bug segnalato 2026-08-20 su un rapportino
 * inviato via email, "Alex Partner Hub / ... <!--[if BLOCK]><![endif]-->"
 * comparso letteralmente nel corpo. Qui si svuota temporaneamente lo stack
 * (via reflection: e' una proprieta' protetta interna di Livewire, non
 * un'API pubblica) cosi' il rendering PDF/email risulta correttamente "non
 * dentro un componente Livewire", poi lo si ripristina.
 */
class OutsideLivewireRender
{
    public static function run(\Closure $callback): mixed
    {
        $property = new ReflectionProperty(ExtendBlade::class, 'livewireComponents');
        $property->setAccessible(true);

        $previous = $property->getValue();
        $property->setValue(null, []);

        try {
            return $callback();
        } finally {
            $property->setValue(null, $previous);
        }
    }
}
