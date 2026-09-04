<?php

namespace App\Support\Pdf;

use App\Models\Customer;
use App\Models\MachineUnit;
use App\Support\DisplayName;
use Illuminate\Support\Collection;

/**
 * Traduce un cliente del CRM nei campi della scheda anagrafica compilabile
 * (vedi SchedaAnagraficaPdf per il modulo vero e proprio).
 *
 * La scheda torna indietro FIRMATA dal cliente: e' una dichiarazione sua, non
 * nostra. Per questo si precompila solo cio' che sappiamo per certo e che lui
 * deve limitarsi a confermare o correggere — anagrafica, sedi, matricole — e
 * si lascia in bianco tutto il resto. In particolare NON si precompilano mai:
 *
 * - i consensi privacy e marketing, che devono essere una scelta sua (una
 *   casella gia' spuntata da noi non e' un consenso valido);
 * - data e firma;
 * - condizioni di pagamento, IBAN e banca, che nel CRM non esistono a livello
 *   di anagrafica (payment_method sta sul singolo preventivo) e indovinarle
 *   dall'ultimo preventivo accettato significherebbe far firmare al cliente
 *   una condizione che non gli abbiamo mai proposto.
 */
class SchedaAnagraficaData
{
    /** Blocchi "sede operativa" stampati sul modulo. */
    public const MAX_SEDI = 5;

    /** Righe della tabella macchine. */
    public const MAX_MACCHINE = 10;

    /**
     * @return array<string, string> nome campo del modulo => valore
     */
    public static function for(Customer $customer): array
    {
        $valori = self::intestatario($customer);

        // Le sedi operative sono le anagrafiche che questo cliente paga: e' il
        // caso del gestore con piu' chioschi. Se non ne paga nessuna, l'unica
        // sede operativa e' lui stesso.
        $sedi = $customer->paidCustomers()
            ->orderBy('company_name')
            ->limit(self::MAX_SEDI)
            ->get();

        if ($sedi->isEmpty()) {
            $sedi = collect([$customer]);
        }

        foreach ($sedi as $i => $sede) {
            $valori += self::sede($sede, $i + 1, $customer);
        }

        $valori += self::macchine($sedi);

        return array_filter($valori, fn (string $v) => $v !== '');
    }

    /**
     * Quante sedi e quante matricole risultano DAVVERO collegate, anche oltre
     * quelle che stanno sul modulo: servono a SchedaAnagraficaPdf per
     * dichiararlo in chiaro invece di stampare i primi cinque chioschi di
     * trentadue e lasciar credere che siano tutti li'.
     *
     * @return array{sedi: int, macchine: int}
     */
    public static function conteggi(Customer $customer): array
    {
        $sedi = $customer->paidCustomers()->pluck('id');

        if ($sedi->isEmpty()) {
            $sedi = collect([$customer->id]);
        }

        return [
            'sedi' => $sedi->count(),
            'macchine' => MachineUnit::whereIn('current_customer_id', $sedi)->count(),
        ];
    }

    /**
     * @return array<string, string>
     */
    private static function intestatario(Customer $customer): array
    {
        $valori = [
            'fatt_ragione_sociale' => self::nome($customer),
            'fatt_via' => (string) $customer->street,
            'fatt_cap' => (string) $customer->postal_code,
            'fatt_citta' => DisplayName::titleCase($customer->city) ?? '',
            'fatt_prov' => (string) $customer->province,
            'fatt_piva' => (string) $customer->vat_number,
            'fatt_cf' => (string) $customer->tax_code,
            'fatt_sdi' => (string) $customer->sdi,
            'fatt_pec' => (string) $customer->pec,
            'fatt_tel' => (string) $customer->primaryPhone(),
            'fatt_email' => (string) $customer->primaryEmail(),
            'fatt_referente' => trim("{$customer->first_name} {$customer->last_name}"),
            // Riquadro riservato ad Alex.
            'int_codice_cliente' => (string) $customer->gestionale_code,
        ];

        // Chi paga: se l'anagrafica ha un pagante diverso, il riquadro B parte
        // gia' compilato con i suoi dati e con la casella giusta spuntata.
        $pagante = $customer->billingCustomer;

        if (! $pagante) {
            return $valori + ['pagante_tipo' => 'stesso'];
        }

        return $valori + [
            'pagante_tipo' => 'terzo',
            'pag_ragione_sociale' => self::nome($pagante),
            'pag_piva' => (string) $pagante->vat_number,
            'pag_cf' => (string) $pagante->tax_code,
            'pag_via' => (string) $pagante->street,
            'pag_cap' => (string) $pagante->postal_code,
            'pag_citta' => DisplayName::titleCase($pagante->city) ?? '',
            'pag_prov' => (string) $pagante->province,
            'pag_sdi' => (string) $pagante->sdi,
            'pag_pec' => (string) $pagante->pec,
            'pag_email' => (string) $pagante->primaryEmail(),
            'pag_tel' => (string) $pagante->primaryPhone(),
        ];
    }

