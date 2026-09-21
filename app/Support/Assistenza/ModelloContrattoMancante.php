<?php

namespace App\Support\Assistenza;

/** In Documenti non c'e' un modello in vigore per quel contratto. */
final class ModelloContrattoMancante extends \RuntimeException
{
    public function __construct(string $nomeContratto)
    {
        parent::__construct("Manca il modello del contratto {$nomeContratto}: caricalo in Magazzino → Documenti, categoria \"Contratto {$nomeContratto}\".");
    }
}
