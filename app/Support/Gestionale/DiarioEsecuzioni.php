<?php

namespace App\Support\Gestionale;

use App\Models\EsecuzioneEureka;
use Illuminate\Console\Events\CommandFinished;
use Illuminate\Console\Events\CommandStarting;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Quando e' girato ogni lavoro con Eureka, com'e' finito e cosa ha trovato
 * (22/09/2026). Si legge in cima alla pagina di revisione del sync.
 *
 * Si aggancia agli eventi dei comandi Artisan, quindi vale per i lavori
 * notturni, per quelli lanciati a mano sul server e per i pulsanti del
 * pannello (che passano da Artisan::call()). I totali sono quelli che il
 * comando scrive con RegistroSync::esito().
 */
final class DiarioEsecuzioni
{
    /**
     * I lavori seguiti: etichetta, quando girano, e dopo quante ore senza un
     * giro riuscito sono da considerare fermi.
     *
     * @var array<string, array{0: string, 1: string, 2: int}>
     */
    public const LAVORI = [
        'gestionale:sync' => ['Clienti, macchine e spostamenti', 'ogni notte alle 03:00', 26],
        'eureka:apply-machine-billing-payer' => ['Chi paga le macchine', 'ogni notte alle 03:15', 26],
        'eureka:import-service-reports' => ['Rapportini', 'ogni notte alle 04:00', 26],
        'eureka:refresh-material-prices' => ['Prezzi dei materiali', 'ogni notte alle 05:00', 26],
        'eureka:import-partite-aperte' => ['Partite aperte', 'ogni notte alle 05:30', 26],
        'eureka:import-fatture' => ['Fatture', 'ogni notte alle 05:45', 26],
        'eureka:import-kpi-contabili' => ['Fatturato e cash flow', 'ogni notte alle 06:15', 26],
        'eureka:allinea-fatture-rapportini' => ['Fatture dei rapportini', 'ogni notte alle 06:45', 26],
        'eureka:sweep-materials-catalog' => ['Catalogo materiali', 'ogni lunedì alle 06:00', 8 * 24],
    ];

    /** @var array<string, array<int, int>> comando => id delle righe aperte (i comandi possono annidarsi) */
    private static array $aperte = [];

    /** @var array<string, array<string, mixed>> */
    private static array $esiti = [];

    private static ?string $ultimoErrore = null;

    /** Il giro appena chiuso con errore ma senza messaggio: l'eccezione si scrive nel log dopo. */
    private static ?int $senzaMessaggio = null;

    public static function registra(): void
    {
        Event::listen(CommandStarting::class, function (CommandStarting $e) {
            if (isset(self::LAVORI[$e->command]) && self::tabellaPronta()) {
                self::$aperte[$e->command][] = EsecuzioneEureka::create([
                    'comando' => $e->command,
                    'avviata_il' => now(),
                    'esito' => EsecuzioneEureka::IN_CORSO,
                ])->id;
            }
        });

        Event::listen(CommandFinished::class, function (CommandFinished $e) {
            if (isset(self::LAVORI[$e->command]) && ! empty(self::$aperte[$e->command])) {
                self::chiudi(array_pop(self::$aperte[$e->command]), $e->exitCode === 0);
            }
        });

        Event::listen(MessageLogged::class, function (MessageLogged $e) {
            if (! in_array($e->level, ['error', 'critical', 'alert', 'emergency'], true)) {
                return;
            }

            $messaggio = Str::of($e->message)->before("\n")->limit(500)->toString();

            if (self::ceNeSonoAperti()) {
                self::$ultimoErrore = $messaggio;
            } elseif (self::$senzaMessaggio !== null) {
                // Un'eccezione che ha fermato il comando: Laravel la scrive nel
                // log solo dopo la fine (es. 'Class "...ConfrontoMacchine" not
                // found', 21/09/2026). E' lei il perche'.
                EsecuzioneEureka::whereKey(self::$senzaMessaggio)->update(['errore' => $messaggio]);
                self::$senzaMessaggio = null;
            }
        });

        // Un comando che si ferma con un'eccezione non arriva a
        // CommandFinished: la riga si chiude qui, con l'errore.
        app()->terminating(function () {
            foreach (self::$aperte as $comando => $ids) {
                foreach ($ids as $id) {
                    self::chiudi($id, false);
                }
            }
            self::$aperte = [];
        });
    }

