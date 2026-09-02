<?php

namespace App\Support\Gestionale;

use Illuminate\Support\Facades\Log;

/**
 * Il diario di quello che il CRM scambia con Eureka.
 *
 * Prima di questa classe un import passava senza lasciare traccia: finito il
 * comando restavano solo i numeri a video, e quando l'ufficio chiedeva
 * "questo prezzo da dove viene" o "quando e' sparito quel materiale" non
 * c'era niente da guardare. Ora ogni movimento significativo finisce in
 * storage/logs/gestionale-AAAA-MM-GG.log.
 *
 * Si registra quello che CAMBIA, non quello che viene letto: una riga per
 * ogni creazione, modifica, riprezzatura o unione. Le letture a vuoto (una
 * scheda gia' identica, un prezzo invariato) non lasciano traccia, altrimenti
 * il file diventa illeggibile proprio nei giorni in cui serve.
 *
 * Il formato e' pensato per grep: l'operazione come primo campo, il resto in
 * contesto strutturato.
 */
class RegistroSync
{
    private const CANALE = 'gestionale';

    /** Inizio di un'operazione: serve a delimitare i movimenti che seguono. */
    public static function avvio(string $operazione, array $contesto = []): void
    {
        Log::channel(self::CANALE)->info("{$operazione}: avviato", $contesto);
    }

    /**
     * Un movimento: qualcosa e' cambiato nei dati.
     *
     * @param  string  $operazione  chi lo ha fatto, es. "import-rapportini"
     * @param  string  $cosa  cosa e' successo, es. "rapportino creato"
     */
    public static function movimento(string $operazione, string $cosa, array $dettagli = []): void
    {
        Log::channel(self::CANALE)->info("{$operazione}: {$cosa}", $dettagli);
    }

    /** Fine di un'operazione, con i totali. */
    public static function esito(string $operazione, array $numeri = []): void
    {
        Log::channel(self::CANALE)->info("{$operazione}: concluso", $numeri);
    }

    /**
     * Qualcosa e' andato storto ma l'operazione e' proseguita.
     *
     * Gli import sono best-effort per scelta: un cliente che Eureka non
     * restituisce non deve fermare gli altri duemila. Ma deve restare
     * scritto, altrimenti un buco nei dati sembra un dato.
     */
    public static function problema(string $operazione, string $cosa, array $dettagli = []): void
    {
        Log::channel(self::CANALE)->warning("{$operazione}: {$cosa}", $dettagli);
    }
}
