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
     * @param  Closure(): int|null  $righe  quante righe si aprono, se non sono quante()
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
        /**
         * Per i controlli che raggruppano, il numero sulla scheda e il
         * numero di righe che si aprono NON sono lo stesso: cinque gruppi
         * di anagrafiche doppie sono tredici anagrafiche da guardare. Si
         * dicono entrambi, perche' chi clicca e ne trova tredici dove ne
         * aveva lette cinque smette di fidarsi della pagina. Null dove la
         * domanda non si pone: una riga, un caso.
         */
        private readonly ?Closure $righe = null,
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

    /** Quante righe atterrano cliccando, quando non sono quante(). */
    public function righe(): ?int
    {
        return $this->righe === null ? null : ($this->righe)();
    }
}
