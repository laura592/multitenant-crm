<?php

namespace Tests\Feature;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Str;
use Symfony\Component\Console\Input\StringInput;
use Tests\TestCase;

/**
 * Un comando schedulato sbagliato non fallisce a scriverlo, fallisce alle
 * 04:00 in produzione: l'import notturno dei rapportini e' rimasto rotto una
 * settimana perche' un flag booleano era passato come "'--with-detail' => true"
 * e Laravel lo rendeva "--with-detail='1'", che Symfony rifiuta prima ancora di
 * eseguire il comando. Qui ogni riga dello scheduler viene ri-bindata alla
 * definizione del suo comando, cioe' esattamente il passo che esplodeva.
 */
class ScheduledCommandsTest extends TestCase
{
    public function test_every_scheduled_command_can_be_parsed_by_its_own_definition(): void
    {
        $events = app(Schedule::class)->events();

        $this->assertNotEmpty($events, 'Nessun comando schedulato: il test non starebbe verificando niente.');

        $checked = 0;

        foreach ($events as $event) {
            $command = (string) $event->command;

            // Gli eventi non-artisan (exec di shell) non hanno una definizione
            // da controllare.
            if (! Str::contains($command, "'artisan' ")) {
                continue;
            }

            $invocation = trim(Str::after($command, "'artisan' "));
            $name = Str::before($invocation, ' ');
            $arguments = trim(Str::after($invocation, $name));

            $resolved = Artisan::all()[$name] ?? null;
            $this->assertNotNull($resolved, "Comando schedulato inesistente: {$name}");

            try {
                (new StringInput($arguments))->bind($resolved->getDefinition());
            } catch (\Throwable $e) {
                $this->fail("Il comando schedulato \"{$invocation}\" non e' valido: {$e->getMessage()}");
            }

            $checked++;
        }

        $this->assertGreaterThan(0, $checked, 'Nessun comando artisan schedulato riconosciuto.');
    }
}
