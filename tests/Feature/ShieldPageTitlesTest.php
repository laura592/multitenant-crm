<?php

namespace Tests\Feature;

use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Shield, per costruire la matrice dei permessi della pagina Ruoli,
 * istanzia OGNI Page del pannello e ne chiama getTitle() — senza montarla e
 * senza tenant attivo (vedi FilamentShield::getLocalizedPageLabel()).
 *
 * Una Page che nel titolo legge una proprieta' valorizzata solo da mount()
 * solleva quindi un Error e manda in 500 l'intera pagina Ruoli, non solo se
 * stessa. E' successo in produzione il 2026-09-02 con DettaglioScaduto, che
 * riceve il codice cliente dall'URL: nessun test lo copriva perche' le
 * pagine si aprivano sempre montate.
 */
class ShieldPageTitlesTest extends TestCase
{
    use RefreshDatabase;

    public function test_ogni_pagina_sa_dire_il_proprio_titolo_senza_essere_montata(): void
    {
        $pagine = Filament::getPanel('admin')->getPages();

        $this->assertNotEmpty($pagine, 'nessuna pagina registrata: il test non starebbe verificando niente');

        foreach ($pagine as $pagina) {
            $titolo = (new $pagina)->getTitle();

            $this->assertNotSame(
                '',
                trim((string) $titolo),
                "{$pagina} non restituisce un titolo utilizzabile senza mount()",
            );
        }
    }
}