    /**
     * @return array<string, string>
     */
    private static function sede(Customer $sede, int $n, Customer $intestatario): array
    {
        $p = "sede{$n}_";

        return [
            $p.'insegna' => self::nome($sede),
            $p.'via' => (string) $sede->street,
            $p.'cap' => (string) $sede->postal_code,
            $p.'citta' => DisplayName::titleCase($sede->city) ?? '',
            $p.'prov' => (string) $sede->province,
            $p.'referente' => trim("{$sede->first_name} {$sede->last_name}"),
            $p.'tel' => (string) $sede->primaryPhone(),
            $p.'email' => (string) $sede->primaryEmail(),
            // La sede coincide con l'intestatario solo quando il cliente non
            // ha altre anagrafiche a suo carico.
            $p.'fatt_come_a' => $sede->is($intestatario) || $sede->billing_customer_id === $intestatario->id
                ? 'Yes'
                : '',
        ];
    }

    /**
     * Le macchine installate presso le sedi elencate sopra, con il numero di
     * sede accanto: e' la colonna che rende la tabella collegabile ai blocchi
     * della sezione D.
     *
     * La colonna "chi paga" era in bianco perche' il CRM non sapeva chi
     * pagasse una singola matricola. Ora lo sa: gli installati di Eureka
     * portano il pagante per macchina, e su 567 e' valorizzato.
     *
     * Riempirla non e' un dettaglio: il pagante scritto sull'anagrafica vale
     * per il cliente intero, e su Patatrac era falso su tre macchine su
     * cinque — due le paga Martellozzo, due Dersut, e il forno il cliente.
     * Una riga sola in cima alla scheda non poteva dirlo.
     *
     * @param  Collection<int, Customer>  $sedi
     * @return array<string, string>
     */
    private static function macchine($sedi): array
    {
        $numeroPerSede = [];
        foreach ($sedi->values() as $i => $sede) {
            $numeroPerSede[$sede->id] = $i + 1;
        }

        $unita = MachineUnit::query()
            ->whereIn('current_customer_id', array_keys($numeroPerSede))
            ->with('product', 'material', 'billingCustomer')
            ->orderBy('current_customer_id')
            ->orderBy('serial_number')
            ->limit(self::MAX_MACCHINE)
            ->get();

        $valori = [];

        foreach ($unita as $i => $macchina) {
            $n = $i + 1;
            $valori["mac{$n}_sede"] = (string) ($numeroPerSede[$macchina->current_customer_id] ?? '');
            $valori["mac{$n}_modello"] = (string) ($macchina->model_name
                ?: $macchina->product?->name
                ?: $macchina->material?->display_label);
            $valori["mac{$n}_matricola"] = (string) $macchina->serial_number;
            // Vuoto quando paga il cliente stesso: e' il caso normale, e
            // ripeterlo su ogni riga toglierebbe risalto alle poche che
            // fanno eccezione.
            $valori["mac{$n}_proprieta"] = $macchina->billingCustomer
                ? self::nome($macchina->billingCustomer)
                : '';
        }

        return $valori;
    }

    /**
     * La ragione sociale esce COSI' COM'E' salvata, senza il Title Case che
     * usiamo a schermo: qui e' il nome che il cliente si trova stampato su un
     * modulo da firmare, e "BAR CENTRALE SRL" ritoccato in "Bar Centrale Srl"
     * non e' piu' la denominazione registrata. Il Title Case resta solo sulla
     * citta', dove e' innocuo e rende leggibili i CAPS che arrivano dal
     * gestionale.
     */
    private static function nome(Customer $customer): string
    {
        return $customer->company_name ?: trim("{$customer->first_name} {$customer->last_name}");
    }
}
