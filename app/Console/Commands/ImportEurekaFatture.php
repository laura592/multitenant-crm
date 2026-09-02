<?php

namespace App\Console\Commands;

use App\Models\Customer;
use App\Models\EurekaFattura;
use App\Models\Tenant;
use App\Support\Gestionale\EurekaClient;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Ricarica la copia locale delle fatture registrate in contabilita' su
 * Eureka, clienti e fornitori.
 *
 * Due chiamate in tutto (una per lato): l'API restituisce l'intero elenco in
 * un colpo, quindi non serve interrogare cliente per cliente.
 *
 * Base per le analisi che non dipendono dalle partite: acconti senza saldo,
 * verifica del soggetto fatturato, stagionalita' del fatturato. Le partite
 * aperte vivono in eureka_partite_aperte e hanno problemi di affidabilita'
 * noti (vedi quella tabella); queste no.
 */
class ImportEurekaFatture extends Command
{
    protected $signature = 'eureka:import-fatture {--tenant=} {--dal=2023-01-01}';

    protected $description = 'Ricarica da Eureka le fatture registrate in contabilita';

    public function handle(): int
    {
        $tenant = $this->option('tenant')
            ? Tenant::where('slug', $this->option('tenant'))->firstOrFail()
            : Tenant::where('is_master', true)->firstOrFail();

        if (! $tenant->hasGestionaleEurekaCredentials()) {
            $this->error("Il tenant \"{$tenant->name}\" non ha credenziali Eureka configurate.");

            return self::FAILURE;
        }

        $client = new EurekaClient($tenant);
        $dal = (string) $this->option('dal');

        // Un lato per volta, e si riscrive solo quello che e' arrivato.
        //
        // Prima le due liste finivano in un array unico con una sola DELETE:
        // se /contabilita/fatture/clienti falliva e /fornitori rispondeva,
        // l'array non era vuoto, la guardia non scattava, e la cancellazione
        // si portava via TUTTE le fatture clienti — cioe' proprio quelle su
        // cui si reggono le analisi contabili — per riscrivere solo i
        // fornitori. Con i 500 a raffica gia' visti da Eureka era una
        // questione di quando, non di se.
        $lati = [];
        $falliti = [];

        foreach ([EurekaFattura::TIPO_CLIENTE, EurekaFattura::TIPO_FORNITORE] as $tipo) {
            $righe = $this->raccogli($client, $tenant, $tipo, $dal);

            if ($righe === null) {
                $falliti[] = $tipo;

                continue;
            }

            $lati[$tipo] = $righe;
        }

        foreach ($falliti as $tipo) {
            $this->error("Eureka non ha risposto per le fatture {$tipo}: quelle gia' in archivio restano invariate.");
        }

        if ($lati === []) {
            return self::FAILURE;
        }

        // Gli acconti si marcano solo se ci sono le fatture clienti: la
        // detrazione sta su un documento cliente, e girare la ricerca sui
        // soli fornitori spegnerebbe ogni e_acconto senza motivo.
        if (isset($lati[EurekaFattura::TIPO_CLIENTE])) {
            $this->marcaAcconti($client, $lati[EurekaFattura::TIPO_CLIENTE], $dal);
        }

        DB::transaction(function () use ($tenant, $lati) {
            foreach ($lati as $tipo => $righe) {
                EurekaFattura::where('tenant_id', $tenant->id)->where('tipo', $tipo)->delete();

                foreach (array_chunk($righe, 500) as $blocco) {
                    EurekaFattura::insert($blocco);
                }
            }
        });

        foreach ($lati as $tipo => $righe) {
            $this->info(sprintf(
                'Fatture %s importate: %d (%s).',
                $tipo,
                count($righe),
                number_format((float) collect($righe)->sum('totale_doc'), 2, ',', '.'),
            ));
        }

        foreach ($client->failedEndpoints() as $problema) {
            $this->warn("{$problema['endpoint']}: {$problema['failures']}/{$problema['attempts']} fallite ({$problema['statuses']}).");
        }

        return $falliti === [] ? self::SUCCESS : self::FAILURE;
    }

