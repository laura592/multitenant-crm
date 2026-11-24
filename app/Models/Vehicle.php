<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\LogsAuditTrail;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;

class Vehicle extends Model
{
    use BelongsToTenant, HasUuids, LogsAuditTrail;

    /**
     * I documenti che ogni mezzo che circola deve avere in corso.
     *
     * Serve a una domanda diversa da quella dello scadenzario: lo
     * scadenzario ricorda le scadenze CHE CI SONO, questo elenco permette
     * di accorgersi di quelle che MANCANO. Un mezzo senza nessuna
     * assicurazione registrata non e' in regola e nessun promemoria potra'
     * mai avvisare, perche' non c'e' niente da far scadere: senza questo
     * controllo il buco resta invisibile per sempre (Laura, 23/11/2026).
     *
     * Polizza RCT, licenza, contratto e altro restano fuori: sono scadenze
     * dell'azienda o eventuali, non obbligatorie per ogni targa.
     */
    public const TIPI_OBBLIGATORI = [
        Deadline::TYPE_ASSICURAZIONE,
        Deadline::TYPE_BOLLO,
        Deadline::TYPE_REVISIONE,
    ];

    protected $fillable = [
        'tenant_id',
        'plate',
        'brand',
        'model',
        'year',
        'dismesso_il',
        'immatricolato_il',
        'assigned_user_id',
        'notes',
    ];

    protected $casts = [
        'dismesso_il' => 'date',
        'immatricolato_il' => 'date',
    ];

    public function assignedUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_user_id');
    }

    public function deadlines(): MorphMany
    {
        return $this->morphMany(Deadline::class, 'deadlinable');
    }

    /**
     * I documenti che per questo mezzo non esistono, con il perche'.
     *
     * Il bollo del Vito HG747AP e' compreso nel canone di leasing: non e'
     * una scadenza dimenticata, e' una scadenza che non c'e'. Chiederla
     * comunque insegna a chi legge gli avvisi che si possono ignorare, che
     * e' il modo piu' rapido di rendere inutile tutto lo scadenzario.
     */
    public function esenzioniScadenze(): HasMany
    {
        return $this->hasMany(VehicleDeadlineExemption::class);
    }

    public function dismesso(): bool
    {
        return $this->dismesso_il !== null;
    }

    /**
     * Scadenza attiva di un tipo (assicurazione/bollo/revisione) per la
     * vista lista/scheda del veicolo: al piu' una per tipo, le altre sono
     * storico (status "rinnovata"), vedi Deadline::renew().
     */
    public function activeDeadline(string $type): ?Deadline
    {
        $deadline = $this->deadlines
            ->where('type', $type)
            ->where('status', Deadline::STATUS_ATTIVA)
            ->sortByDesc('due_date')
            ->first();

        return $deadline instanceof Deadline ? $deadline : null;
    }

    /**
     * I tipi obbligatori per cui questo mezzo non ha nessuna scadenza
     * ATTIVA.
     *
     * Lo storico non conta, ed e' il punto: un'assicurazione segnata
     * "rinnovata" e' una pratica chiusa, e se nessuno ha inserito quella
     * nuova il mezzo esce da ogni promemoria pur avendo ancora la riga a
     * sistema. E' il caso di FC985CF, che al 23/11/2026 aveva tredici
     * bolli, cinque assicurazioni e due revisioni, tutti "rinnovata", e
     * quindi non compariva in nessun avviso.
     *
     * @return array<int, string>
     */
    public function scadenzeMancanti(): array
    {
        // Un mezzo uscito dalla flotta non deve mettersi in regola: FC985CF,
        // venduto il 23/10/2026, compariva fra gli scoperti con tutte e tre
        // le voci mancanti perche' le sue diciotto scadenze sono storico.
        if ($this->dismesso()) {
            return [];
        }

        $esenti = $this->esenzioniScadenze->pluck('type')->all();

        return array_values(array_filter(
            self::TIPI_OBBLIGATORI,
            fn (string $tipo) => ! in_array($tipo, $esenti, true)
                && $this->activeDeadline($tipo) === null,
        ));
    }

    /**
     * Le etichette leggibili di scadenzeMancanti(), per la dashboard e la
     * mail: "Assicurazione, Revisione" invece di "assicurazione,revisione".
     *
     * @return array<int, string>
     */
    public function etichetteScadenzeMancanti(): array
    {
        $labels = Deadline::typeLabels();

        return array_map(
            fn (string $tipo) => $labels[$tipo] ?? $tipo,
            $this->scadenzeMancanti(),
        );
    }

    public function descrizione(): string
    {
        return trim($this->brand.' '.$this->model) ?: '—';
    }

    /**
     * I mezzi a cui manca almeno un documento obbligatorio.
     *
     * Valutato in SQL e non filtrando in PHP perche' e' l'unico punto di
     * verita' sia per il widget in dashboard (che vuole un Builder da dare
     * alla tabella) sia per il digest settimanale: due elenchi che per lo
     * stesso parco mezzi dicono numeri diversi sono il modo piu' rapido di
     * far smettere alla gente di fidarsi di entrambi — la stessa ragione
     * per cui Deadline::scopeUrgent() esiste.
     *
     * @param  Builder<Vehicle>  $query
     * @return Builder<Vehicle>
     */
    public function scopeConScadenzeMancanti(Builder $query): Builder
    {
        return $query
            ->inFlotta()
            ->where(function (Builder $q) {
                foreach (self::TIPI_OBBLIGATORI as $tipo) {
                    // Manca se non c'e' la scadenza attiva E non c'e'
                    // un'esenzione: le due condizioni insieme, altrimenti il
                    // Vito resta fra gli scoperti per il bollo che sta nel
                    // leasing. Resta tutto in SQL e senza JSON, cosi' vale
                    // identico su MySQL e su sqlite nei test.
                    $q->orWhere(fn (Builder $w) => $w
                        ->whereDoesntHave('deadlines', fn (Builder $d) => $d
                            ->where('type', $tipo)
                            ->where('status', Deadline::STATUS_ATTIVA))
                        ->whereDoesntHave('esenzioniScadenze', fn (Builder $e) => $e
                            ->where('type', $tipo)));
                }
            });
    }

    /**
     * I mezzi ancora in uso. Tutto cio' che chiede "e' in regola?", "va
     * avvisato?", "quanti mezzi abbiamo?" parte da qui: un venduto non e'
     * cancellato (la sua storia di bolli e revisioni serve), ma non e'
     * nemmeno piu' un mezzo dell'azienda.
     *
     * @param  Builder<Vehicle>  $query
     * @return Builder<Vehicle>
     */
    public function scopeInFlotta(Builder $query): Builder
    {
        return $query->whereNull('dismesso_il');
    }
}
