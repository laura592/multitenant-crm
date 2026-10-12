<?php

namespace App\Support\Gestionale;

use App\Models\Customer;
use Illuminate\Support\Collection;

/**
 * Se due clienti sono lo stesso posto, detto in due modi.
 *
 * Confrontare due id e dire "diversi" produce quasi solo falsi allarmi: il
 * primo giro del confronto fra CRM e documenti segnalava 848 macchine "nel
 * posto sbagliato", e quasi tutte erano una fattura intestata al pagante
 * (Dersut compra la macchina, il cliente la usa) o due schede della stessa
 * azienda (906 "Hotel Neps" e 907 "Hotel-Ristorante Neps"). Con queste tre
 * regole sono scese a 14 (12/10/2026).
 */
final class StessoPosto
{
    /**
     * @param  Collection<string, ?string>  $paganti  id cliente => id di chi paga per lui
     * @param  Collection<string, string>  $nomi  id cliente => ragione sociale normalizzata
     */
    private function __construct(
        private readonly Collection $paganti,
        private readonly Collection $nomi,
    ) {}

    /**
     * @param  Collection<int, Customer>  $clienti  con id, company_name, billing_customer_id
     */
    public static function per(Collection $clienti): self
    {
        return new self(
            $clienti->pluck('billing_customer_id', 'id'),
            $clienti->mapWithKeys(fn (Customer $c) => [$c->id => self::normalizza($c->company_name)]),
        );
    }

    /**
     * @param  ?string  $pagante  chi paga per la macchina, se diverso da chi la ospita
     */
    public function uguali(?string $uno, ?string $altro, ?string $pagante = null): bool
    {
        if ($uno === $altro) {
            return true;
        }

        // Il magazzino (null) contro un cliente e' una differenza vera.
        if ($uno === null || $altro === null) {
            return false;
        }

        if ($this->paganti->get($uno) === $altro || $this->paganti->get($altro) === $uno) {
            return true;
        }

        if ($pagante !== null && in_array($pagante, [$uno, $altro], true)) {
            return true;
        }

        $nomeUno = $this->nomi->get($uno);

        return $nomeUno !== null && $nomeUno !== '' && $nomeUno === $this->nomi->get($altro);
    }

    /** Ragioni sociali confrontabili: niente forma societaria, niente punteggiatura. */
    public static function normalizza(?string $nome): string
    {
        $nome = mb_strtolower(trim((string) $nome));
        $nome = preg_replace('/\b(s\.?r\.?l\.?s?|s\.?p\.?a\.?|s\.?a\.?s\.?|s\.?n\.?c\.?|societa\'?|unipersonale|di|&|c\.)\b/u', ' ', $nome);
        $nome = preg_replace('/[^a-z0-9]+/u', ' ', (string) $nome);

        return trim(preg_replace('/\s+/', ' ', (string) $nome));
    }
}