    /**
     * NULL se la chiamata e' fallita, array (anche vuoto) se Eureka ha
     * risposto: solo il secondo caso autorizza a cancellare quel lato.
     *
     * @return array<int, array<string, mixed>>|null
     */
    private function raccogli(EurekaClient $client, Tenant $tenant, string $tipo, string $dal): ?array
    {
        $documenti = $client->fattureContabili($tipo === EurekaFattura::TIPO_FORNITORE, $dal);

        if ($documenti === null) {
            return null;
        }

        if ($documenti === []) {
            return [];
        }

        $codici = array_values(array_filter(array_map(fn ($d) => (int) ($d['codice'] ?? 0), $documenti)));

        $customerPerCodice = Customer::query()
            ->where('tenant_id', $tenant->id)
            ->whereIn('gestionale_code', array_unique($codici))
            ->pluck('id', 'gestionale_code')
            ->all();

        $adesso = now();
        $righe = [];

        foreach ($documenti as $d) {
            $codice = (int) ($d['codice'] ?? 0);

            $righe[] = [
                'id' => (string) Str::uuid7(),
                'tenant_id' => $tenant->id,
                'tipo' => $tipo,
                'id_eureka' => (int) ($d['id'] ?? 0),
                'gestionale_code' => $codice ?: null,
                'customer_id' => $customerPerCodice[$codice] ?? null,
                'ragione_sociale' => $d['rag_sociale'] ?? null,
                'partita_iva' => $d['partita_iva'] ?? null,
                'numero_doc' => isset($d['numero_doc']) ? trim((string) $d['numero_doc']) : null,
                'data_doc' => $this->data($d['data_doc'] ?? null),
                'totale_doc' => round((float) ($d['totale_doc'] ?? 0), 2),
                'imponibile' => round((float) ($d['imponibile'] ?? 0), 2),
                'pagamento' => $d['pagamento'] ?? null,
                'causale' => $d['causale'] ?? null,
                'id_b10_origine' => ((int) ($d['id_b10_origine'] ?? 0)) ?: null,
                'e_acconto' => false,
                'detrae_acconto_numero' => null,
                'detrazione_ambigua' => false,
                'created_at' => $adesso,
                'updated_at' => $adesso,
            ];
        }

        return $righe;
    }

