<?php

namespace App\Support\Gestionale;

use App\Models\Customer;
use App\Models\EurekaFattura;
use App\Models\ServiceReport;
use Illuminate\Support\Carbon;

/**
 * Chi paga una scheda lavoro secondo Eureka (22/09/2026: "se sono nel
 * gestionale deve essere vincolante").
 *
 * La prova e' la FATTURA: a chi e' stata intestata la fattura su cui e'
 * finita la scheda. Verificato sul dump del 21/09/2026: delle 658 schede
 * senza pagante fissato ma gia' fatturate, 628 erano fatturate al cliente
 * stesso e 30 a un altro (ZAF Servizi, Villa Gestioni, Goppion...), e la
 * "destinazione" della scheda era vuota in tutte: non basta a dire chi paga.
 *
 * Prima della fattura vale solo una destinazione esplicita della scheda;
 * senza nessuna delle due Eureka non dice niente, e non si indovina.
 */
class PaganteEureka
{
    /**
     * Il cliente intestatario delle fatture della scheda. Null se non
     * fatturata, se la fattura non e' (ancora) nell'elenco fatture del CRM,
     * o se le fatture della stessa scheda sono intestate a clienti diversi.
     *
     * @param  array<int, array<string, mixed>>|null  $fatture  come ServiceReport::$eureka_fatture
     */
    public static function daFatture(?array $fatture, string $tenantId): ?string
    {
        $esito = static::esitoFatture($fatture, $tenantId);

        return $esito['esito'] === 'trovato' ? $esito['customer_id'] : null;
    }

    /**
     * Come daFatture(), dicendo anche perche' non c'e' un pagante.
     *
     * La fattura della scheda si ritrova nell'elenco fatture per numero e
     * anno: l'elenco non ha l'id della scheda. Le autofatture su acquisti
     * (EurekaFattura::CAUSALI_NON_CLIENTI) si escludono: hanno una
     * numerazione loro e duplicavano i numeri. Se anche cosi' il numero porta
     * a clienti diversi, la fattura e' ambigua e non si decide.
     *
     * @param  array<int, array<string, mixed>>|null  $fatture
     * @return array{esito: 'trovato'|'nessuna'|'ambigua', customer_id: ?string}
     */
    public static function esitoFatture(?array $fatture, string $tenantId): array
    {
        $clienti = collect();

        foreach ($fatture ?? [] as $f) {
            if (! is_array($f) || empty($f['numero_fattura']) || empty($f['data_fattura'])) {
                continue;
            }

            $candidati = EurekaFattura::query()
                ->where('tenant_id', $tenantId)
                ->where('tipo', EurekaFattura::TIPO_CLIENTE)
                // Solo fatture vere ai clienti: le autofatture su acquisti
                // (ZAF, Vodafone...) hanno una numerazione loro e lo stesso
                // numero di una fattura cliente.
                ->where(fn ($q) => $q->whereNull('causale')->orWhereNotIn('causale', EurekaFattura::CAUSALI_NON_CLIENTI))
                ->where('numero_doc', (string) $f['numero_fattura'])
                ->whereYear('data_doc', Carbon::parse($f['data_fattura'])->year)
                ->pluck('customer_id')
                ->unique();

            if ($candidati->count() > 1) {
                return ['esito' => 'ambigua', 'customer_id' => null];
            }

            $clienti = $clienti->merge($candidati->filter());
        }

        $clienti = $clienti->unique();

        return match ($clienti->count()) {
            0 => ['esito' => 'nessuna', 'customer_id' => null],
            1 => ['esito' => 'trovato', 'customer_id' => $clienti->first()],
            default => ['esito' => 'ambigua', 'customer_id' => null],
        };
    }

    /**
     * La destinazione ESPLICITA della scheda (dettaglio /schedelavoro), se
     * diversa dall'intestatario. Vuota = Eureka non la indica: null.
     *
     * @param  array<string, mixed>  $detail
     * @return array{code: int, label: ?string, customer_id: ?string}|null
     */
    public static function destinazione(array $detail, array $summary, string $tenantId): ?array
    {
        $destinazione = (int) ($detail['destinazione']['id_eureka'] ?? 0);
        $intestatario = (int) ($detail['id_intestatario'] ?? $summary['id_codice_f15'] ?? 0);

        if ($destinazione <= 0 || $destinazione === $intestatario) {
            return null;
        }

        return [
            'code' => $destinazione,
            'label' => trim((string) ($detail['destinazione']['rag_sociale'] ?? '')) ?: null,
            'customer_id' => Customer::query()->where('tenant_id', $tenantId)->where('gestionale_code', $destinazione)->value('id'),
        ];
    }

    /**
     * Il pagante vincolante di un rapportino nel gestionale: fattura, poi
     * destinazione esplicita gia' salvata. Null = Eureka non lo dice.
     */
    public static function per(ServiceReport $rapportino): ?string
    {
        return static::daFatture($rapportino->eureka_fatture, $rapportino->tenant_id)
            ?? ($rapportino->eureka_destinazione_code ? $rapportino->eurekaDestinazionePayer()?->id : null);
    }
}
