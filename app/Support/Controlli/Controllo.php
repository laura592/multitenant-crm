<?php

namespace App\Support\Controlli;

use Closure;

/**
 * Un controllo sui dati: una domanda a cui si risponde con un numero, e un
 * posto dove andare se quel numero non e' zero.
 *
 * Il conteggio e' una closure e non un valore gia' calcolato: la pagina ne
 * mostra otto, e se li calcolasse tutti in costruzione pagherebbe anche
 * quelli che l'utente non ha il permesso di vedere.
 */
class Controllo
{
    /**
     * @param  Closure(): int  $conta  quante cose non vanno
     * @param  Closure(): ?string  $url  dove si va a sistemarle
     * @param  Closure(): bool  $visibile  il permesso di chi guarda
     */
    public function __construct(
        public readonly string $titolo,
        public readonly string $spiegazione,
        public readonly string $icona,
        private readonly Closure $conta,
        private readonly Closure $url,
        private readonly Closure $visibile,
        /** Cosa vuol dire zero: non "nessun risultato" ma "va tutto bene". */
        public readonly string $quandoZero,
        /** Il testo del bottone quando c'e' da fare. */
        public readonly string $azione = 'Vai a sistemare',
    ) {}

    public function visibile(): bool
    {
        return ($this->visibile)();
    }

    public function quanti(): int
    {
        return ($this->conta)();
    }

    public function url(): ?string
    {
        return ($this->url)();
    }
}