    /** Chiamato da RegistroSync::esito(): i totali finiscono sul giro in corso. */
    public static function annotaEsito(string $operazione, array $numeri): void
    {
        if (self::ceNeSonoAperti()) {
            self::$esiti[$operazione] = $numeri;
        }
    }

    /**
     * Lo stato di ogni lavoro per la striscia in cima alla revisione del sync:
     * l'ultimo giro, se e' fermo da troppo, e i totali in parole.
     *
     * @return array<int, array{etichetta: string, quando: string, ultimo: ?EsecuzioneEureka, stato: string, riepilogo: ?string}>
     */
    public static function stato(): array
    {
        return collect(self::LAVORI)->map(function (array $lavoro, string $comando) {
            [$etichetta, $quando, $oreMax] = $lavoro;
            $ultimo = EsecuzioneEureka::where('comando', $comando)->latest('avviata_il')->latest('id')->first();
            $ultimoOk = EsecuzioneEureka::where('comando', $comando)->where('esito', EsecuzioneEureka::OK)->max('finita_il');

            $stato = match (true) {
                $ultimo === null => 'mai',
                $ultimo->esito === EsecuzioneEureka::IN_CORSO && $ultimo->avviata_il->lt(now()->subHours(3)) => 'errore',
                $ultimo->esito === EsecuzioneEureka::IN_CORSO => 'in_corso',
                $ultimo->esito === EsecuzioneEureka::ERRORE => 'errore',
                $ultimoOk === null || Carbon::parse($ultimoOk)->lt(now()->subHours($oreMax)) => 'fermo',
                default => 'ok',
            };

            return [
                'etichetta' => $etichetta,
                'quando' => $quando,
                'ultimo' => $ultimo,
                'stato' => $stato,
                'riepilogo' => self::inParole($ultimo?->riepilogo),
            ];
        })->values()->all();
    }

    /** {"import-rapportini": {"creati": 3, "aggiornati": 10}} -> "creati 3 · aggiornati 10" */
    private static function inParole(?array $riepilogo): ?string
    {
        if (! $riepilogo) {
            return null;
        }

        return collect($riepilogo)->flatMap(fn ($numeri) => collect((array) $numeri)
            ->reject(fn ($v) => is_array($v) || $v === null || $v === '' || in_array($v, ['alex'], true))
            ->map(fn ($v, $k) => str_replace('_', ' ', (string) $k).' '.(is_bool($v) ? ($v ? 'sì' : 'no') : $v)))
            ->implode(' · ') ?: null;
    }

    private static function chiudi(int $id, bool $riuscito): void
    {
        EsecuzioneEureka::whereKey($id)->update([
            'finita_il' => now(),
            'esito' => $riuscito ? EsecuzioneEureka::OK : EsecuzioneEureka::ERRORE,
            'riepilogo' => self::$esiti === [] ? null : json_encode(self::$esiti),
            'errore' => $riuscito ? null : (self::$ultimoErrore ?? 'terminato con errore'),
        ]);

        self::$senzaMessaggio = ! $riuscito && self::$ultimoErrore === null ? $id : null;

        if (! self::ceNeSonoAperti()) {
            self::$esiti = [];
            self::$ultimoErrore = null;
        }
    }

    private static function ceNeSonoAperti(): bool
    {
        return collect(self::$aperte)->flatten()->isNotEmpty();
    }

    /** Prima della migrazione (il primo update.sh) i comandi non devono rompersi. */
    private static function tabellaPronta(): bool
    {
        return Schema::hasTable('esecuzioni_eureka');
    }
}
