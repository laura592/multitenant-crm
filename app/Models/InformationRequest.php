<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\LogsAuditTrail;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;

class InformationRequest extends Model
{
    use BelongsToTenant, HasUuids, LogsAuditTrail;

    /**
     * Gli stati che dicono due cose insieme: che cosa abbiamo messo sul
     * tavolo (un preventivo, un'offerta di piu' alternative, un noleggio) e
     * com'e' finita (mandato e in attesa, accettato, rifiutato).
     *
     * Prima qui c'erano solo i tre "preventivo_": tutto il resto, deciso o
     * no, finiva su "chiusa", e su 330 richieste 173 stavano in quella
     * parola sola, che non dice se la trattativa l'abbiamo vinta o persa
     * (Laura, 27/11/2026). Li scrive solo il ricalcolo dai documenti
     * collegati: a mano non si scelgono, perche' il risultato sta scritto
     * sul preventivo e questo e' solo il suo riflesso.
     */
    public const DOCUMENT_STATUSES = [
        'preventivo_inviato', 'preventivo_accettato', 'preventivo_rifiutato',
        'offerta_inviata', 'offerta_accettata', 'offerta_rifiutata',
        'noleggio_inviato', 'noleggio_accettato', 'noleggio_rifiutato',
    ];

    /**
     * Le richieste su cui tocca a noi muoverci: non c'e' ancora niente di
     * proposto. E' il conteggio del badge in sidebar e di PrioritaWidget.
     */
    public const DA_GESTIRE = ['nuova', 'in_lavorazione'];

    /**
     * Proposta mandata e nessuna risposta: la palla e' nel loro campo, ma
     * sono anche quelle che una telefonata la meritano piu' di tutte.
     */
    public const IN_ATTESA = ['preventivo_inviato', 'offerta_inviata', 'noleggio_inviato'];

    /** Aperta = c'e' ancora qualcosa da fare, da una parte o dall'altra. */
    public const OPEN_STATUSES = [...self::DA_GESTIRE, ...self::IN_ATTESA];

    /**
     * Gli stati che segue da sola dai documenti collegati. "Gestita" e
     * "Chiusa" restano scelte a mano: una richiesta chiusa non si riapre
     * perche' qualcuno manda un preventivo.
     */
    public const AUTO_STATUSES = [...self::DA_GESTIRE, ...self::DOCUMENT_STATUSES];

    /**
     * Da tipo di proposta ed esito allo stato della richiesta. Le etichette
     * le ha dettate la titolare ("preventivo inviato, preventivo accettato,
     * preventivo rifiutato, uguale per offerte, noleggi"), qui ci sono le
     * chiavi: l'offerta e' femminile, il noleggio maschile.
     */
    private const STATI_PER_PROPOSTA = [
        'preventivo' => ['inviato' => 'preventivo_inviato', 'accettato' => 'preventivo_accettato', 'rifiutato' => 'preventivo_rifiutato'],
        'offerta' => ['inviato' => 'offerta_inviata', 'accettato' => 'offerta_accettata', 'rifiutato' => 'offerta_rifiutata'],
        'noleggio' => ['inviato' => 'noleggio_inviato', 'accettato' => 'noleggio_accettato', 'rifiutato' => 'noleggio_rifiutato'],
    ];

    /**
     * Quale proposta comanda, a pari esito: quella che impegna di piu'.
     *
     * Un noleggio e' un contratto che diventa un canone ogni mese, e se da
     * una richiesta e' nato quello e' quello il titolo. Un'offerta batte il
     * preventivo singolo perche' e' la trattativa come la vede il cliente,
     * con un numero OFF- suo: su RI-2026-0054 ci sono due preventivi
     * sciolti e l'offerta OFF-2026-0001, tutti accettati, e la cosa che si
     * va a cercare e' l'offerta.
     */
    private const TIPI_PER_PESO = ['noleggio', 'offerta', 'preventivo'];

