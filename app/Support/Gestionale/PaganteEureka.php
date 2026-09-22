<?php

namespace App\Support\Gestionale;

use App\Models\Customer;

/**
 * Chi paga una scheda lavoro secondo Eureka (22/09/2026: "se sono nel
 * gestionale deve essere vincolante").
 *
 * La "destinazione" della scheda (doc API §6.1) e' il pagante quando e'
 * diversa dall'intestatario; se manca, o ripete l'intestatario, paga
 * l'intestatario, cioe' il cliente del rapportino. Il pagante impostato
 * oggi su macchina o cliente non c'entra: la scheda e' gia' nel gestionale.
 */
class PaganteEureka
{
    /**
     * @param  array<string, mixed>  $detail  GET /schedelavoro/{id}
     * @param  array<string, mixed>  $summary  riga della lista, se c'e'
     * @return array{code: ?int, label: ?string, customer_id: ?string, trovato: bool}
     *   customer_id nullo con trovato=false: Eureka indica un pagante che nel
     *   CRM non esiste (ancora) come cliente.
     */
    public static function daDettaglio(array $detail, array $summary, string $tenantId, ?string $clienteId): array
    {
        $destinazione = (int) ($detail['destinazione']['id_eureka'] ?? 0);
        $intestatario = (int) ($detail['id_intestatario'] ?? $summary['id_codice_f15'] ?? 0);

        if ($destinazione <= 0 || $destinazione === $intestatario) {
            return ['code' => null, 'label' => null, 'customer_id' => $clienteId, 'trovato' => $clienteId !== null];
        }

        $pagante = Customer::query()
            ->where('tenant_id', $tenantId)
            ->where('gestionale_code', $destinazione)
            ->value('id');

        return [
            'code' => $destinazione,
            'label' => trim((string) ($detail['destinazione']['rag_sociale'] ?? '')) ?: null,
            'customer_id' => $pagante,
            'trovato' => $pagante !== null,
        ];
    }
}
