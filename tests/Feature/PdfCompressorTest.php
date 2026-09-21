<?php

namespace Tests\Feature;

use App\Support\PdfCompressor;
use Illuminate\Support\Facades\Process;
use Symfony\Component\Process\Exception\LogicException;
use Tests\TestCase;

/**
 * Sull'hosting di produzione proc_open e' disabilitato (21/09/2026): la
 * compressione si salta, non manda in errore il salvataggio del documento.
 */
class PdfCompressorTest extends TestCase
{
    public function test_senza_proc_open_la_compressione_si_salta(): void
    {
        Process::fake(fn () => throw new LogicException('The Process class relies on proc_open, which is not available on your PHP installation.'));

        $this->assertFalse(PdfCompressor::isAvailable());
        $this->assertFalse(PdfCompressor::compressInPlace(__FILE__));
    }
}
