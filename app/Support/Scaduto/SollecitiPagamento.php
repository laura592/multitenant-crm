<?php

namespace App\Support\Scaduto;

use App\Filament\Forms\Components\AllegatiEmail;
use App\Jobs\InviaSollecitoPagamentoJob;
use App\Mail\SollecitoPagamentoMail;
use App\Models\Customer;
use App\Models\EurekaPartitaAperta;
use App\Models\SollecitoPagamento;
use App\Models\Tenant;
use App\Support\DisplayName;
use App\Support\Gestionale\EurekaClient;
use Filament\Facades\Filament;
use Filament\Forms;
use Filament\Notifications\Notification;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\HtmlString;

/**
 * Il promemoria di pagamento: le fatture scadute di un cliente, allegate e
 * mandate per mail.
 *
 * Gemello di App\Support\Preventivi\Solleciti — stessa forma (documento,
 * destinatario, formSchema, invia, registra), due regole diverse, e sono
 * proprio le differenze che vale la pena scrivere:
 *
 *  - NESSUN TETTO al numero di solleciti. Sui preventivi dopo due volte si
 *    smette ("non posso tormentarli per sempre"). Su un credito no: non si
 *    smette di chiedere i propri soldi perche' si e' gia' chiesto due
 *    volte.
 *
 *  - IL DESTINATARIO NON E' IL PAGANTE. Vedi destinatario().
 *
 * Quello che resta uguale e' la cosa piu' importante: la mail la manda una
 * persona premendo un pulsante. Nessun cron sollecita da solo, e men che
 * meno su dei soldi.
 */
class SollecitiPagamento
{
    /**
     * I clienti gia' risolti in questa richiesta.
     *
     * L'elenco dello scaduto chiede "si puo' sollecitare?" per ogni riga a
     * schermo, e ogni domanda passa di qui: senza memoria sarebbero tre
     * query per riga solo per decidere se il pulsante e' acceso.
     *
     * @var array<int, Customer|null>
     */
    private static array $clienti = [];

    /** Le fatture allegabili gia' calcolate in questa richiesta. @var array<int, array<string, FatturaDaAllegare>> */
    private static array $fatture = [];

    /**
     * Le partite scadute a debito di un'anagrafica: esattamente quelle che
     * l'elenco somma nella colonna "Scaduto" (vedi ScadutoClienti), nello
     * stesso ordine in cui il dettaglio le mostra.
     *
     * Le note di credito restano fuori: una richiesta di pagamento con
     * dentro un documento a nostro debito e' una contraddizione, e il
     * cliente che la riceve ha buon gioco a rispondere che i conti li
     * facciamo male. Restano fuori anche le partite non ancora scadute: un
     * promemoria su una fattura che scade fra due settimane e' solo un modo
     * di far innervosire chi e' in regola.
     *
     * @return Collection<int, EurekaPartitaAperta>
     */
    public static function partite(int $codice): Collection
    {
        return EurekaPartitaAperta::query()
            ->where('tenant_id', self::tenantId())
            ->where('tipo', EurekaPartitaAperta::TIPO_CLIENTE)
            ->where('gestionale_code', $codice)
            ->where('saldo', '>', 0)
            // COALESCE come nell'elenco: un riporto di apertura non ha
            // scadenza, vale la sua data (01/01/2023), che e' passata da un
            // pezzo.
            ->whereRaw('COALESCE(data_scadenza, data_fattura) < ?', [now()->toDateString()])
            ->orderBy('data_scadenza')
            ->orderBy('data_fattura')
            ->get();
    }

    /**
     * Le fatture che si possono davvero allegare, per id di partita.
     *
     * Solo archivio locale: qui si sta dentro una richiesta Livewire (il
     * modulo d'invio), e il recupero dal vivo da Eureka lo fa la coda —
     * vedi FatturaDaAllegare::perPartite().
     *
     * @return array<string, FatturaDaAllegare>
     */
    public static function fatture(int $codice): array
    {
        return self::$fatture[$codice] ??= FatturaDaAllegare::perPartite(self::partite($codice));
    }

