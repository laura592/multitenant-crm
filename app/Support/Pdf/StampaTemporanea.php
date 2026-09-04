<?php

namespace App\Support\Pdf;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * Il ponte fra un PDF generato dentro un'azione Livewire e una scheda nuova
 * del browser.
 *
 * Il problema: un'azione Filament che ritorna una response fa partire un
 * download, sempre — Livewire la consegna come file, e "inline" nell'header
 * non cambia niente. Per aprire il PDF in una scheda serve un URL, ma questi
 * PDF non ce l'hanno: dipendono da cosa la pagina ha davanti in quel momento
 * (i filtri e la ricerca della tabella, il mese scelto, le date del form).
 * Metterli in querystring vorrebbe dire serializzare lo stato di ogni
 * tabella, e riprodurlo poi identico nel controller.
 *
 * Qui invece il PDF si genera dov'e' sempre stato generato, con lo stato che
 * ha sotto mano, e si parcheggia dieci minuti in cache dietro una chiave
 * casuale. L'azione apre quella chiave in una scheda nuova. Nessun file su
 * disco da ripulire: la cache scade da sola.
 *
 * Il contenuto viaggia in base64 perche' la cache sta su MySQL (CACHE_STORE
 * =database) e i byte grezzi di un PDF non sopravvivono a una colonna
 * utf8mb4.
 */
class StampaTemporanea
{
    private const TTL_MINUTI = 10;

    /**
     * Parcheggia il PDF e torna l'URL da aprire.
     */
    public static function parcheggia(string $contenuto, string $nomeFile): string
    {
        $chiave = (string) Str::uuid();

        Cache::put(self::cacheKey($chiave), [
            // Chi l'ha generata: la chiave e' irrindovinabile, ma un PDF di
            // scaduto clienti non deve poter essere letto da un altro utente
            // nemmeno per sbaglio (link incollato, cronologia condivisa).
            'user_id' => auth()->id(),
            'nome' => $nomeFile,
            'contenuto' => base64_encode($contenuto),
        ], now()->addMinutes(self::TTL_MINUTI));

        return route('stampe.temporanea', $chiave);
    }

    /**
     * @return array{nome: string, contenuto: string}|null
     */
    public static function ritira(string $chiave): ?array
    {
        $dati = Cache::get(self::cacheKey($chiave));

        if (! is_array($dati) || $dati['user_id'] !== auth()->id()) {
            return null;
        }

        return [
            'nome' => $dati['nome'],
            'contenuto' => base64_decode($dati['contenuto']),
        ];
    }

    private static function cacheKey(string $chiave): string
    {
        return 'stampa-temporanea:'.$chiave;
    }
}
