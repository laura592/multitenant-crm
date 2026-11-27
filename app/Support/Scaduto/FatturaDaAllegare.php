<?php

namespace App\Support\Scaduto;

use App\Models\EurekaFattura;
use App\Models\EurekaPartitaAperta;
use App\Support\Gestionale\EurekaClient;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Da una partita aperta al PDF della fattura che l'ha generata.
 *
 * E' il pezzo piu' delicato di tutto il sollecito di pagamento, perche' e'
 * quello che decide QUALE documento finisce allegato alla richiesta di
 * soldi. Sbagliarlo non produce un errore: produce una mail perfetta con
 * dentro la fattura di qualcun altro.
 *
 * LA CATENA (tutta in locale, nessuna chiamata di ricerca):
 *
 *   eureka_partite_aperte (gestionale_code + anno + numero_fattura)
 *     -> eureka_fatture, stesso codice, stesso anno di data_doc,
 *        stesso numero_doc confrontato SENZA zeri iniziali
 *     -> campo id_b10_origine
 *     -> EurekaClient::pdfFattura($id)
 *
 * Copertura verificata su produzione il 27/11/2026: 135 partite scadute su
 * 135 trovano la loro fattura, nessuna collisione su 2.071 documenti.
 *
 * TRE TRAPPOLE, tutte misurate dal vivo, tutte costose:
 *
 * 1. id_eureka NON e' l'id che vuole pdfFattura(). Sono due numerazioni
 *    diverse — la registrazione contabile e il documento — sfalsate di una
 *    novantina nel 2026. Nel caso migliore /report/fattura/{id_eureka}
 *    risponde 404; nel caso peggiore risponde un PDF, e il PDF e' di un
 *    ALTRO CLIENTE: id_eureka 17750 (fattura 394 a Ristoalma) restituisce
 *    la fattura 373 di Martellozzo Lorenzo & C. Allegare quella a Ristoalma
 *    non e' un bug di contabilita', e' il listino di un cliente mandato a
 *    un altro.
 *
 * 2. Il join va filtrato su causale '101' e totale_doc > 0, altrimenti si
 *    allega una NOTA DI CREDITO chiamandola fattura da pagare. Non e'
 *    teoria: RTG SRL ha le partite 457 e 458 del 2025 con i segni
 *    invertiti rispetto ai documenti — la partita 458 risulta a debito di
 *    955,26 mentre il documento 458 e' una nota di credito di -955,26
 *    (causale 153). Con il filtro, quella partita semplicemente non trova
 *    niente da allegare, che e' il verso giusto in cui sbagliare: si
 *    telefona, non si manda una nota di credito dicendo "ci paghi questa".
 *
 * 3. eureka_fatture si ricarica una volta al giorno (05:45), le partite
 *    due (l'ultima alle 14:00). Una fattura emessa stamattina puo' quindi
 *    essere gia' in partita e non ancora in archivio: per quel caso c'e'
 *    il recupero dal vivo, che chiede a Eureka le fatture dalla data del
 *    documento in poi e trova lo stesso id_b10_origine.
 *
 * Il PDF e' la COPIA DI CORTESIA, quella con stampato sopra "Copia non
 * valida ai fini fiscali ai sensi art. 3, 4C Provv. AE 30.4.18". E' l'unica
 * che l'API esponga — la fattura elettronica sta nel modulo documenti, che
 * sui tipi FT risponde 401 — ed e' quella giusta: un promemoria di
 * pagamento non e' una riemissione fiscale, e l'originale il cliente ce
 * l'ha gia' nel suo cassetto fiscale.
 */
final class FatturaDaAllegare
{
    private function __construct(
        public readonly EurekaPartitaAperta $partita,
        public readonly int $idB10,
        public readonly string $numero,
        public readonly ?CarbonInterface $data,
        public readonly float $totale,
    ) {}

    /**
     * Le fatture allegabili per un elenco di partite, nell'ordine in cui le
     * partite arrivano.
     *
     * Senza client si guarda SOLO l'archivio locale: e' il caso del modulo
     * d'invio, che gira dentro una richiesta Livewire e non puo'
     * permettersi round-trip verso un gestionale che ogni tanto risponde
     * 500 per qualche minuto di fila. Con il client (cioe' dentro la coda)
     * si recupera dal vivo anche quel che l'archivio non ha ancora.
     *
     * @param  Collection<int, EurekaPartitaAperta>  $partite
     * @return array<string, self> id della partita => fattura
     */
    public static function perPartite(Collection $partite, ?EurekaClient $client = null): array
    {
        $trovate = [];
        $mancanti = [];

        foreach ($partite as $partita) {
            if (! self::haUnNumero($partita)) {
                continue;
            }

            if ($fattura = self::dallArchivio($partita)) {
                $trovate[$partita->id] = self::costruisci($partita, $fattura);

                continue;
            }

            $mancanti[] = $partita;
        }

        if ($client !== null && $mancanti !== []) {
            foreach (self::dalVivo($client, $mancanti) as $id => $fattura) {
                $trovate[$id] = $fattura;
            }
        }

        return $trovate;
    }

    /**
     * Il numero di documento da citare. Una partita senza numero e' un
     * riporto di apertura del vecchio gestionale (01/01/2023: 14 partite
     * per 27.245,27 EUR su 13 clienti): e' un credito vero, ma non c'e'
     * nessun documento da allegare ne' da nominare al telefono.
     *
     * Il confronto comprende lo zero perche' una partita porta davvero
     * numero_fattura = "0" (Hermes, 411,16 EUR): e' un numero che non
     * corrisponde a nessun documento, e cercarlo trova per forza niente.
     */
    private static function haUnNumero(EurekaPartitaAperta $partita): bool
    {
        return filled($partita->numero_fattura) && (int) $partita->numero_fattura > 0;
    }

    private static function costruisci(EurekaPartitaAperta $partita, EurekaFattura $fattura): self
    {
        return new self(
            partita: $partita,
            idB10: (int) $fattura->id_b10_origine,
            numero: (string) $fattura->numero_doc,
            data: $fattura->data_doc,
            totale: (float) $fattura->totale_doc,
        );
    }

    /** La fattura nella nostra copia dell'archivio Eureka, se c'e'. */
    private static function dallArchivio(EurekaPartitaAperta $partita): ?EurekaFattura
    {
        return EurekaFattura::query()
            ->where('tenant_id', $partita->tenant_id)
            ->where('tipo', EurekaFattura::TIPO_CLIENTE)
            ->where('gestionale_code', $partita->gestionale_code)
            ->whereYear('data_doc', $partita->anno)
            // Senza zeri iniziali da entrambe le parti: la partita porta il
            // numero come testo ("0045") e il documento come testo suo.
            ->whereRaw('CAST(numero_doc AS UNSIGNED) = ?', [(int) $partita->numero_fattura])
            // Solo fatture vere, e solo a debito: vedi la trappola 2.
            ->where('causale', '101')
            ->where('totale_doc', '>', 0)
            // id_b10_origine e' l'unica strada verso il PDF: una fattura
            // senza quel campo non e' allegabile, e fingere di si'
            // significherebbe chiamare pdfFattura(0).
            ->whereNotNull('id_b10_origine')
            ->first();
    }

    /**
     * Recupero dal vivo per le fatture troppo fresche per essere in
     * archivio (vedi trappola 3).
     *
     * UNA chiamata sola per tutte le mancanti, a partire dalla piu' vecchia:
     * /contabilita/fatture/clienti?data_da=... porta gia' id_b10_origine, e
     * chiederla una volta per fattura vorrebbe dire tornare a fare N
     * round-trip verso un servizio che ogni tanto cade.
     *
     * Se Eureka non risponde non succede niente di drammatico: quelle
     * partite restano senza allegato, e chi manda il sollecito lo vede
     * scritto prima di mandarlo.
     *
     * @param  array<int, EurekaPartitaAperta>  $mancanti
     * @return array<string, self>
     */
    private static function dalVivo(EurekaClient $client, array $mancanti): array
    {
        $dal = collect($mancanti)
            ->map(fn (EurekaPartitaAperta $p) => $p->data_fattura?->toDateString() ?? $p->anno.'-01-01')
            ->min();

        $fatture = $client->fattureContabili(dal: (string) $dal);

        if ($fatture === null) {
            return [];
        }

        $trovate = [];

        foreach ($mancanti as $partita) {
            foreach ($fatture as $riga) {
                // "codice" e non "gestionale_code": nella risposta di Eureka
                // l'anagrafica si chiama cosi' (vedi ImportEurekaFatture),
                // ed e' il nostro import a ribattezzarla quando la salva.
                $data = $riga['data_doc'] ?? null;
                $idB10 = (int) ($riga['id_b10_origine'] ?? 0);

                $stessaFattura = (int) ($riga['codice'] ?? 0) === (int) $partita->gestionale_code
                    && (int) trim((string) ($riga['numero_doc'] ?? '')) === (int) $partita->numero_fattura
                    && is_string($data)
                    && (int) substr($data, 0, 4) === (int) $partita->anno;

                // Gli stessi due filtri dell'archivio, con le stesse
                // ragioni: una nota di credito allegata come fattura da
                // pagare e' sbagliata anche quando arriva dal vivo.
                if ($stessaFattura && (string) ($riga['causale'] ?? '') === '101' && (float) ($riga['totale_doc'] ?? 0) > 0 && $idB10 > 0) {
                    $trovate[$partita->id] = new self(
                        partita: $partita,
                        idB10: $idB10,
                        numero: trim((string) $riga['numero_doc']),
                        data: Carbon::parse($data),
                        totale: (float) $riga['totale_doc'],
                    );

                    break;
                }
            }
        }

        return $trovate;
    }

    /**
     * Quello che il cliente deve ancora su questa fattura.
     *
     * E' il saldo della PARTITA, non il totale del documento: otto fatture
     * scadute su 135 sono pagate a meta' (Ristoalma, fattura 199: totale
     * 10.323,68, residuo 3.447,50). Il PDF porta sempre l'importo pieno,
     * quindi senza questo numero scritto accanto il cliente legge 10.323,68
     * e risponde "ho gia' pagato" — e ha ragione lui.
     */
    public function residuo(): float
    {
        return (float) $this->partita->saldo;
    }

    /** Vero quando il PDF allegato dice un importo diverso da quel che resta. */
    public function parziale(): bool
    {
        return round($this->totale - $this->residuo(), 2) !== 0.0;
    }

    public function scadenza(): ?CarbonInterface
    {
        return $this->partita->data_scadenza;
    }

    public function giorniDiRitardo(): ?int
    {
        return $this->partita->giorniDiRitardo();
    }

    /**
     * Il nome con cui il file arriva al cliente: "fattura-199-2026.pdf".
     * Numero e anno insieme perche' la numerazione riparte ogni anno, e in
     * una mail con nove allegati "fattura-199.pdf" due volte non si
     * distingue.
     */
    public function nomeFile(): string
    {
        return 'fattura-'.$this->numero.'-'.$this->partita->anno.'.pdf';
    }

    /** Il PDF, o NULL se Eureka non l'ha dato. Chi chiama decide se ritentare. */
    public function pdf(EurekaClient $client): ?string
    {
        return $client->pdfFattura($this->idB10);
    }

    /**
     * La riga da congelare nel registro: quello che quel giorno abbiamo
     * chiesto, con il numero di documento e il residuo di allora.
     *
     * @return array<string, mixed>
     */
    public function perIlRegistro(): array
    {
        return [
            'anno' => (int) $this->partita->anno,
            'numero' => $this->numero,
            'data' => $this->data?->toDateString(),
            'scadenza' => $this->scadenza()?->toDateString(),
            'totale' => round($this->totale, 2),
            'residuo' => round($this->residuo(), 2),
            'id_b10' => $this->idB10,
            'allegata' => true,
        ];
    }
}