    /**
     * La scheda CRM dell'anagrafica Eureka, se esiste.
     *
     * Quattro delle anagrafiche con scaduto non ce l'hanno (Castello di
     * Roncade, Caffea, Vesuvio): sono clienti del gestionale che nel CRM
     * non sono mai stati creati. Per loro non c'e' nessun indirizzo da
     * precompilare, ed e' uno dei motivi per cui il pulsante si spegne.
     */
    public static function cliente(int $codice): ?Customer
    {
        return self::$clienti[$codice] ??= Customer::query()
            ->where('tenant_id', self::tenantId())
            ->where('gestionale_code', $codice)
            ->first();
    }

    public static function nome(int $codice): string
    {
        $cliente = self::cliente($codice);

        $nome = $cliente?->company_name
            ?: $cliente?->full_name
            ?: EurekaPartitaAperta::query()
                ->where('tenant_id', self::tenantId())
                ->where('gestionale_code', $codice)
                ->value('ragione_sociale');

        return DisplayName::titleCase($nome) ?: "Anagrafica {$codice}";
    }

    /**
     * A CHI SI SCRIVE: all'anagrafica a cui la fattura e' INTESTATA, mai a
     * chi paga al posto suo.
     *
     * Qui invoiceRecipient() NON si usa, ed e' una regola diversa da quella
     * dei rapportini e dei preventivi. La partita aperta sta sull'anagrafica
     * intestataria del documento: il pagante, quando c'e', e' un
     * torrefattore che si e' accollato le macchine, non il debitore di
     * quella fattura.
     *
     * Oggi (27/11/2026) seguire il pagante vorrebbe dire mandare cinque
     * solleciti a Dersut, Goppion e Caffe' Pavin per fatture che non hanno
     * mai visto — Bar Miki, Hotel Neps, Trattoria Mirafiori, Pasticceria
     * Luciana, M.&B. — chiedendo a tre aziende terze soldi che non devono.
     * Non e' un dettaglio tecnico: e' il modo piu' rapido di rovinare tre
     * rapporti commerciali in una mattina.
     */
    public static function destinatario(int $codice): ?string
    {
        return self::cliente($codice)?->primaryEmail();
    }

    /**
     * L'indirizzo, se quello che c'e' scritto e' un indirizzo solo.
     *
     * Stessa ragione di Solleciti::unIndirizzo sui preventivi: in
     * anagrafica alcuni clienti hanno due mail nello stesso campo. Fra chi
     * ha scaduto c'e' Hotel Bristol, con
     * "info@bristol-hotel.it; contabilita@bristol-hotel.it": precompilare
     * quel valore vuol dire aprire il modulo con un campo che la sua stessa
     * validazione rifiuta, e scoprirlo dopo aver scritto la mail. Meglio il
     * campo vuoto e il valore sporco scritto sotto, da cui copiare.
     */
    private static function unIndirizzo(?string $valore): ?string
    {
        $valore = trim((string) $valore);

        return $valore !== '' && filter_var($valore, FILTER_VALIDATE_EMAIL) ? $valore : null;
    }

    /**
     * Perche' NON si puo' sollecitare questo cliente per mail, in una frase
     * da mettere sul pulsante spento. NULL quando invece si puo'.
     *
     * Si spegne prima, non dopo: un errore che arriva quando l'utente ha
     * gia' scritto la mail e premuto invio fa perdere il testo e la
     * fiducia.
     *
     * $allegabili si passa quando lo sa gia' chi chiama (l'elenco dello
     * scaduto lo calcola per tutte le righe in una query sola): ricontarlo
     * riga per riga vorrebbe dire una query per fattura per riga a schermo.
     *
     * Torna due testi: "breve" finisce sul pulsante al posto di "Sollecita",
     * "perche" nel suggerimento. Il pulsante spento e muto fa ricaricare la
     * pagina per capire se era un problema di Internet.
     *
     * @return array{breve: string, perche: string}|null
     */
    public static function impossibile(int $codice, ?int $allegabili = null): ?array
    {
        $allegabili ??= count(self::fatture($codice));

        if ($allegabili === 0) {
            // E' il caso dei tredici clienti che hanno solo riporti di
            // apertura del 01/01/2023 (27.245,27 EUR in tutto): un credito
            // vero, ma senza nessun documento da mettere in allegato. Si
            // telefona. Un estratto conto costruito da noi qui non si
            // inventa: e' una cosa da decidere insieme a chi firma.
            return [
                'breve' => 'Niente da allegare',
                'perche' => 'Non c\'è nessuna fattura da allegare: lo scaduto è fatto di riporti di apertura o di documenti che Eureka non espone. Meglio una telefonata.',
            ];
        }

        if (! self::cliente($codice) instanceof Customer) {
            return [
                'breve' => 'Non è in anagrafica',
                'perche' => 'Questa anagrafica esiste su Eureka ma non nel CRM: non c\'è una scheda da cui prendere l\'indirizzo.',
            ];
        }

        if (self::destinatario($codice) === null) {
            return [
                'breve' => 'Manca l\'indirizzo',
                'perche' => 'Il cliente non ha nessun indirizzo email in anagrafica. Si può aggiungere dalla sua scheda.',
            ];
        }

        return null;
    }