    /**
     * Fra proposte diverse: prima quella che aspetta ancora una risposta.
     *
     * Sono domande diverse, e una senza risposta e' l'unica che da' da fare
     * oggi; fra due decise un si' incassato conta piu' di un no.
     */
    private const ESITI_FRA_PROPOSTE = ['inviato', 'accettato', 'rifiutato'];

    /**
     * Dentro una stessa offerta: un si' vale per tutta la trattativa.
     *
     * Qui il "in attesa prima di tutto" non vale, perche' le alternative
     * sono la stessa domanda fatta in tre modi. Su OFF-2026-0010, 0011 e
     * 0012 il cliente ha scelto un'alternativa e le altre sono finite
     * "rifiutato" di conseguenza: non ha detto no tre volte, ha detto si'
     * una volta.
     */
    private const ESITI_DENTRO_OFFERTA = ['accettato', 'inviato', 'rifiutato'];

    protected $fillable = [
        'tenant_id',
        'customer_id',
        'number',
        'request_details',
        'status',
        'appointment_at',
        'appointment_notes',
        'handled_by_user_id',
        'source',
        'origin_url',
        'raw_payload',
        'external_id',
    ];

    protected $casts = [
        'appointment_at' => 'datetime',
        'raw_payload' => 'array',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $request) {
            if (! $request->number) {
                $request->number = static::nextNumberForTenant($request->tenant_id);
            }
        });
    }

    /**
     * Numerazione scoped per tenant (vedi docs/architecture.md §10.5).
     */
    public static function nextNumberForTenant(?string $tenantId): string
    {
        $year = date('Y');
        $prefix = "RI-{$year}-";

        $last = static::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->whereYear('created_at', $year)
            ->where('number', 'like', "{$prefix}%")
            ->orderByRaw('CAST(SUBSTRING(number, -4) AS UNSIGNED) DESC')
            ->first();

        $next = 1;
        if ($last && preg_match('/-(\d+)$/', $last->number, $matches)) {
            $next = (int) $matches[1] + 1;
        }

        return $prefix.str_pad((string) $next, 4, '0', STR_PAD_LEFT);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function products(): BelongsToMany
    {
        // ->using() non e' cosmetico: senza, sync()/attach() inseriscono con
        // il query builder e la chiave `id` della pivot resta vuota (vedi
        // InformationRequestProduct).
        return $this->belongsToMany(Product::class, 'information_request_product')
            ->using(InformationRequestProduct::class)
            ->withTimestamps();
    }

    /**
     * I preventivi nati da questa richiesta: possono essere piu' d'uno
     * (varianti, rilanci) e, se raggruppati, appartenere a una stessa offerta.
     */
    public function quotes(): HasMany
    {
        return $this->hasMany(Quote::class)->latest('created_at');
    }

    /**
     * I noleggi di questa richiesta.
     *
     * Prima il legame passava solo per il preventivo ("un noleggio nasce da
     * una proposta"), ma non regge: il contratto dell'Hotel Stalon
     * (NOL-2026-0002) e' stato fatto senza preventivo e dalla richiesta non
     * si vedeva (20/10/2026). Ora il noleggio porta scritta la richiesta, e
     * quando nasce da un preventivo se la copia da solo (Noleggio::booted()).
     *
     * @return HasMany<Noleggio, $this>
     */
    public function noleggi(): HasMany
    {
        return $this->hasMany(Noleggio::class)->latest('created_at');
    }

    /**
     * Le proposte attaccate alla richiesta, una riga per trattativa.
     *
     * Si guardano ANCHE i noleggi, non solo i preventivi. Su Oberthaler
     * (RI-2026-0237) la proposta era un noleggio operativo e non un
     * preventivo: la richiesta restava "nuova" anche con l'offerta gia'
     * mandata, perche' qui dentro i noleggi non li leggeva nessuno
     * (Laura, 27/11/2026).
     *
     * @return Collection<int, array{tipo: string, esito: string, numero: string}>
     */
    public function proposteCollegate(): Collection
    {
        /** @var Collection<int, array{tipo: string, esito: string, numero: string}> $proposte */
        $proposte = collect();

        /** @var array<string, array{numero: string, esiti: list<string>}> $offerte */
        $offerte = [];

        // Un preventivo dentro un'offerta NON e' un preventivo singolo: la
        // trattativa e' l'offerta, col suo numero OFF-, e le alternative
        // sono la stessa domanda fatta in due o tre modi.
        //
        // Se l'offerta e' stata cancellata la relazione torna null (soft
        // delete di QuoteGroup) e i preventivi tornano a contare da soli:
        // in produzione ce ne sono appesi a offerte cancellate, ed e' la
        // stessa regola decisa oggi in PreventiviDaSollecitare.
        foreach ($this->quotes()->withoutGlobalScope('tenant')->with('quoteGroup')->get() as $preventivo) {
            /** @var Quote $preventivo */
            $esito = self::esitoDelPreventivo((string) $preventivo->status);
            /** @var QuoteGroup|null $offerta */
            $offerta = $preventivo->quoteGroup;

            if ($offerta === null) {
                $proposte->push(['tipo' => 'preventivo', 'esito' => $esito, 'numero' => (string) $preventivo->number]);

                continue;
            }

            $offerte[(string) $offerta->id]['numero'] = (string) $offerta->number;
            $offerte[(string) $offerta->id]['esiti'][] = $esito;
        }

        foreach ($offerte as $offerta) {
            $proposte->push([
                'tipo' => 'offerta',
                'esito' => self::esitoMigliore($offerta['esiti'], self::ESITI_DENTRO_OFFERTA),
                'numero' => $offerta['numero'],
            ]);
        }

        foreach ($this->noleggi()->withoutGlobalScope('tenant')->get() as $noleggio) {
            $proposte->push([
                'tipo' => 'noleggio',
                'esito' => match ((string) $noleggio->stato) {
                    // Un noleggio attivo o finito e' un si' incassato; uno
                    // rifiutato e' un no detto. Una bozza non e' ancora uscita.
                    Noleggio::STATO_ATTIVO, Noleggio::STATO_CHIUSO => 'accettato',
                    Noleggio::STATO_RIFIUTATO => 'rifiutato',
                    Noleggio::STATO_INVIATO => 'inviato',
                    default => 'bozza',
                },
                'numero' => (string) $noleggio->number,
            ]);
        }

        return $proposte;
    }

    /**
     * Lo stato che dicono i documenti collegati, o null se non c'e' niente
     * attaccato. Con le sole bozze torna "In lavorazione": una proposta non
     * ancora uscita non e' una proposta, ma qualcuno ci sta lavorando.
     *
     * Vince la proposta piu' urgente da guardare (ESITI_FRA_PROPOSTE) e, a
     * pari esito, quella che impegna di piu' (TIPI_PER_PESO).
     */
    public function statoDaiDocumenti(): ?string
    {
        $proposte = $this->proposteCollegate();

        if ($proposte->isEmpty()) {
            return null;
        }

        $decise = $proposte->reject(fn (array $proposta): bool => $proposta['esito'] === 'bozza');

        if ($decise->isEmpty()) {
            return 'in_lavorazione';
        }

        /** @var array{tipo: string, esito: string, numero: string} $vincente */
        $vincente = $decise
            ->sortBy(fn (array $proposta): int => self::ordine($proposta['esito'], self::ESITI_FRA_PROPOSTE) * 10
                + self::ordine($proposta['tipo'], self::TIPI_PER_PESO))
            ->first();

        return self::STATI_PER_PROPOSTA[$vincente['tipo']][$vincente['esito']];
    }

    /**
     * Lo stato che la richiesta dovrebbe avere guardando i suoi documenti, o
     * null se non c'e' niente da dire (stato scelto a mano).
     *
     * Fino a stamattina qui tornava 'chiusa' appena arrivava un si' o un no:
     * il risultato restava scritto sul preventivo e la richiesta diceva solo
     * se c'era ancora qualcosa da fare. Ma l'elenco delle richieste e' il
     * posto da cui si guarda l'andamento, e meta' dell'archivio su "chiusa"
     * non diceva piu' niente a nessuno: adesso ci finisce lo stato vero
     * (Laura, 27/11/2026).
     *
     * 'chiusa' non e' fra gli AUTO_STATUSES: una volta chiusa a mano nessun
     * ricalcolo la riapre. Se il cliente ci ripensa, si riapre a mano.
     * Senza piu' documenti (scollegati o cancellati) torna "In lavorazione"
     * se era avanzata per merito loro, altrimenti resta com'e'.
     */
    public function statusFromQuotes(): ?string
    {
        if (! in_array($this->status, self::AUTO_STATUSES, true)) {
            return null;
        }

        return $this->statoDaiDocumenti()
            ?? (in_array($this->status, self::DOCUMENT_STATUSES, true) ? 'in_lavorazione' : null);
    }

    /**
     * Da stato del preventivo a esito della proposta.
     *
     * "Scaduto" conta come un no: dopo la validita' il cliente non ha detto
     * niente, e per l'azienda e' un no (vedi ScadiPreventivi). Senza questo
     * una trattativa scaduta lasciava la richiesta su "preventivo inviato"
     * per sempre.
     */
    private static function esitoDelPreventivo(string $status): string
    {
        return match ($status) {
            'accettato' => 'accettato',
            'rifiutato', 'scaduto' => 'rifiutato',
            'inviato' => 'inviato',
            default => 'bozza',
        };
    }

    /**
     * Il primo esito secondo l'ordine dato, fra quelli passati.
     *
     * @param  list<string>  $esiti
     * @param  list<string>  $ordinamento
     */
    private static function esitoMigliore(array $esiti, array $ordinamento): string
    {
        usort($esiti, fn (string $uno, string $altro): int => self::ordine($uno, $ordinamento) <=> self::ordine($altro, $ordinamento));

        return $esiti[0] ?? 'bozza';
    }

    /**
     * Posizione nell'ordine di precedenza; quello che non c'e' va in fondo.
     *
     * @param  list<string>  $ordinamento
     */
    private static function ordine(string $valore, array $ordinamento): int
    {
        $posizione = array_search($valore, $ordinamento, true);

        return $posizione === false ? count($ordinamento) : $posizione;
    }

    public function syncStatusFromQuotes(): void
    {
        $status = $this->statusFromQuotes();

        if ($status !== null && $status !== $this->status) {
            $this->update(['status' => $status]);
        }
    }

    public function handledByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'handled_by_user_id');
    }

    /**
     * Gli appuntamenti presi su questa richiesta, dal primo all'ultimo.
     *
     * Ce ne possono essere piu' d'uno aperti insieme; appointment_at tiene
     * il prossimo, per l'elenco e la stampa (sincronizzaProssimoAppuntamento).
     *
     * @return HasMany<InformationRequestAppointment, $this>
     */
    public function appointments(): HasMany
    {
        return $this->hasMany(InformationRequestAppointment::class)->orderBy('scheduled_at');
    }

    /**
     * Riallinea le colonne appointment_at/appointment_notes all'elenco: il
     * prossimo appuntamento futuro, o l'ultimo passato se non ce ne sono
     * piu' davanti. Senza appuntamenti le colonne si svuotano.
     */
    public function sincronizzaProssimoAppuntamento(): void
    {
        $appuntamenti = $this->appointments()->get();

        $prossimo = $appuntamenti->first(fn ($a) => $a->scheduled_at->isFuture())
            ?? $appuntamenti->last();

        $this->forceFill([
            'appointment_at' => $prossimo?->scheduled_at,
            'appointment_notes' => $prossimo?->notes,
        ])->saveQuietly();
    }

    public function notes(): HasMany
    {
        return $this->hasMany(InformationRequestNote::class)->orderByDesc('logged_at');
    }
}