    /**
     * Marca quali documenti sono fatture di acconto e quali ne detraggono
     * una, leggendo il testo delle righe: e' l'unico modo, perche' la lista
     * fatture non distingue FA/FAD da FT e la causale e' 101 per entrambe.
     *
     * L'abbinamento e' per CLIENTE + NUMERO + ANNO dell'acconto citato nella
     * riga "A DETRARRE FATTURA DI ACCONTO NR X/AA". Se qualcuno scrive
     * quella dicitura in modo diverso l'acconto risultera' falsamente non
     * saldato: la lista che ne esce va quindi letta come "casi da
     * verificare", non come certezza.
     *
     * @param  array<int, array<string, mixed>>  $righe  fatture CLIENTI, modificate per riferimento
     */
    private function marcaAcconti(EurekaClient $client, array &$righe, string $dal): void
    {
        $righeDocumento = $this->righeCheParlanoDiAcconti($client, $dal);

        $acconti = [];
        $detrazioni = [];
        $saldati = [];
        $ambigue = [];

        foreach ($righeDocumento as $r) {
            $descrizione = mb_strtoupper((string) ($r['descrizione_riga'] ?? ''));
            $cliente = (string) ($r['id_f15'] ?? '');
            $anno = $this->anno($r['data'] ?? null);

            // Non e' una detrazione: se sta su un FA/FAD, e' l'acconto stesso.
            if (! preg_match(self::VERBO_DETRARRE, $descrizione)) {
                if (in_array($r['tipo_doc'] ?? '', ['FA', 'FAD'], true)) {
                    $acconti[$this->chiaveDocumento($cliente, $r['numero'] ?? '', $anno)] = true;
                }

                continue;
            }

            // Solo FATTURE.
            //
            // La stessa riga "a detrarre" viene copiata sulla bolla (BC) e
            // sulla scheda lavoro (SL) che precedono la fattura, e la loro
            // numerazione e' indipendente da quella delle fatture: la bolla
            // 249 di un cliente finiva per marcare la sua FATTURA 249, che
            // con quell'acconto non c'entra nulla (visto sui dati reali su
            // due documenti). Peggio: una bolla che detrae un acconto SENZA
            // che la fattura di saldo sia mai stata emessa e' esattamente il
            // caso che questa analisi deve segnalare, e contarla come
            // saldata lo nascondeva.
            if (! $this->eUnaFattura((string) ($r['tipo_doc'] ?? ''))) {
                continue;
            }

            // Primo numero utile dopo la dicitura, con l'anno se c'e': e' il
            // numero dell'acconto detratto. Si accetta qualunque testo in
            // mezzo, entro pochi caratteri, invece di inseguire ogni
            // variante.
            if (! preg_match(self::ACCONTO_DETRATTO, $descrizione, $m)) {
                // "A DETRARRE FATTURA DI ACCONTO" e basta: la detrazione c'e'
                // stata ma non si sa quale acconto chiuda. Si tiene da parte
                // il cliente e la data, per non dichiarare aperto un acconto
                // che qualcosa ha detratto.
                $ambigue[$cliente][] = $this->data($r['data'] ?? null) ?? '';

                continue;
            }

            $numeroAcconto = $this->numeroDocumento($m[1]);

            $detrazioni[$this->chiaveDocumento($cliente, $r['numero'] ?? '', $anno)] = $numeroAcconto;

            // Senza anno nella dicitura la chiave resta "qualunque anno":
            // e' tutto cio' che si sa, e vale come prima.
            $saldati[$cliente.'|'.$numeroAcconto.'|'.$this->annoDaDicitura($m[2] ?? null)] = true;
        }

        foreach ($righe as &$riga) {
            $cliente = (string) ($riga['gestionale_code'] ?? '');
            $numero = $this->numeroDocumento($riga['numero_doc'] ?? '');
            $anno = $this->anno($riga['data_doc'] ?? null);

            $saldato = isset($saldati["{$cliente}|{$numero}|{$anno}"])
                || isset($saldati["{$cliente}|{$numero}|"]);

            $chiave = $this->chiaveDocumento($cliente, $riga['numero_doc'] ?? '', $anno);

            $riga['e_acconto'] = isset($acconti[$chiave]) && ! $saldato;
            $riga['detrae_acconto_numero'] = $detrazioni[$chiave] ?? null;
            $riga['detrazione_ambigua'] = $riga['e_acconto']
                && $this->haDetrazioniSenzaNumeroDopo($ambigue[$cliente] ?? [], $riga['data_doc'] ?? null);
        }
    }

    /**
     * Le righe documento che parlano di acconti, cercate per la parola
     * ACCONTO e per la sua abbreviazione ACCTO.
     *
     * NON si cerca il verbo "detrarre": e' scritto male. Sui dati reali
     * convivono "A DETRARRE" e "A DETARRRE", e cercando la forma corretta le
     * due righe col refuso sparivano — l'acconto 29/2024 di HOTEL MARCO POLO
     * da 5.856 euro risultava mai saldato mentre la sua fattura di saldo, la
     * 67 del mese dopo, lo detrae per intero. Il nome del documento invece
     * compare in TUTTE le righe viste (verificato il 2026-09-02: nessuna
     * delle 190 righe di detrazione ne e' priva), quindi e' l'aggancio
     * affidabile. Il verbo si riconosce dopo, sul testo, dove un refuso si
     * puo' assorbire con un'espressione regolare.
     *
     * @return array<string, array<string, mixed>>
     */
    private function righeCheParlanoDiAcconti(EurekaClient $client, string $dal): array
    {
        $righe = [];

        foreach (['ACCONTO', 'ACCTO'] as $termine) {
            foreach ($client->righeDocumentoContenenti($termine, $dal) as $r) {
                // Le due ricerche si sovrappongono su "ACCTO" contenuto in
                // frasi che dicono anche "ACCONTO": la chiave identifica la
                // riga, cosi' la stessa entra una volta sola.
                //
                // Nella chiave c'e' anche il documento per esteso (tipo e
                // numero) e non il solo id_doc: due documenti distinti con
                // la stessa dicitura — ed e' la norma, "FATTURA DI ACCONTO
                // PARI AL 50%" e' un testo standard — non devono mai
                // fondersi in una riga sola.
                $righe[implode('|', [
                    $r['id_doc'] ?? '',
                    $r['tipo_doc'] ?? '',
                    $r['numero'] ?? '',
                    $r['descrizione_riga'] ?? '',
                ])] = $r;
            }
        }

        return $righe;
    }

