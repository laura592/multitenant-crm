<?php

namespace App\Support;

use App\Models\Customer;
use App\Models\MachineUnit;
use App\Models\Material;

/**
 * Sceglie i codici tariffa di un rapportino a partire dal pagante.
 *
 * Le scorciatoie del rapportino inserivano codici fissi (CHIORD/CHIVE per
 * citta', ORE, LAV2, ULTVIA): chi compila doveva ricordarsi di sostituirli a
 * mano quando il cliente e' fatturato a un pagante con listino proprio, e nei
 * fatti non succedeva quasi mai. La tabella sta in config/tariffe.php.
 */
class TariffeIntervento
{
    /**
     * @return array{chiamata: ?string, manodopera: ?string, lavaggio: ?string, lavaggio_ulteriore_via: ?string, sanificazione: ?string, manutenzione_suffisso: ?string, pagante: ?string}
     */
    public static function per(?Customer $cliente, bool $festivo = false): array
    {
        $standard = config('tariffe.standard');
        $pagante = $cliente?->billingCustomer;
        $listino = self::listino($pagante) ?: null;

        // Sul pagante il codice e' lo stesso ovunque si vada: e' il listino
        // concordato con lui, non la difficolta' del viaggio.
        $chiamata = $listino
            ? self::voce($listino, 'chiamata', $festivo)
            : ($festivo
                ? $standard['chiamata_festiva']
                : ($cliente?->city === 'Venezia' ? $standard['chiamata_venezia'] : $standard['chiamata']));

        return [
            'chiamata' => $chiamata,
            'manodopera' => $listino
                ? self::voce($listino, 'manodopera', $festivo)
                : ($festivo ? $standard['manodopera_festiva'] : $standard['manodopera']),
            'lavaggio' => $listino['lavaggio'] ?? $standard['lavaggio'],
            'lavaggio_ulteriore_via' => $listino['lavaggio_ulteriore_via'] ?? $standard['lavaggio_ulteriore_via'],
            'sanificazione' => $listino['sanificazione'] ?? $standard['sanificazione'],
            'manutenzione_suffisso' => $listino['manutenzione_suffisso'] ?? null,
            'pagante' => $listino['nome'] ?? null,
        ];
    }

    /**
     * Il codice della manutenzione ordinaria dovuta su questa macchina.
     *
     * Non e' una tariffa fissa come chiamata o manodopera: dipende dal
     * MODELLO (Faema 3 gruppi -> F3, Cimbali 2 -> C2, Dalla Corte A/2 ->
     * DC2), e il modello lo dichiara in Material::maintenance_code.
     *
     * Sul pagante con listino proprio si prova prima la sua variante, che a
     * catalogo esiste come suffisso: F3 + GOPPION = F3GOPPION. Se quella
     * variante NON esiste (F4GOPPION non c'e', mentre F4HTS si') si ricade
     * sul codice base: meglio la tariffa piena che una riga con un codice
     * inventato, che su Eureka non si aggancerebbe a niente.
     */
    public static function manutenzione(?MachineUnit $macchina, ?Customer $cliente): ?string
    {
        // Prima la macchina, poi il suo modello: il codice normale vive sul
        // modello a catalogo (si compila una volta per modello invece che su
        // 774 macchine), ma la singola macchina puo' fare eccezione e allora
        // comanda lei.
        $base = $macchina?->maintenance_code ?: $macchina?->material?->maintenance_code;

        if (blank($base)) {
            return null;
        }

        // Chi paga davvero, con la stessa precedenza usata ovunque nell'app
        // (vedi Lavaggio::invoiceRecipient): una macchina puo' avere un
        // pagante suo — Goppion sulla singola macchina di un bar che per il
        // resto paga da se' — e in quel caso vince sul pagante del cliente.
        $pagante = $macchina?->billingCustomer ?? $cliente?->billingCustomer;
        $suffisso = self::listino($pagante)['manutenzione_suffisso'] ?? null;

        if (filled($suffisso) && Material::where('code', $base.$suffisso)->exists()) {
            return $base.$suffisso;
        }

        return $base;
    }

