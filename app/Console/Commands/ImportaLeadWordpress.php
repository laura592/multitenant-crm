<?php

namespace App\Console\Commands;

use App\Models\Customer;
use App\Models\InformationRequest;
use App\Models\Tenant;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Importa gli invii del vecchio modulo WordPress (tabella wp_db7_forms) come
 * Richieste informazioni gia' chiuse.
 *
 * Sono richieste storiche, non lead da lavorare: entrano in stato `chiusa` per
 * non inquinare il badge delle richieste da gestire. Servono a ricostruire lo
 * storico di un cliente, non a generare lavoro.
 *
 * ATTENZIONE AL CONSENSO: la casella che questi contatti hanno accettato era
 * SOLO privacy, non marketing. Vengono importati con consent_privacy_at
 * valorizzato e consent_marketing_at nullo, e non vanno sincronizzati verso
 * Brevo su questa base.
 */
class ImportaLeadWordpress extends Command
{
    protected $signature = 'lead:importa-wordpress
                            {--dump= : percorso del .sql con la tabella wp_db7_forms}
                            {--dry : mostra cosa farebbe senza scrivere}';

    protected $description = 'Importa i lead del vecchio sito WordPress come richieste chiuse';

    public function handle(): int
    {
        $file = $this->option('dump');

        if (! $file || ! is_readable($file)) {
            $this->error('Serve --dump con il percorso del file .sql.');

            return self::FAILURE;
        }

        $righe = $this->estrai(file_get_contents($file));
        $this->info(count($righe).' invii trovati nel dump.');

        $tenant = Tenant::query()->firstOrFail();
        $creati = $saltati = 0;

        // Le date storiche si applicano DOPO aver creato tutto.
        // InformationRequest numera contando le richieste dell'anno corrente:
        // retrodatare dentro il ciclo toglie dal conteggio la riga appena
        // creata, e la successiva riceve lo stesso numero.
        $daRetrodatare = [];

        foreach ($righe as $r) {
            $externalId = 'wp-'.$r['id'];

            $gia = InformationRequest::withoutGlobalScopes()
                ->where('tenant_id', $tenant->id)
                ->where('external_id', $externalId)
                ->first();

            if ($gia) {
                // Gia' importata: non si ricrea, ma la data si riallinea
                // comunque. Un'interruzione a meta' lascia righe create senza
                // che la retrodatazione in coda sia mai arrivata.
                $daRetrodatare[$gia->id] = $r['data'];
                $saltati++;

                continue;
            }

            if (blank($r['email'])) {
                $saltati++;

                continue;
            }

            if ($this->option('dry')) {
                $this->line("  + {$r['email']} — {$r['nome']} ({$r['data']})");
                $creati++;

                continue;
            }

            DB::transaction(function () use ($r, $tenant, $externalId, &$daRetrodatare) {
                $email = mb_strtolower(trim($r['email']));

                $cliente = Customer::withoutGlobalScopes()
                    ->where('tenant_id', $tenant->id)
                    ->whereJsonContains('emails', $email)
                    ->first();

                $cliente ??= Customer::create([
                    'tenant_id' => $tenant->id,
                    'company_name' => $r['nome'] ?: $email,
                    'emails' => [$email],
                    'phones' => array_values(array_filter([$r['telefono']])),
                    'source' => Customer::SOURCE_APP,
                    // Solo privacy: la casella accettata sul vecchio sito non
                    // comprendeva il marketing.
                    'consent_privacy_at' => $r['data'],
                    'consent_source' => 'modulo vecchio sito WordPress',
                ]);

                $richiesta = InformationRequest::create([
                    'tenant_id' => $tenant->id,
                    'customer_id' => $cliente->id,
                    'status' => 'chiusa',
                    'source' => 'sito',
                    'origin_url' => 'https://www.alexcaffe.com (vecchio sito)',
                    'external_id' => $externalId,
                    'raw_payload' => $r['raw'],
                    'request_details' => $this->dettagli($r),
                ]);

                $daRetrodatare[$richiesta->id] = $r['data'];
            });

            $creati++;
        }

        // Ora che i numeri sono assegnati, si riallineano le date: senza,
        // lo storico apparirebbe tutto arrivato oggi.
        foreach ($daRetrodatare as $id => $data) {
            InformationRequest::withoutGlobalScopes()->whereKey($id)->update([
                'created_at' => $data,
                'updated_at' => $data,
            ]);
        }

        $this->info(($this->option('dry') ? 'Da creare: ' : 'Create: ')."{$creati} — saltate: {$saltati}");

        return self::SUCCESS;
    }

