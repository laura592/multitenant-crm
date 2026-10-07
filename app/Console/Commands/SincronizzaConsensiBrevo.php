<?php

namespace App\Console\Commands;

use App\Models\Customer;
use App\Models\Tenant;
use App\Support\Brevo\BrevoClient;
use App\Support\Brevo\RegioniProvince;
use Illuminate\Console\Command;
use Throwable;

/**
 * Porta su Brevo chi ha dato il consenso marketing nel CRM, e mette in
 * blacklist chi lo ha revocato.
 *
 * E' l'unica strada con cui un contatto entra nelle liste delle campagne con
 * un consenso documentato: la data e l'origine vengono dal CRM
 * (consent_marketing_at, consent_source — oggi il modulo del sito, vedi
 * LeadIntakeController). Gli import del vecchio WordPress hanno solo il
 * consenso privacy e restano fuori da soli.
 *
 * Si manda solo cio' che e' cambiato dall'ultimo invio (brevo_sync_hash).
 */
class SincronizzaConsensiBrevo extends Command
{
    protected $signature = 'brevo:sincronizza-consensi
                            {--tenant= : slug del tenant (default: tutti gli attivi)}
                            {--dry : mostra cosa farebbe senza scrivere su Brevo}';

    protected $description = 'Manda a Brevo i clienti con consenso marketing e blocca chi lo ha revocato';

    public function handle(): int
    {
        $brevo = BrevoClient::fromConfig();
        $dry = (bool) $this->option('dry');

        if (! $brevo && ! $dry) {
            $this->warn('BREVO_API_KEY non configurata: nessun invio.');

            return self::SUCCESS;
        }

        $tenants = Tenant::query()->where('is_active', true)
            ->when($this->option('tenant'), fn ($q, $slug) => $q->where('slug', $slug))
            ->get();

        foreach ($tenants as $tenant) {
            [$inviati, $bloccati, $errori] = $this->sincronizza($tenant, $brevo, $dry);
            $this->info("{$tenant->name}: {$inviati} contatti inviati, {$bloccati} bloccati, {$errori} errori".($dry ? ' (simulazione)' : ''));
        }

        return self::SUCCESS;
    }

    /** @return array{0: int, 1: int, 2: int} */
    private function sincronizza(Tenant $tenant, ?BrevoClient $brevo, bool $dry): array
    {
        $inviati = $bloccati = $errori = 0;
        $listaConsensi = $dry ? null : $brevo->idListaOCreala(config('services.brevo.lista_consensi'));

        $clienti = Customer::withoutGlobalScopes()
            ->where('tenant_id', $tenant->id)
            ->whereNull('deleted_at')
            ->where(fn ($q) => $q->whereNotNull('consent_marketing_at')->orWhereNotNull('brevo_synced_at'))
            ->get();

        foreach ($clienti as $cliente) {
            $email = $this->email($cliente);

            if (! $email) {
                continue;
            }

            try {
                // Consenso revocato dopo l'invio: blacklist, e si dimentica
                // l'invio cosi' un consenso nuovo riparte da capo.
                if (! $cliente->consent_marketing_at) {
                    if (! $dry) {
                        $brevo->blacklist($email);
                        $cliente->forceFill(['brevo_synced_at' => null, 'brevo_sync_hash' => null])->saveQuietly();
                    }
                    $this->line("  - blacklist {$email}");
                    $bloccati++;

                    continue;
                }

                $attributi = $this->attributi($cliente);
                $impronta = sha1(json_encode([$email, $attributi]));

                if ($cliente->brevo_sync_hash === $impronta) {
                    continue;
                }

                $this->line("  + {$email} — ".($attributi['RAGIONE_SOCIALE'] ?? ''));

                if (! $dry) {
                    $regione = $attributi['REGIONE'] ?? null;
                    $liste = array_filter([$listaConsensi, $regione ? $brevo->idLista($regione) : null]);
                    $brevo->salvaContatto($email, $attributi, $liste);
                    $cliente->forceFill(['brevo_synced_at' => now(), 'brevo_sync_hash' => $impronta])->saveQuietly();
                }
                $inviati++;
            } catch (Throwable $e) {
                $errori++;
                $this->warn("  ! {$email}: {$e->getMessage()}");
                report($e);
            }
        }

        return [$inviati, $bloccati, $errori];
    }

    private function email(Customer $cliente): ?string
    {
        $email = mb_strtolower(trim((string) collect($cliente->emails)->first()));

        return filter_var($email, FILTER_VALIDATE_EMAIL) ? $email : null;
    }

    /** @return array<string, mixed> */
    private function attributi(Customer $cliente): array
    {
        $telefoni = collect($cliente->phones)->map(fn ($t) => preg_replace('/[^\d+]/', '', (string) $t))->filter();
        $cellulare = $telefoni->first(fn ($t) => preg_match('/^\+393\d{8,9}$/', $t));
        $fisso = $telefoni->first(fn ($t) => $t !== $cellulare);

        return array_filter([
            'RAGIONE_SOCIALE' => $cliente->company_name,
            'NOME' => $cliente->first_name,
            'COGNOME' => $cliente->last_name,
            'VIA' => $cliente->street,
            'CAP' => ctype_digit((string) $cliente->postal_code) ? (int) $cliente->postal_code : null,
            'CITTA' => $cliente->city,
            'PROVINCIA' => $cliente->province ? strtoupper($cliente->province) : null,
            'REGIONE' => RegioniProvince::regione($cliente->province),
            'TELEFONO' => $fisso ?: $cellulare,
            'SMS' => $cellulare,
            'PIVA' => $cliente->vat_number,
            'PEC' => $cliente->pec,
            'SITO_WEB' => $cliente->website,
            'OPT_IN' => true,
            'DATA_CONSENSO' => $cliente->consent_marketing_at->toDateString(),
            'FONTE' => 'CRM — '.($cliente->consent_source ?: 'consenso marketing'),
        ], fn ($v) => $v !== null && $v !== '');
    }
}
