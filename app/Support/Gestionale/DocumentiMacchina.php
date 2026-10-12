<?php

namespace App\Support\Gestionale;

use App\Models\MachineUnit;
use App\Models\Tenant;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * I documenti di una macchina su Eureka, cercati per matricola.
 *
 * Scoperto il 12/10/2026 (vedi docs/gestionale-eureka/macchinari.md): i DDT
 * dall'API si leggono, ma non dal modulo documenti — /b10/* ammette solo BC,
 * FB e SL, e le consegne delle macchine sono BCC, i rientri BCF. La strada
 * che funziona e' /articoli/cerca-in-righe: Eureka scrive la matricola in una
 * riga descrittiva ("Matricola 3400000282946"), quindi cercandola per testo
 * tornano tutti i documenti di quella singola macchina, di qualunque tipo.
 *
 * Questo rimpiazza /show/q/art_installati, che riporta solo la consegna
 * corrente e quindi non vede i passaggi di mezzo: sulla matricola
 * 3400000282946 dava Marcuzzo dal 15/07/2025, mentre i documenti raccontano
 * sei spostamenti, fra cui un periodo all'Hotel Vidi che nel CRM non c'era.
 *
 * Sola lettura verso Eureka.
 */
final class DocumentiMacchina
{
    /**
     * Le matricole piu' corte di cosi' non si cercano per testo: "13621"
     * comparirebbe dentro qualunque numero o importo di una riga.
     */
    public const MATRICOLA_MINIMA = 4;

    /** Quante righe al massimo Eureka restituisce per matricola. */
    private const LIMITE_RIGHE = 500;

    /**
     * Movimenti per matricola (chiave normalizzata da MachineUnit::chiaveMatricola).
     *
     * @param  Collection<int, string>  $matricole
     * @return array{movimenti: array<string, array<int, array{data: Carbon, tipo: string, numero: int, id_doc: int, codice_cliente: int, ragione_sociale: string}>>, falliti: array<int, string>}
     */
    public static function perMatricole(Tenant $tenant, Collection $matricole, int $concorrenza = 10): array
    {
        $client = new EurekaClient($tenant);

        $cercabili = $matricole
            ->map(fn (string $m) => trim($m))
            ->filter(fn (string $m) => self::cercabile($m))
            ->unique()
            ->values();

        $parametri = $cercabili
            ->mapWithKeys(fn (string $m) => [$m => ['testo' => $m, 'limit' => self::LIMITE_RIGHE]])
            ->all();

        $risposte = $client->pooledGet('/articoli/cerca-in-righe', $parametri, $concorrenza);
        $falliti = $client->chiaviPooledFallite();

        // Eureka ha i suoi 500 a raffica (vedi eureka-api-intermittent-500s):
        // le chiamate cadute si richiedono, le altre no.
        for ($tentativo = 0; $tentativo < 2 && $falliti !== []; $tentativo++) {
            usleep(2_000_000);

            $ripetute = $client->pooledGet(
                '/articoli/cerca-in-righe',
                array_intersect_key($parametri, array_flip($falliti)),
                $concorrenza,
            );
            $ancora = $client->chiaviPooledFallite();

            foreach ($ripetute as $chiave => $valore) {
                if (! in_array($chiave, $ancora, true)) {
                    $risposte[$chiave] = $valore;
                }
            }

            $falliti = $ancora;
        }

        $movimenti = [];

        foreach ($risposte as $matricola => $righe) {
            $chiave = MachineUnit::chiaveMatricola((string) $matricola);

            foreach ((array) $righe as $riga) {
                if (! is_array($riga) || ! self::nomina((string) $matricola, (string) ($riga['descrizione_riga'] ?? ''))) {
                    continue;
                }

                $data = self::data($riga['data'] ?? null);

                if (! $data) {
                    continue;
                }

                $movimenti[$chiave][] = [
                    'data' => $data,
                    'tipo' => strtoupper(trim((string) ($riga['tipo_doc'] ?? ''))),
                    'numero' => (int) ($riga['numero'] ?? 0),
                    'id_doc' => (int) ($riga['id_doc'] ?? 0),
                    'codice_cliente' => (int) ($riga['id_f15'] ?? 0),
                    'ragione_sociale' => trim((string) ($riga['rag_sociale'] ?? '')),
                ];
            }
        }

        foreach ($movimenti as $chiave => $righe) {
            usort($righe, fn (array $a, array $b) => $a['data'] <=> $b['data']);
            $movimenti[$chiave] = $righe;
        }

        return ['movimenti' => $movimenti, 'falliti' => $falliti];
    }

    /**
     * La destinazione scritta sul documento, se il documento si puo' leggere
     * (BC, FB, SL: gli altri tipi danno 401). E' la riga che conta quando chi
     * paga non e' chi riceve: la bolla del 09/10/2026 e' intestata a Dersut
     * ma la macchina e' andata all'Hotel Neps.
     *
     * Prima riga del blocco indirizzo, che e' la ragione sociale.
     */
    public static function destinazione(EurekaClient $client, int $idDoc): ?string
    {
        $documento = $client->documentoB10($idDoc);

        if ($documento === null) {
            return null;
        }

        $righe = preg_split('/\r\n|\r|\n/', (string) ($documento['str_destinazione'] ?? ''));
        $prima = trim((string) ($righe[0] ?? ''));

        return $prima !== '' ? $prima : null;
    }

    /** Una matricola inventata dal CRM (IMP-SPINA-..., IMP-ACQUA-...) su Eureka non esiste. */
    public static function cercabile(string $matricola): bool
    {
        $matricola = trim($matricola);

        return mb_strlen($matricola) >= self::MATRICOLA_MINIMA
            && ! str_starts_with(mb_strtoupper($matricola), 'IMP-')
            && ! preg_match('/^0+$/', $matricola);
    }

    /**
     * La riga parla proprio di questa matricola e non di un numero che la
     * contiene: confini di cifra attorno, cosi' "13621" non si prende
     * "136215" ne' un importo.
     */
    private static function nomina(string $matricola, string $descrizione): bool
    {
        if ($descrizione === '') {
            return false;
        }

        return (bool) preg_match('/(?<![0-9A-Za-z])'.preg_quote(trim($matricola), '/').'(?![0-9A-Za-z])/i', $descrizione);
    }

    private static function data(mixed $valore): ?Carbon
    {
        if (! is_string($valore) || trim($valore) === '') {
            return null;
        }

        return rescue(fn () => Carbon::parse($valore)->startOfDay(), null, false);
    }
}
