<?php

namespace App\Http\Controllers;

use App\Models\Customer;
use App\Models\Tenant;
use App\Support\LeadSpam;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Riceve le iscrizioni alla newsletter dal campo in fondo alle pagine di
 * alexcaffe.com (03/11/2026).
 *
 * NON diventa una Richiesta informazioni, al contrario di un lead: chi
 * lascia l'email in fondo a una pagina non ha chiesto niente a nessuno, e
 * una richiesta che nessuno deve lavorare, in mezzo a quelle vere, e' solo
 * rumore. Qui si registra un consenso, che e' tutto quello che serve: da
 * li' il sync orario (brevo:sincronizza-consensi) lo porta su Brevo.
 *
 * Il consenso vive sull'anagrafica perche' e' la' che il CRM lo tiene per
 * tutti -- sito, telefono, modulo cartaceo -- ed e' la' che si revoca. Se
 * l'email e' di un cliente che c'e' gia', si aggiorna lui e non nasce un
 * doppione; se non c'e', nasce un'anagrafica minima con source `app` e
 * senza approvazione, che per costruzione non finisce mai in Eureka da sola
 * (Customer::approved_for_gestionale_at).
 */
class NewsletterIntakeController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        $dati = $request->validate([
            // Idempotenza: e' l'id della submission lato sito, come per i
            // lead. Il job puo' riprovare quanto vuole.
            'external_id' => ['required', 'string', 'max:255'],
            'origin_url' => ['nullable', 'string', 'max:2048'],
            'email' => ['required', 'email', 'max:255'],
            'nome' => ['nullable', 'string', 'max:255'],
            // `accepted` e non `boolean`: un'iscrizione senza consenso non
            // e' un'iscrizione, e deve essere rifiutata, non registrata a
            // zero.
            'consenso_privacy' => ['required', 'accepted'],
        ]);

        // Stesso filtro dei lead: un bot che riempie il campo nel footer
        // non diventa un'anagrafica. Resta nel log, e il sito riceve 202
        // cosi' non riprova all'infinito.
        if ($spam = LeadSpam::motivo($dati)) {
            Log::warning('Iscrizione newsletter ignorata come spam: '.$spam, ['payload' => $dati]);

            return response()->json(['ignorato' => true, 'motivo' => $spam], 202);
        }

        $tenant = Tenant::query()->firstOrFail();
        $email = mb_strtolower(trim($dati['email']));

        $cliente = DB::transaction(function () use ($dati, $tenant, $email) {
            $cliente = Customer::withoutGlobalScopes()
                ->where('tenant_id', $tenant->id)
                ->whereJsonContains('emails', $email)
                ->first();

            $origine = 'newsletter sito — '.($dati['origin_url'] ?? 'alexcaffe.com');

            if ($cliente) {
                // La data del PRIMO consenso non si riscrive: e' quella che
                // conta se un domani qualcuno chiede quando e perche' e'
                // finito in lista. Una seconda iscrizione aggiorna solo
                // l'origine, e il resto dell'anagrafica non si tocca --
                // un'email nel footer non deve poter riscrivere un cliente
                // curato a mano o arrivato da Eureka.
                $cliente->fill([
                    'consent_privacy_at' => $cliente->consent_privacy_at ?: now(),
                    'consent_marketing_at' => $cliente->consent_marketing_at ?: now(),
                    'consent_source' => $origine,
                ])->save();

                return $cliente;
            }

            return Customer::create([
                'tenant_id' => $tenant->id,
                // Senza ragione sociale si usa l'email: e' l'unica cosa che
                // questa persona ha dato, e un'anagrafica senza nome in
                // elenco non si riconosce.
                'company_name' => $dati['nome'] ?: $email,
                'first_name' => $dati['nome'] ?: null,
                'emails' => [$email],
                'source' => Customer::SOURCE_APP,
                'consent_privacy_at' => now(),
                'consent_marketing_at' => now(),
                'consent_source' => $origine,
            ]);
        });

        return response()->json([
            'iscritto' => true,
            'cliente' => $cliente->company_name,
            'nuovo' => $cliente->wasRecentlyCreated,
        ], 201);
    }
}