    /** Estrae le righe di wp_db7_forms dal dump e ne srotola il PHP serializzato. */
    private function estrai(string $sql): array
    {
        // Una regex non basta: i dati sono PHP serializzato e contengono
        // apici, virgole e parentesi che spezzano qualsiasi pattern ingenuo.
        // Serve scorrere il testo tenendo conto delle stringhe quotate.
        $out = [];

        foreach ($this->righeInsert($sql, 'wp_db7_forms') as $riga) {
            if (count($riga) < 4) {
                continue;
            }

            // Nel dump gli apici sono sfuggiti: vanno ripristinati prima di
            // deserializzare, altrimenti unserialize() fallisce sulle lunghezze.
            $serializzato = stripcslashes($riga[2]);
            $dati = @unserialize($serializzato);

            if (! is_array($dati)) {
                continue;
            }

            $out[] = [
                'id' => $riga[0],
                'data' => $riga[3],
                'email' => $dati['your-email'] ?? $dati['email'] ?? null,
                'nome' => trim(($dati['your-name'] ?? $dati['nome'] ?? '').' '.($dati['your-surname'] ?? $dati['cognome'] ?? '')),
                'telefono' => $dati['your-phone'] ?? $dati['telefono'] ?? $dati['your-number'] ?? null,
                'raw' => $dati,
            ];
        }

        return $out;
    }

    /**
     * Divide le VALUES di un INSERT in righe e campi, rispettando le stringhe
     * quotate e gli escape. E' l'unico modo affidabile di leggere un dump con
     * dentro PHP serializzato.
     *
     * @return array<int, array<int, string>>
     */
    private function righeInsert(string $sql, string $tabella): array
    {
        $righe = [];

        if (! preg_match_all('/INSERT INTO `'.$tabella.'`[^;]*?VALUES\s*(.*?);\s*$/ms', $sql, $blocchi)) {
            return $righe;
        }

        foreach ($blocchi[1] as $blocco) {
            $campi = [];
            $buffer = '';
            $inStringa = false;
            $escape = false;
            $profondita = 0;
            $len = strlen($blocco);

            for ($i = 0; $i < $len; $i++) {
                $c = $blocco[$i];

                if ($inStringa) {
                    if ($escape) {
                        $buffer .= $c;
                        $escape = false;
                    } elseif ($c === '\\') {
                        $buffer .= $c;
                        $escape = true;
                    } elseif ($c === "'") {
                        $inStringa = false;
                    } else {
                        $buffer .= $c;
                    }

                    continue;
                }

                if ($c === "'") {
                    $inStringa = true;
                } elseif ($c === '(' && $profondita++ === 0) {
                    $campi = [];
                    $buffer = '';
                } elseif ($c === ',' && $profondita === 1) {
                    $campi[] = trim($buffer);
                    $buffer = '';
                } elseif ($c === ')' && --$profondita === 0) {
                    $campi[] = trim($buffer);
                    $righe[] = $campi;
                    $buffer = '';
                } else {
                    $buffer .= $c;
                }
            }
        }

        return $righe;
    }

    private function dettagli(array $r): string
    {
        $d = $r['raw'];

        $righe = ['Richiesta storica, importata dal modulo del vecchio sito.'];

        foreach ([
            'your-subject' => 'Oggetto',
            'ragione_sociale' => 'Ragione sociale',
            'attivita' => 'Tipo di attività',
            'sede' => 'Sede',
            'modello' => 'Modello di interesse',
            'capacita_struttura' => 'Capacità struttura',
            'colazioni_max' => 'Colazioni max/giorno',
        ] as $k => $etichetta) {
            $v = $d[$k] ?? null;

            // I campi checkbox del vecchio modulo arrivano come array:
            // concatenarli direttamente fa "Array to string conversion".
            if (is_array($v)) {
                $v = implode(', ', array_filter($v, 'is_scalar'));
            }

            if (filled($v)) {
                $righe[] = "{$etichetta}: {$v}";
            }
        }

        $messaggio = $d['your-message'] ?? $d['messaggio'] ?? null;

        if (is_array($messaggio)) {
            $messaggio = implode("\n", array_filter($messaggio, 'is_scalar'));
        }

        if (filled($messaggio)) {
            $righe[] = '';
            $righe[] = $messaggio;
        }

        return implode("\n", $righe);
    }
}
