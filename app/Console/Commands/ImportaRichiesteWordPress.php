<?php

namespace App\Console\Commands;

use App\Models\Customer;
use App\Models\InformationRequest;
use App\Models\Tenant;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Recupera nel CRM le richieste arrivate dai moduli del vecchio sito
 * WordPress (30/09/2026).
 *
 * Il vecchio alexcaffe.com salvava le submission in wp_db7_forms (plugin
 * "Contact Form 7 Database"): 125 richieste fra il 24/10/2024 e il
 * 29/09/2026, due moduli diversi — "Modulo di contatto" (nome, email,
 * telefono, oggetto, messaggio) e "Richiedi Informazioni" (con ragione
 * sociale, tipo di attivita', modello, sede e volumi). Fino a meta' agosto
 * 2026 nessuna di queste e' mai entrata nel CRM: si rispondeva via email e
 * basta.
 *
 * Il file di ingresso e' il JSON estratto dal dump (una riga per
 * submission); il formato e' documentato in docs/richieste-wordpress.md.
 *
 * Idempotente: l'external_id e' l'id della submission, quindi rilanciare il
 * comando non duplica niente. Salta anche le richieste gia' inserite a mano
 * nel CRM, riconosciute dallo stesso cliente a ridosso della stessa data.
 */
class ImportaRichiesteWordPress extends Command
{
    protected $signature = 'richieste:importa-wordpress
        {file : il JSON estratto da wp_db7_forms}
        {--esegui : senza questo non scrive niente, elenca e basta}
        {--giorni-vecchia=90 : oltre questi giorni la richiesta nasce gia\' chiusa}';

    protected $description = 'Porta nel CRM le richieste dei moduli del vecchio sito WordPress';

    /** @var array<string, int> progressivo per anno, per non rinumerare il 2026 */
    private array $progressivi = [];

    public function handle(): int
    {
        $file = $this->argument('file');

        if (! is_file($file)) {
            $this->error("File non trovato: {$file}");

            return self::FAILURE;
        }

        $righe = json_decode((string) file_get_contents($file), true);

        if (! is_array($righe)) {
            $this->error('Il file non contiene un elenco JSON leggibile.');

            return self::FAILURE;
        }

        $esegui = (bool) $this->option('esegui');
        $tenant = Tenant::query()->firstOrFail();
        $limite = now()->subDays((int) $this->option('giorni-vecchia'));

        $conti = ['importate' => 0, 'gia_importate' => 0, 'gia_a_mano' => 0, 'clienti_nuovi' => 0, 'clienti_trovati' => 0, 'scartate' => 0];

        foreach ($righe as $r) {
            $email = mb_strtolower(trim((string) ($r['email'] ?? '')));

            if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $conti['scartate']++;
                $this->line("  <fg=gray>scartata (email non valida)</>: ".($r['id'] ?? '?'));

                continue;
            }

            $externalId = 'wp-'.($r['modulo'] ?? 'form').'-'.$r['id'];
            $data = Carbon::parse($r['data']);

            if (InformationRequest::withoutGlobalScopes()->where('external_id', $externalId)->exists()) {
                $conti['gia_importate']++;

                continue;
            }

            $cliente = $this->trovaCliente($email, $r, $tenant);

            // Dal 18/08/2026 qualcuno ha cominciato a ribattere a mano nel
            // CRM le richieste che arrivavano dal sito: quelle non vanno
            // importate una seconda volta.
            if ($cliente && $this->giaInseritaAMano($cliente, $data)) {
                $conti['gia_a_mano']++;
                $this->line("  <fg=yellow>gia' nel CRM</>: {$data->format('d/m/Y')} {$cliente->company_name}");

                continue;
            }

            $nome = $r['ragione_sociale'] ?: trim(($r['nome'] ?? '').' '.($r['cognome'] ?? ''));
            $stato = $data->lt($limite) ? 'chiusa' : 'nuova';

            $cliente ? $conti['clienti_trovati']++ : $conti['clienti_nuovi']++;
            $conti['importate']++;

            $this->line(sprintf(
                '  %s %-34s %-32s %s',
                $data->format('d/m/Y'),
                mb_substr($nome ?: $email, 0, 32),
                mb_substr($cliente?->company_name ?? '<fg=green>anagrafica nuova</>', 0, 30),
                $stato,
            ));

            if (! $esegui) {
                continue;
            }

            DB::transaction(function () use ($r, $email, $externalId, $data, $stato, $tenant, &$cliente, $nome) {
                $cliente ??= $this->creaCliente($email, $r, $tenant, $data, $nome);

                $richiesta = new InformationRequest([
                    'tenant_id' => $tenant->id,
                    'customer_id' => $cliente->id,
                    'number' => $this->numeroPerAnno($tenant->id, $data),
                    'status' => $stato,
                    'source' => 'sito',
                    'external_id' => $externalId,
                    'raw_payload' => $r,
                    'request_details' => $this->dettagli($r),
                ]);

                // La data vera e' quella della submission: importate tutte
                // con la data di oggi non si capirebbe piu' niente.
                $richiesta->created_at = $data;
                $richiesta->updated_at = $data;
                $richiesta->save();
            });
        }

        $this->newLine();
        $this->table(['', 'quante'], [
            ['da importare', $conti['importate']],
            ['  di cui su anagrafiche esistenti', $conti['clienti_trovati']],
            ['  di cui con anagrafica nuova', $conti['clienti_nuovi']],
            ['gia\' importate in precedenza', $conti['gia_importate']],
            ['gia\' inserite a mano nel CRM', $conti['gia_a_mano']],
            ['scartate', $conti['scartate']],
        ]);

        if (! $esegui) {
            $this->warn('Prova a vuoto: non e\' stato scritto niente. Rilancia con --esegui.');
        }

        return self::SUCCESS;
    }