    /** Quando e' partito l'ultimo sollecito riuscito, se e' mai partito. */
    public static function ultimoSollecito(int $codice): ?SollecitoPagamento
    {
        return SollecitoPagamento::query()
            ->where('tenant_id', self::tenantId())
            ->where('gestionale_code', $codice)
            ->where('status', SollecitoPagamento::STATO_INVIATO)
            ->latest('created_at')
            ->first();
    }

    /**
     * Il modulo d'invio: prima cosa si sta chiedendo, poi a chi, poi cosa
     * si scrive.
     *
     * Il riepilogo sta in cima e non in fondo perche' e' l'unica parte che
     * non si puo' correggere dopo: l'indirizzo si rilegge, il testo si
     * riscrive, ma gli importi vengono dal gestionale e o tornano o si
     * chiude la finestra.
     *
     * @return array<int, Forms\Components\Component>
     */
    public static function formSchema(int $codice): array
    {
        $fatture = self::fatture($codice);

        return [
            Forms\Components\Placeholder::make('riepilogo')
                ->label('Cosa stai chiedendo')
                ->content(fn (): HtmlString => self::riepilogo($codice))
                ->columnSpanFull(),

            AllegatiEmail::make(array_map(
                fn (FatturaDaAllegare $f): string => $f->nomeFile(),
                array_values($fatture),
            )),

            Forms\Components\TextInput::make('recipient_email')
                ->label('Email destinatario')
                ->email()
                ->required()
                ->default(fn (): ?string => self::unIndirizzo(self::destinatario($codice)))
                ->helperText(function () use ($codice): ?string {
                    $trovato = self::destinatario($codice);

                    return $trovato && ! self::unIndirizzo($trovato)
                        ? 'In anagrafica c\'è scritto: '.$trovato
                        : null;
                }),

            Forms\Components\TextInput::make('cc_email')
                ->label('CC (facoltativo)')
                ->email()
                ->helperText(fn (): ?string => ($fissi = self::inCopiaSempre()) !== []
                    ? 'In copia anche: '.implode(', ', $fissi)
                    : null),

            Forms\Components\TextInput::make('subject')
                ->label('Oggetto')
                ->required()
                ->maxLength(255)
                ->default(fn (): string => SollecitoPagamentoMail::oggettoPredefinito($fatture)),

            Forms\Components\RichEditor::make('custom_message')
                ->label('Testo')
                ->toolbarButtons(['bold', 'italic', 'bulletList', 'orderedList', 'link', 'undo', 'redo'])
                ->default(fn (): string => SollecitoPagamentoMail::corpoPredefinito($fatture)),
        ];
    }

