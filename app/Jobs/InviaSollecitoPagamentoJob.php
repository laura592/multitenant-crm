<?php

namespace App\Jobs;

use App\Mail\SollecitoPagamentoMail;
use App\Models\EurekaPartitaAperta;
use App\Models\SollecitoPagamento;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Gestionale\EurekaClient;
use App\Support\Scaduto\FatturaDaAllegare;
use App\Support\Scaduto\SollecitiPagamento;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Mail;

/**
 * Scarica le fatture da Eureka e manda il sollecito di pagamento.
 *
 * In coda e non dentro la richiesta: il cliente messo peggio ha nove
 * fatture scadute, cioe' nove PDF da chiedere al gestionale uno alla volta.
 * Trenta secondi buoni di finestra ferma, su un servizio che ogni tanto
 * risponde 500 a raffica per qualche minuto (vedi
 * storage/logs/eureka-import.log). Il pulsante risponde subito, il lavoro
 * lo fa qui, e chi ha premuto riceve una notifica quando e' finita — nel
 * bene e nel male, come fa AggiornaSaldiEurekaJob.
 *
 * MANDA ESATTAMENTE QUELLO CHE E' STATO APPROVATO, mai una fattura in piu'.
 * Il modulo d'invio mostra quante fatture e per quanti euro, e quella e' la
 * promessa: il job riparte dalla fotografia salvata e, se nel frattempo lo
 * scaduto e' cambiato, si ferma e lo dice invece di mandare una mail che
 * chiede una cifra che nessuno ha letto.
 */
class InviaSollecitoPagamentoJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Un tentativo solo: i ritentativi li gestiamo PDF per PDF qui dentro
     * (vedi ATTESE). Rimettere in coda l'intero job dopo che la mail e'
     * partita vorrebbe dire sollecitare due volte lo stesso cliente nello
     * stesso minuto.
     */
    public int $tries = 1;

    public int $timeout = 300;

    /**
     * Quanto si aspetta prima di richiedere un PDF che Eureka non ha dato.
     *
     * I 500 del gestionale arrivano a raffica e passano da soli: la stessa
     * query rifatta dopo qualche secondo risponde. Le attese crescono
     * perche' quando la raffica dura, insistere subito non fa che
     * aggiungere carico a un servizio gia' in ginocchio (il 12/10/2026 era
     * il pool di connessioni esaurito, vedi EurekaClient::nonDisponibile).
     */
    private const ATTESE = [5, 15, 45];

    public function __construct(private readonly SollecitoPagamento $sollecito) {}

    public function handle(): void
    {
        // Presa in carico atomica. Il driver database rimette in coda un
        // job che gira da piu' di 90 secondi (retry_after), e nove PDF con
        // due raffiche di 500 in mezzo ci arrivano: senza questo, il
        // secondo worker manderebbe lo stesso sollecito una seconda volta.
        $preso = SollecitoPagamento::query()
            ->whereKey($this->sollecito->getKey())
            ->where('status', SollecitoPagamento::STATO_IN_CODA)
            ->update(['status' => SollecitoPagamento::STATO_IN_CORSO]);

        if ($preso === 0) {
            return;
        }

        $tenant = $this->sollecito->tenant;

        if (! $tenant instanceof Tenant) {
            $this->fermati('Non trovo più l\'azienda di questo sollecito.');

            return;
        }

        // Il job gira senza pannello e senza utente: gli scope di tenant e
        // i metodi di SollecitiPagamento hanno bisogno di sapere qual e'
        // l'azienda, o non vedono niente (stessa ragione del feed ICS, vedi
        // CalendarioIcsController).
        Filament::setTenant($tenant, true);

        $codice = (int) $this->sollecito->gestionale_code;

        try {
            $client = new EurekaClient($tenant);
        } catch (\Throwable $e) {
            $this->fermati('Eureka non è configurato per questa azienda: '.$e->getMessage());

            return;
        }

        $partite = $this->partitePromesse($codice);

        if (is_string($partite)) {
            $this->fermati($partite);

            return;
        }

        // Il recupero dal vivo serve proprio qui: l'import delle fatture
        // svuota e ricarica la tabella intera ogni notte, quindi fra il
        // momento in cui si e' premuto "manda" e questo istante una fattura
        // puo' essere sparita dalla nostra copia pur esistendo su Eureka.
        $fatture = FatturaDaAllegare::perPartite($partite, $client);

        if (count($fatture) !== $partite->count()) {
            $this->fermati('Alcune fatture non sono più recuperabili da Eureka: non ho mandato niente.');

            return;
        }

        if ($motivo = SollecitiPagamento::nonPiuDovuto($client, $codice, $fatture)) {
            $this->fermati($motivo, avviso: true);

            return;
        }

        $pdf = [];

        foreach ($fatture as $fattura) {
            $contenuto = $this->scarica($client, $fattura);

            if ($contenuto === null) {
                // Niente invio parziale: un sollecito che chiede 5.841 euro
                // allegando tre fatture su cinque si fa rispondere "e le
                // altre?", e tocca rimandare tutto da capo scusandosi.
                $this->fermati('Eureka non mi ha dato il PDF della fattura '.$fattura->numero
                    .'. Non ho mandato niente: riprova fra qualche minuto.');

                return;
            }

            $pdf[$fattura->nomeFile()] = $contenuto;
        }

        try {
            Mail::to($this->sollecito->recipient_email)
                ->cc(array_values(array_unique(array_filter([
                    $this->sollecito->cc_email,
                    ...SollecitiPagamento::inCopiaSempre($tenant),
                ]))))
                ->send(new SollecitoPagamentoMail(
                    fatture: array_values($fatture),
                    pdf: $pdf,
                    tenant: $tenant,
                    customMessage: $this->sollecito->message,
                    subjectText: $this->sollecito->subject,
                ));
        } catch (\Throwable $e) {
            $this->fermati('Invio fallito: '.$e->getMessage());

            return;
        }

        $this->sollecito->update([
            'status' => SollecitoPagamento::STATO_INVIATO,
            'allegati' => array_keys($pdf),
            'error_message' => null,
        ]);

        $this->avvisa(
            'Sollecito di pagamento inviato',
            count($pdf).' '.(count($pdf) === 1 ? 'fattura' : 'fatture')
            .' a '.$this->sollecito->recipient_email.'.',
            riuscito: true,
        );
    }

    /**
     * Le partite da allegare: quelle promesse nella fotografia, ritrovate
     * una per una nello scaduto di adesso.
     *
     * Torna una stringa quando c'e' da fermarsi. Il confronto e' sul
     * residuo: se il cliente ha pagato qualcosa fra l'apertura del modulo e
     * adesso, la mail direbbe un numero che non e' piu' vero, e chi l'ha
     * scritta non lo saprebbe mai.
     *
     * @return Collection<int, EurekaPartitaAperta>|string
     */
    private function partitePromesse(int $codice): Collection|string
    {
        $promesse = collect($this->sollecito->partite ?? [])
            ->filter(fn (array $riga): bool => (bool) ($riga['allegata'] ?? false));

        $adesso = SollecitiPagamento::partite($codice);

        $trovate = [];

        foreach ($promesse as $promessa) {
            $partita = $adesso->first(fn (EurekaPartitaAperta $p): bool => (int) $p->anno === (int) ($promessa['anno'] ?? 0)
                && (int) $p->numero_fattura === (int) ($promessa['numero'] ?? 0));

            if (! $partita instanceof EurekaPartitaAperta) {
                return 'La fattura '.($promessa['numero'] ?? '?').' non risulta più fra le scadute: '
                    .'può darsi che il cliente abbia pagato. Non ho mandato niente.';
            }

            if (abs((float) $partita->saldo - (float) ($promessa['residuo'] ?? 0)) > 0.01) {
                return 'Sulla fattura '.($promessa['numero'] ?? '?').' l\'importo è cambiato dopo che hai premuto invia. '
                    .'Non ho mandato niente: riapri lo scaduto e ricontrolla.';
            }

            $trovate[] = $partita;
        }

        return collect($trovate);
    }

    /**
     * Il PDF di una fattura, riprovando quando Eureka non risponde.
     *
     * Stessa logica dei recuperi della sync (vedi
     * GestionaleSyncRunner::proposeMachineUnitLinks), con attese piu' lunghe
     * perche' qui non si sta leggendo un dato in piu': senza questo PDF il
     * sollecito non parte affatto.
     */
    private function scarica(EurekaClient $client, FatturaDaAllegare $fattura): ?string
    {
        if ($contenuto = $fattura->pdf($client)) {
            return $contenuto;
        }

        foreach (self::ATTESE as $attesa) {
            sleep($attesa);

            if ($contenuto = $fattura->pdf($client)) {
                return $contenuto;
            }
        }

        return null;
    }

    /** Si ferma, scrive perche' sulla riga del sollecito e lo dice a chi aveva premuto. */
    private function fermati(string $motivo, bool $avviso = false): void
    {
        $this->sollecito->update([
            'status' => SollecitoPagamento::STATO_FALLITO,
            'error_message' => $motivo,
        ]);

        $this->avvisa($avviso ? 'Sollecito non inviato' : 'Sollecito di pagamento non inviato', $motivo, riuscito: false);
    }

    private function avvisa(string $titolo, string $corpo, bool $riuscito): void
    {
        $utente = $this->sollecito->user;

        if (! $utente instanceof User) {
            return;
        }

        $notifica = Notification::make()->title($titolo)->body($corpo);

        ($riuscito ? $notifica->success() : $notifica->warning())->sendToDatabase($utente);
    }

    /**
     * Il job morto per strada (timeout, worker ucciso): senza questo il
     * sollecito resterebbe "in corso" per sempre e nessuno saprebbe che
     * non e' partito.
     */
    public function failed(\Throwable $e): void
    {
        $this->fermati('Il lavoro si è interrotto: '.$e->getMessage());
    }
}