    private function trovaCliente(string $email, array $r, Tenant $tenant): ?Customer
    {
        $cliente = Customer::withoutGlobalScopes()
            ->where('tenant_id', $tenant->id)
            ->whereJsonContains('emails', $email)
            ->first();

        if ($cliente || blank($r['ragione_sociale'] ?? null)) {
            return $cliente;
        }

        return Customer::withoutGlobalScopes()
            ->where('tenant_id', $tenant->id)
            ->where('company_name', $r['ragione_sociale'])
            ->first();
    }

    /**
     * Una richiesta dello stesso cliente a ridosso della submission e' la
     * stessa richiesta ribattuta a mano (RI-2026-0058..0070).
     */
    private function giaInseritaAMano(Customer $cliente, Carbon $data): bool
    {
        return InformationRequest::withoutGlobalScopes()
            ->where('customer_id', $cliente->id)
            ->whereNull('external_id')
            ->whereBetween('created_at', [$data->copy()->subDays(3), $data->copy()->addDays(14)])
            ->exists();
    }

    private function creaCliente(string $email, array $r, Tenant $tenant, Carbon $data, string $nome): Customer
    {
        return Customer::create([
            'tenant_id' => $tenant->id,
            'company_name' => $nome ?: $email,
            'first_name' => $r['nome'] ?: null,
            'last_name' => $r['cognome'] ?: null,
            'emails' => [$email],
            'phones' => array_values(array_filter([$r['telefono'] ?? null])),
            'city' => $r['sede'] ?: null,
            'source' => Customer::SOURCE_APP,
            // Il modulo aveva la spunta obbligatoria sull'informativa: il
            // consenso e' del giorno dell'invio, non di oggi.
            'consent_privacy_at' => $data,
            'consent_source' => 'modulo del vecchio sito alexcaffe.com',
        ]);
    }

    /**
     * Numero coerente con l'anno della richiesta: una del 2024 non puo'
     * chiamarsi RI-2026-qualcosa. Il progressivo riparte dal massimo gia'
     * presente in quell'anno, cosi' il 2026 continua dopo le esistenti.
     */
    private function numeroPerAnno(string $tenantId, Carbon $data): string
    {
        $anno = $data->format('Y');

        if (! isset($this->progressivi[$anno])) {
            $ultimo = InformationRequest::withoutGlobalScopes()
                ->where('tenant_id', $tenantId)
                ->where('number', 'like', "RI-{$anno}-%")
                ->orderByRaw('CAST(SUBSTRING(number, -4) AS UNSIGNED) DESC')
                ->value('number');

            $this->progressivi[$anno] = $ultimo && preg_match('/-(\d+)$/', $ultimo, $m) ? (int) $m[1] : 0;
        }

        $this->progressivi[$anno]++;

        return "RI-{$anno}-".str_pad((string) $this->progressivi[$anno], 4, '0', STR_PAD_LEFT);
    }

    private function dettagli(array $r): string
    {
        $righe = ['Richiesta arrivata dal modulo del vecchio sito ('.($r['modulo'] === 'richiedi_informazioni' ? 'Richiedi informazioni' : 'Modulo di contatto').').'];

        $campi = [
            'oggetto' => 'Oggetto',
            'attivita' => 'Tipo di attività',
            'modello' => 'Modello di interesse',
            'sede' => 'Sede',
            'capacita' => 'Capacità della struttura',
            'colazioni' => 'Colazioni al giorno',
            'telefono' => 'Telefono',
        ];

        foreach ($campi as $campo => $etichetta) {
            if (filled($r[$campo] ?? null)) {
                $righe[] = "{$etichetta}: {$r[$campo]}";
            }
        }

        if (filled($r['messaggio'] ?? null)) {
            $righe[] = '';
            $righe[] = 'Messaggio: '.$r['messaggio'];
        }

        return implode("\n", $righe);
    }
}