    /** Ore di manodopera su una disinstallazione, per chi e' la macchina. */
    public const ORE_DISINSTALLAZIONE_TORREFATTORE = 1.0;

    public const ORE_DISINSTALLAZIONE = 0.5;

    /**
     * Il codice della disinstallazione: con o senza ritiro.
     *
     * Sono due articoli diversi allo stesso prezzo (79,50) e la differenza e'
     * sostanziale: DISIN/RITIRO dice che la macchina e' tornata in magazzino
     * (e da quella riga il sync propone il rientro, vedi
     * Support\Gestionale\RientriMagazzino), DISIN che e' rimasta dal cliente
     * staccata. Nessun pagante ha finora un codice proprio, ma la voce passa
     * dal listino come le altre cosi' che aggiungerlo sia una riga.
     */
    public static function disinstallazione(?Customer $cliente, bool $conRitiro = true): ?string
    {
        $chiave = $conRitiro ? 'disinstallazione_ritiro' : 'disinstallazione';
        $listino = self::listino($cliente?->billingCustomer);

        return $listino[$chiave] ?? config('tariffe.standard')[$chiave] ?? null;
    }

    /**
     * Quanta manodopera va in riga su una disinstallazione.
     *
     * Decide di chi e' la macchina, non quanto e' grossa: quella del
     * torrefattore si stacca, si imballa e si carica per il rientro (un'ora);
     * quella del cliente resta dov'e' (mezz'ora). L'elenco dei torrefattori
     * sta in config/tariffe.php.
     *
     * Resta un valore di partenza: il tecnico che ci ha messo di piu' lo
     * corregge in riga.
     */
    public static function oreDisinstallazione(?MachineUnit $macchina, ?Customer $cliente): float
    {
        return self::diUnTorrefattore($macchina, $cliente)
            ? self::ORE_DISINSTALLAZIONE_TORREFATTORE
            : self::ORE_DISINSTALLAZIONE;
    }

    /**
     * Il torrefattore proprietario della macchina, se c'e'.
     *
     * Stessa precedenza di manutenzione(): la singola macchina puo' avere un
     * pagante suo (una Goppion in un bar che per il resto paga da se'), e in
     * quel caso comanda lei.
     */
    public static function torrefattoreProprietario(?MachineUnit $macchina, ?Customer $cliente): ?Customer
    {
        $pagante = $macchina?->billingCustomer;

        if (! $pagante) {
            $pagante = $cliente?->billingCustomer;
        }

        if (! $pagante instanceof Customer || ! $pagante->gestionale_code) {
            return null;
        }

        $torrefattori = array_map('intval', config('tariffe.torrefattori', []));

        return in_array((int) $pagante->gestionale_code, $torrefattori, true) ? $pagante : null;
    }

    private static function diUnTorrefattore(?MachineUnit $macchina, ?Customer $cliente): bool
    {
        return self::torrefattoreProprietario($macchina, $cliente) !== null;
    }

    /**
     * Il listino configurato per un pagante, o null se non ne ha uno.
     *
     * @return array<string, mixed>
     */
    private static function listino(?Customer $pagante): array
    {
        return $pagante?->gestionale_code
            ? (config('tariffe.paganti')[(int) $pagante->gestionale_code] ?? [])
            : [];
    }

    /**
     * La voce festiva quando esiste; altrimenti quella ordinaria del pagante,
     * che e' comunque piu' giusta del codice standard.
     */
    private static function voce(array $listino, string $chiave, bool $festivo): ?string
    {
        if ($festivo && filled($listino[$chiave.'_festiva'] ?? null)) {
            return $listino[$chiave.'_festiva'];
        }

        return $listino[$chiave] ?? null;
    }
}