    /**
     * Il verbo "detrarre" com'e' scritto davvero: DETRARRE e DETARRRE
     * convivono negli stessi archivi. Chi lo scrive sbaglia le doppie, non
     * l'inizio della parola.
     */
    private const VERBO_DETRARRE = '/DET[AR]+RE/u';

    /** Il verbo, poi il primo numero utile, poi l'anno se c'e'. */
    private const ACCONTO_DETRATTO = '/DET[AR]+RE\D{0,40}?(\d+)\s*(?:\/\s*(\d{2,4}))?/u';

    /**
     * Una detrazione senza numero vale solo per gli acconti EMESSI PRIMA:
     * non si puo' detrarre un acconto che ancora non esiste.
     *
     * @param  array<int, string>  $dateDetrazioni
     */
    private function haDetrazioniSenzaNumeroDopo(array $dateDetrazioni, mixed $dataAcconto): bool
    {
        if ($dateDetrazioni === [] || blank($dataAcconto)) {
            return false;
        }

        foreach ($dateDetrazioni as $data) {
            if ($data !== '' && $data >= (string) $dataAcconto) {
                return true;
            }
        }

        return false;
    }

    /**
     * Un documento e' identificato da cliente + numero + anno: la
     * numerazione riparte da 1 ogni anno, quindi senza l'anno la fattura
     * 41/2026 e la 41/2023 dello stesso cliente sono la stessa chiave.
     */
    private function chiaveDocumento(mixed $cliente, mixed $numero, string $anno): string
    {
        return (string) $cliente.'|'.$this->numeroDocumento($numero).'|'.$anno;
    }

    /**
     * Gli zeri iniziali non contano: la stessa fattura e' "1" nella lista
     * contabile e "01/26" nella dicitura scritta a mano. Confrontarle
     * letteralmente lasciava aperti acconti gia' detratti — succedeva a due
     * dei dieci casi in elenco.
     */
    private function numeroDocumento(mixed $numero): string
    {
        $numero = trim((string) $numero);

        return ltrim($numero, '0') ?: $numero;
    }

    /** Anno a quattro cifre da una data, stringa vuota se non c'e'. */
    private function anno(mixed $data): string
    {
        return blank($data) ? '' : substr((string) $data, 0, 4);
    }

    /** L'anno scritto nella dicitura: "25" e "2025" sono la stessa cosa. */
    private function annoDaDicitura(?string $anno): string
    {
        if (blank($anno)) {
            return '';
        }

        return strlen($anno) === 2 ? '20'.$anno : $anno;
    }

    /**
     * Tipi documento che sono fatture. La regola e' il prefisso F, tolti gli
     * acconti stessi (FA/FAD): copre FT e FB visti sul campo e lascia fuori
     * bolle (BC), schede lavoro (SL), ordini e ricevute, che portano la
     * stessa dicitura ma hanno numerazione propria.
     */
    private function eUnaFattura(string $tipoDoc): bool
    {
        return str_starts_with($tipoDoc, 'F') && ! in_array($tipoDoc, ['FA', 'FAD'], true);
    }

    private function data(mixed $valore): ?string
    {
        if (blank($valore)) {
            return null;
        }

        try {
            return Carbon::parse((string) $valore)->toDateString();
        } catch (\Throwable) {
            return null;
        }
    }
}