    /**
     * Il riquadro in cima al modulo: quante fatture, quali, per quanto, e
     * soprattutto che cosa NON parte.
     *
     * La riga sulle partite escluse e' quella che conta davvero. Su Santa
     * Sofia Venezia lo scaduto a schermo e' 6.938,64 ma allegabili sono
     * cinque partite su sei: senza quella riga chi manda crede di aver
     * chiesto tutto, e al telefono si trova il cliente che gli legge un
     * numero diverso.
     */
    private static function riepilogo(int $codice): HtmlString
    {
        $fatture = self::fatture($codice);
        $partite = self::partite($codice);

        $chieste = array_sum(array_map(fn (FatturaDaAllegare $f): float => $f->residuo(), $fatture));
        $scaduto = (float) $partite->sum('saldo');
        $fuori = round($scaduto - $chieste, 2);

        $righe = ['<div style="font-weight:600;">'
            .count($fatture).' '.(count($fatture) === 1 ? 'fattura' : 'fatture')
            .' per '.self::euro($chieste).'</div>', ];

        foreach ($fatture as $fattura) {
            $righe[] = '<div style="opacity:.8;">n. '.e($fattura->numero)
                .($fattura->data ? ' del '.$fattura->data->format('d/m/Y') : '')
                .' — '.self::euro($fattura->residuo())
                .($fattura->parziale() ? ' (residuo di '.self::euro($fattura->totale).')' : '')
                .'</div>';
        }

        if ($fuori > 0) {
            $righe[] = '<div style="margin-top:.35rem;color:#b45309;">Restano fuori '
                .self::euro($fuori).': riporti di apertura o documenti che Eureka non espone. '
                .'La mail non li nomina.</div>';
        }

        if ($ultimo = self::ultimoSollecito($codice)) {
            $righe[] = '<div style="margin-top:.35rem;opacity:.7;">Ultimo sollecito il '
                .$ultimo->created_at?->format('d/m/Y').' a '.e($ultimo->recipient_email).'.</div>';
        }

        return new HtmlString(implode('', $righe));
    }

    private static function euro(float $valore): string
    {
        return '€ '.number_format($valore, 2, ',', '.');
    }

    /**
     * L'azienda di chi sta guardando, con il tipo dichiarato:
     * Filament::getTenant() e' tipizzato ?Model, e un ?->id non basterebbe
     * a dire all'analisi statica che quel Model ha un id.
     */
    private static function tenantId(): ?string
    {
        $tenant = Filament::getTenant();

        return $tenant instanceof Tenant ? $tenant->id : null;
    }

    /**
     * Chi riceve copia di ogni sollecito di pagamento.
     *
     * Come sui preventivi: una mail che esce verso un cliente senza lasciare
     * copia in ufficio non esiste per chi risponde al telefono il giorno
     * dopo. Qui pesa doppio, perche' la risposta tipica a un sollecito e'
     * un bonifico o una contestazione, e chi la riceve deve sapere di che
     * cosa si parla.
     *
     * Il tenant si passa quando non c'e' un pannello sotto: la coda gira
     * senza richiesta, e Filament::getTenant() li' non sa niente.
     *
     * @return array<int, string>
     */
    public static function inCopiaSempre(?Tenant $tenant = null): array
    {
        $tenant ??= Filament::getTenant();

        return $tenant instanceof Tenant ? $tenant->notificationRecipients('sollecito_pagamento') : [];
    }

    /**
     * Registra il sollecito e lo mette in coda.
     *
     * NON manda niente da qui: nove fatture sono nove PDF da scaricare da
     * Eureka, cioe' nove round-trip verso un servizio che ogni tanto
     * risponde 500 per qualche minuto di fila. Dentro una richiesta
     * Livewire significherebbe una finestra che resta ferma trenta secondi
     * e ogni tanto muore a meta'.
     *
     * La riga nasce "in coda" e la scrive questa richiesta, non il job:
     * cosi' se la coda e' ferma resta comunque la traccia di un sollecito
     * che qualcuno ha chiesto di mandare.
     *
     * @param  array<string, mixed>  $dati
     */
    public static function invia(int $codice, array $dati): void
    {
        $fatture = self::fatture($codice);

        // Ultimo controllo prima di scrivere: fra l'apertura del modulo e
        // l'invio puo' essere passato un import.
        if ($fatture === []) {
            Notification::make()
                ->title('Niente da allegare')
                ->body('Per questo cliente non risulta più nessuna fattura scaduta da allegare.')
                ->warning()
                ->send();

            return;
        }

        $cliente = self::cliente($codice);

        $sollecito = SollecitoPagamento::create([
            'tenant_id' => self::tenantId(),
            'gestionale_code' => $codice,
            'customer_id' => $cliente?->id,
            'user_id' => Auth::id(),
            'recipient_email' => $dati['recipient_email'],
            'cc_email' => $dati['cc_email'] ?? null,
            'subject' => $dati['subject'],
            'message' => $dati['custom_message'] ?? null,
            'totale_sollecitato' => array_sum(array_map(fn (FatturaDaAllegare $f): float => $f->residuo(), $fatture)),
            'partite' => self::fotografia($codice, $fatture),
            'status' => SollecitoPagamento::STATO_IN_CODA,
        ]);

        InviaSollecitoPagamentoJob::dispatch($sollecito);

        Notification::make()
            ->title('Sollecito in preparazione')
            ->body('Scarico le fatture da Eureka e mando a '.$dati['recipient_email'].'. Ti avviso qui quando è partito.')
            ->success()
            ->send();
    }

