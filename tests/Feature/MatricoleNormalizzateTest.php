<?php

namespace Tests\Feature;

use App\Models\MachineUnit;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Le matricole vanno confrontate senza la punteggiatura con cui Eureka le
 * scrive: nell'import degli installati del 02/09/2026 lo stesso apparecchio
 * e' entrato due volte perche' un cliente lo aveva come
 * "BRL 003 020002113218" e un altro come "BRL003020002113218".
 */
class MatricoleNormalizzateTest extends TestCase
{
    #[DataProvider('matricoleEquivalenti')]
    public function test_forme_diverse_della_stessa_matricola_coincidono(string $a, string $b): void
    {
        $this->assertSame(
            MachineUnit::chiaveMatricola($a),
            MachineUnit::chiaveMatricola($b),
            "«{$a}» e «{$b}» dovrebbero essere la stessa macchina",
        );
    }

    public static function matricoleEquivalenti(): array
    {
        return [
            'spazi interni' => ['BRL 003 020002113218', 'BRL003020002113218'],
            'trattino in testa' => ['-0819352', '0819352'],
            'trattini contro spazi' => ['50025302-IHG035077', '50025302 IHG 035077'],
            'maiuscole' => ['ahd245035', 'AHD245035'],
            'spazi ai bordi' => ['  285017 ', '285017'],
        ];
    }

    public function test_matricole_diverse_restano_diverse(): void
    {
        $this->assertNotSame(
            MachineUnit::chiaveMatricola('285017'),
            MachineUnit::chiaveMatricola('285018'),
        );
    }

    public function test_la_matricola_vuota_resta_vuota(): void
    {
        $this->assertSame('', MachineUnit::chiaveMatricola(null));
        $this->assertSame('', MachineUnit::chiaveMatricola('   '));
        $this->assertSame('', MachineUnit::chiaveMatricola('- - -'));
    }
}
