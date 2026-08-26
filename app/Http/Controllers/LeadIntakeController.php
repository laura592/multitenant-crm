<?php

namespace App\Http\Controllers;

use App\Models\Customer;
use App\Models\InformationRequest;
use App\Models\Tenant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Riceve i lead dal form di alexcaffe.com e li trasforma in Richieste
 * informazioni.
 *
 * Automatizza un lavoro che oggi si fa a mano: il lead arriva dal modulo,
 * qualcuno lo rilegge e lo ribatte nel CRM circa un'ora dopo (vedi
 * RI-2026-0061, Hotel Trevi). Qui la ricopiatura sparisce, e con lei
 * l'errore di trascrizione che prima o poi arriva.
 */
class LeadIntakeController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        $dati = $request->validate([
            // Idempotenza: e' l'id della submission lato sito. Il job puo'
            // riprovare quanto vuole, la richiesta resta una.
            'external_id' => ['required', 'string', 'max:255'],
            'origin_url' => ['nullable', 'string', 'max:2048'],

            'provincia' => ['required', 'string', 'size:2'],
            'interesse' => ['required', Rule::in(['caffe', 'attrezzature', 'assistenza'])],
            'ragione_sociale' => ['required', 'string', 'max:255'],
            'nome' => ['nullable', 'string', 'max:255'],
            'cognome' => ['nullable', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255'],
            'telefono' => ['nullable', 'string', 'max:64'],
            'sede' => ['nullable', 'string', 'max:255'],
            'tipo_attivita' => ['nullable', 'string', 'max:64'],
            'volumi' => ['nullable', 'string', 'max:32'],
            'note' => ['nullable', 'string'],

            'consenso_privacy' => ['required', 'boolean'],
            'consenso_marketing' => ['nullable', 'boolean'],
        ]);

        $tenant = Tenant::query()->firstOrFail();

        // Fuori da una transazione, un fallimento a meta' lascerebbe
        // un'anagrafica orfana senza la richiesta che la giustifica.
        $richiesta = DB::transaction(function () use ($dati, $tenant) {
            $esistente = InformationRequest::withoutGlobalScopes()
                ->where('tenant_id', $tenant->id)
                ->where('external_id', $dati['external_id'])
                ->first();

            if ($esistente) {
                return $esistente;
            }

            $cliente = $this->risolviCliente($dati, $tenant);

            return InformationRequest::create([
                'tenant_id' => $tenant->id,
                'customer_id' => $cliente->id,
                'status' => 'nuova',
                'source' => 'sito',
                'origin_url' => $dati['origin_url'] ?? null,
                'external_id' => $dati['external_id'],
                'raw_payload' => $dati,
                'request_details' => $this->componiDettagli($dati),
            ]);
        });

        return response()->json([
            'numero' => $richiesta->number,
            'cliente' => $richiesta->customer?->company_name,
        ], 201);
    }

    /**
     * Trova il cliente o lo crea.
     *
     * L'ordine di ricerca va dal piu' affidabile al piu' fragile: email
     * esatta, poi ragione sociale nella stessa provincia. Nel dubbio si crea
     * un record nuovo e si lascia il merge all'operatore — un doppione
     * visibile e' meglio di una richiesta attaccata al cliente sbagliato.
     */
    private function risolviCliente(array $dati, Tenant $tenant): Customer
    {
        $email = mb_strtolower(trim($dati['email']));

        $cliente = Customer::withoutGlobalScopes()
            ->where('tenant_id', $tenant->id)
            ->whereJsonContains('emails', $email)
            ->first();

        if (! $cliente) {
            $cliente = Customer::withoutGlobalScopes()
                ->where('tenant_id', $tenant->id)
                ->where('company_name', $dati['ragione_sociale'])
                ->where('province', $dati['provincia'])
                ->first();
        }

        $consensi = [
            'consent_privacy_at' => $dati['consenso_privacy'] ? now() : null,
            'consent_marketing_at' => ($dati['consenso_marketing'] ?? false) ? now() : null,
            'consent_source' => 'form sito — '.($dati['origin_url'] ?? 'alexcaffe.com'),
        ];

        if ($cliente) {
            // Su un cliente che c'e' gia' si aggiornano SOLO i consensi, e
            // solo in aggiunta: un lead dal sito non deve poter sovrascrivere
            // un'anagrafica curata a mano o arrivata da Eureka.
            $cliente->fill(array_filter($consensi, fn ($v) => filled($v)))->save();

            return $cliente;
        }

        // Nasce come anagrafica del CRM, senza approvazione: entra nel flusso
        // di revisione esistente e NON viene mai spinta in Eureka da sola.
        // Nota: il form Filament pretende codice fiscale o P.IVA in creazione,
        // ma quella regola vive nel form, non nel database — un lead dal sito
        // non ha ne' l'uno ne' l'altra e va completato da una persona prima
        // di poter andare nel gestionale.
        return Customer::create(array_merge([
            'tenant_id' => $tenant->id,
            'company_name' => $dati['ragione_sociale'],
            'first_name' => $dati['nome'] ?? null,
            'last_name' => $dati['cognome'] ?? null,
            'emails' => [$email],
            'phones' => array_values(array_filter([$dati['telefono'] ?? null])),
            'city' => $dati['sede'] ?? null,
            'province' => $dati['provincia'],
            'source' => Customer::SOURCE_APP,
        ], $consensi));
    }

    /**
     * Il testo che l'operatore legge aprendo la richiesta. Le note libere
     * per ultime: sono la parte che conta, e va trovata senza scorrere.
     */
    private function componiDettagli(array $dati): string
    {
        $etichette = [
            'interesse' => 'Interesse',
            'tipo_attivita' => 'Tipo di attività',
            'sede' => 'Sede',
            'provincia' => 'Provincia',
            'volumi' => 'Caffè al giorno',
        ];

        $righe = ['Richiesta arrivata dal sito.'];

        foreach ($etichette as $campo => $etichetta) {
            if (filled($dati[$campo] ?? null)) {
                $righe[] = "{$etichetta}: {$dati[$campo]}";
            }
        }

        if (filled($dati['note'] ?? null)) {
            $righe[] = '';
            $righe[] = 'Note: '.$dati['note'];
        }

        return implode("\n", $righe);
    }
}