    /**
     * La fotografia delle partite scadute di adesso: tutte, anche quelle
     * che non si possono allegare, con il motivo.
     *
     * Serve a rispondere fra sei mesi a "che cosa gli avevamo chiesto?",
     * quando le partite di oggi non esisteranno piu' (si chiudono appena il
     * cliente paga) e nessuna query potra' piu' ricostruirlo.
     *
     * @param  array<string, FatturaDaAllegare>  $fatture
     * @return array<int, array<string, mixed>>
     */
    private static function fotografia(int $codice, array $fatture): array
    {
        return self::partite($codice)->map(function (EurekaPartitaAperta $partita) use ($fatture): array {
            if ($fattura = $fatture[$partita->id] ?? null) {
                return $fattura->perIlRegistro();
            }

            return [
                'anno' => (int) $partita->anno,
                'numero' => $partita->numero_fattura,
                'data' => $partita->data_fattura?->toDateString(),
                'scadenza' => $partita->data_scadenza?->toDateString(),
                'totale' => null,
                'residuo' => round((float) $partita->saldo, 2),
                'id_b10' => null,
                'allegata' => false,
            ];
        })->values()->all();
    }

    /**
     * L'ultimo controllo prima di mandare: una sola chiamata a Eureka sul
     * codice del cliente, per vedere se i soldi che stiamo per chiedere
     * sono ancora dovuti.
     *
     * Le partite in archivio sono di stamattina o delle 14:00, e un
     * bonifico arrivato ieri pomeriggio non si vede. Sollecitare chi ha
     * appena pagato non e' un errore di contabilita': e' una figuraccia che
     * costa un rapporto, e in mano al cliente resta una mail con nove
     * fatture che lui ha gia' saldato.
     *
     * Torna NULL se si puo' mandare, altrimenti la frase da mostrare a chi
     * aveva premuto il pulsante. Se Eureka non risponde affatto si manda lo
     * stesso: un gestionale muto non e' una prova che il cliente abbia
     * pagato, e bloccare tutto per ogni 500 vorrebbe dire non sollecitare
     * piu' nessuno nei giorni in cui Eureka fa i capricci.
     *
     * @param  array<string, FatturaDaAllegare>  $fatture
     */
    public static function nonPiuDovuto(EurekaClient $client, int $codice, array $fatture): ?string
    {
        $vive = $client->partiteAperte([$codice])[$codice] ?? null;

        if (! is_array($vive) || $vive === []) {
            return null;
        }

        // Chiave anno/numero come la porta Eureka, senza zeri iniziali.
        $saldi = [];

        foreach ($vive as $partita) {
            if (! is_array($partita)) {
                continue;
            }

            $saldi[(int) ($partita['anno'] ?? 0).'/'.(int) ($partita['numero_fattura'] ?? 0)] = round((float) ($partita['saldo_partita'] ?? 0), 2);
        }

        foreach ($fatture as $fattura) {
            $chiave = (int) $fattura->partita->anno.'/'.(int) $fattura->numero;
            $adesso = $saldi[$chiave] ?? 0.0;

            // Si confronta solo al RIBASSO: se il cliente deve piu' di
            // prima non c'e' niente da fermare, il sollecito chiede meno
            // del dovuto e va benissimo cosi'.
            if ($adesso < $fattura->residuo() - 0.01) {
                return 'La fattura '.$fattura->numero.' del '.($fattura->data?->format('d/m/Y') ?? '—')
                    .' su Eureka adesso risulta '
                    .($adesso <= 0.0 ? 'saldata' : 'scoperta per € '.number_format($adesso, 2, ',', '.'))
                    .', mentre il sollecito chiedeva € '.number_format($fattura->residuo(), 2, ',', '.')
                    .'. Non ho mandato niente: ricontrolla lo scaduto e, se serve, rifallo.';
            }
        }

        return null;
    }
}
