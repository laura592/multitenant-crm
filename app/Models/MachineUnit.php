<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\LogsAuditTrail;
use App\Support\Gestionale\RegistroSync;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Un macchinario fisico con matricola, tracciato indipendentemente da dove si
 * trova fisicamente in questo momento (current_customer_id, es. un bar
 * diverso) e da chi paga (billing_customer_id, se diverso da chi lo ospita).
 * Lo storico degli spostamenti vive in MachineUnitPlacement — vedi moveTo().
 */
class MachineUnit extends Model
{
    use BelongsToTenant, HasUuids, LogsAuditTrail, SoftDeletes;

    public const STATUS_IN_MAGAZZINO = 'in_magazzino';

    public const STATUS_INSTALLATA = 'installata';

    public const STATUS_RIMOSSA = 'rimossa';

    public const SOURCE_MANUALE = 'manuale';

    public const SOURCE_EUREKA = 'eureka';

    public const TYPE_COLONNA_SPINA = 'colonna_spina';

    public const TYPE_IMPIANTO_ACQUA = 'impianto_acqua';

    protected $fillable = [
        'maintenance_code',
        'tenant_id',
        'source',
        'product_id',
        'material_id',
        'current_customer_id',
        'billing_customer_id',
        'serial_number',
        'model_name',
        'type',
        'status',
        'notes',
        'gestionale_code',
        'gestionale_suggested_code',
        'gestionale_suggested_label',
        'eureka_billing_customer_code',
        'fusione_suggerita_id',
        'fusione_suggerita_motivo',
        'fusa_in_id',
        'spostamento_suggerito_customer_id',
        'spostamento_suggerito_il',
        'spostamento_suggerito_motivo',
        'spostamento_suggerito_pagante_code',
        'spostamento_scartato',
    ];

    protected $casts = [
        'spostamento_suggerito_il' => 'date',
    ];

    protected $attributes = [
        'status' => self::STATUS_IN_MAGAZZINO,
    ];

    protected static function booted(): void
    {
        // La FK cascadeOnDelete() del DB non scatta piu' su un soft delete
        // (e' un UPDATE, non una DELETE): replichiamo la cascata a mano sullo
        // storico posizionamenti.
        static::deleting(function (self $unit) {
            $unit->placements->each->delete();
        });

        // Chi paga e' della posizione attuale: se cambia sulla macchina (dal
        // form, o da eureka:apply-machine-billing-payer) cambia anche li',
        // e lo storico resta giusto.
        static::updated(function (self $unit) {
            // Solo i campi cambiati davvero: il sync carica la macchina con
            // poche colonne, e copiare anche quelle non lette azzerava il
            // pagante della posizione.
            $cambiati = array_intersect_key($unit->getChanges(), array_flip(['billing_customer_id', 'eureka_billing_customer_code']));

            if ($cambiati !== []) {
                $unit->placements()->whereNull('removed_at')->update($cambiati);
            }
        });
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /**
     * L'articolo Eureka da cui nasce questa matricola: e' la stessa anagrafica
     * che il rapportino referenzia in machine_material_id. product() resta per
     * le macchine a listino, quelle che vendiamo e configuriamo a preventivo.
     */
    public function material(): BelongsTo
    {
        return $this->belongsTo(Material::class);
    }

    public function currentCustomer(): BelongsTo
    {
        return $this->belongsTo(Customer::class, 'current_customer_id');
    }

    public function billingCustomer(): BelongsTo
    {
        return $this->belongsTo(Customer::class, 'billing_customer_id');
    }

    public function placements(): HasMany
    {
        return $this->hasMany(MachineUnitPlacement::class)->latest('placed_at');
    }

    public function getDisplayNameAttribute(): string
    {
        return $this->product?->name
            ?? $this->material?->display_label
            ?? $this->model_name
            ?? 'Macchina senza modello';
    }

    /**
     * Chiude l'eventuale posizionamento aperto e ne apre uno nuovo (o
     * nessuno, se $customer e' null = rientro in magazzino/rimozione),
     * mantenendo lo storico invece di sovrascrivere current_customer_id e
     * basta. $placedAt di default e' now() (spostamento fatto ora dal
     * tecnico), ma un import storico (es. da Eureka, dove la data vera e'
     * quella del DDT di consegna/installazione) puo' passare la data reale
     * invece di intestare tutto a "oggi".
     */
    /**
     * Chi paga va con la posizione: senza $pagante la macchina nella nuova
     * posizione la paga il cliente stesso (o chi paga per lui, vedi
     * Customer::invoiceRecipient()). Prima restava il pagante della
     * posizione di prima.
     */
    public function moveTo(?Customer $customer, ?string $notes = null, ?\DateTimeInterface $placedAt = null, ?Customer $pagante = null, ?int $codicePaganteEureka = null): void
    {
        $paganteId = $customer && $pagante && $pagante->id !== $customer->id ? $pagante->id : null;

        $quando = $placedAt ? Carbon::instance($placedAt) : now();

        // La posizione vecchia si chiude nello stesso istante in cui si apre
        // la nuova: uno spostamento registrato a posteriori ("ritirata il
        // 29/10/2024") non deve risultare chiuso oggi (22/09/2026). Mai
        // prima di quando era cominciata.
        $this->placements()->whereNull('removed_at')->get()->each(
            fn (MachineUnitPlacement $aperta) => $aperta->update(['removed_at' => $quando->max($aperta->placed_at)])
        );

        if ($customer) {
            $this->placements()->create([
                'tenant_id' => $this->tenant_id,
                'customer_id' => $customer->id,
                'billing_customer_id' => $paganteId,
                'eureka_billing_customer_code' => $customer ? $codicePaganteEureka : null,
                'placed_at' => $quando,
                'notes' => $notes,
            ]);
        }

        $this->update([
            'current_customer_id' => $customer?->id,
            'billing_customer_id' => $paganteId,
            'eureka_billing_customer_code' => $customer ? $codicePaganteEureka : null,
            'status' => $customer ? self::STATUS_INSTALLATA : self::STATUS_IN_MAGAZZINO,
        ]);
    }

    /**
     * Annulla l'ultimo "Sposta" fatto per sbaglio: toglie il posizionamento
     * appena aperto (o, se la macchina era stata rimandata in magazzino,
     * niente) e riapre quello che lo spostamento aveva chiuso, come se lo
     * spostamento non ci fosse mai stato. Lo storico resta nell'audit.
     *
     * Solo l'ultimo: annullarne uno piu' vecchio riscriverebbe una storia
     * su cui nel frattempo possono essere nati rapportini e lavaggi.
     */
    public function undoLastMove(): bool
    {
        $open = $this->placements()->whereNull('removed_at')->latest('placed_at')->first();

        // Il posizionamento che lo spostamento ha chiuso: moveTo() chiude e
        // apre nello stesso istante. Se non ce n'e' uno chiuso in quel
        // momento la macchina veniva dal magazzino, e li' deve tornare: non
        // si riapre un cliente di prima del magazzino.
        $closed = $this->placements()
            ->whereNotNull('removed_at')
            ->when($open, fn ($q) => $q->whereBetween('removed_at', [
                $open->placed_at->copy()->subMinute(),
                $open->placed_at->copy()->addMinute(),
            ]))
            ->latest('removed_at')
            ->first();

        if (! $open && ! $closed) {
            return false;
        }

        DB::transaction(function () use ($open, $closed) {
            $open?->delete();
            $closed?->update(['removed_at' => null]);

            $this->update([
                'current_customer_id' => $closed?->customer_id,
                'billing_customer_id' => $closed?->billing_customer_id,
                'eureka_billing_customer_code' => $closed?->eureka_billing_customer_code,
                'status' => $closed?->customer_id ? self::STATUS_INSTALLATA : self::STATUS_IN_MAGAZZINO,
            ]);
        });

        return true;
    }

    /**
     * Si annulla solo uno spostamento recente (30 giorni): serve a
     * correggere un errore appena fatto, non a riscrivere posizionamenti
     * vecchi o importati da Eureka con la data del DDT.
     */
    public function canUndoLastMove(): bool
    {
        $lastMove = collect([
            $this->placements()->whereNull('removed_at')->max('placed_at'),
            $this->placements()->max('removed_at'),
        ])->filter()->max();

        return $lastMove !== null && Carbon::parse($lastMove)->gt(now()->subDays(30));
    }

    /**
     * Accetta la proposta di collegamento trovata dal sync
     * (GestionaleSyncRunner::proposeMachineUnitLinks()).
     *
     * Marca la macchina come proveniente da Eureka invece di scrivere
     * gestionale_code: la proposta nasce da /show/q/art_installati, che
     * espone l'id dell'ARTICOLO e non l'id matricola (M14) che quella colonna
     * contiene ("id m14 (matricola) su Eureka", vedi la migration). L'id
     * matricola si leggeva da /crm_api/m14/search, che dal 2026-08-27
     * risponde 403 perche' il modulo `crm` non e' abilitato sulle nostre
     * credenziali. Scriverci dentro l'id articolo sarebbe un dato sbagliato
     * in una colonna dal significato preciso.
     *
     * source=eureka e' comunque il segnale che conta davvero: e' quello a
     * decidere l'invio di sl_matricola (ServiceReport::toGestionalePayload())
     * e chi legge gestionale_code lo tratta gia' come equivalente
     * (MachineUnitResource::table(), ServiceReportResource::isMachineUnitLinkedToEureka()).
     *
     * Il prodotto si collega solo se non ce n'e' gia' uno: la proposta porta
     * l'articolo Eureka della matricola, utile per le macchine importate
     * senza prodotto, ma non deve sovrascrivere una scelta gia' fatta a mano.
     */
    public function confermaCollegamentoEureka(): void
    {
        $product = $this->product_id === null && $this->gestionale_suggested_code !== null
            ? Product::query()->where('gestionale_code', $this->gestionale_suggested_code)->first()
            : null;

        $this->update([
            'source' => self::SOURCE_EUREKA,
            'product_id' => $product->id ?? $this->product_id,
            'gestionale_suggested_code' => null,
            'gestionale_suggested_label' => null,
        ]);
    }

    /**
     * Chiave di confronto fra matricole.
     *
     * Eureka scrive la stessa matricola in forme diverse — "BRL 003 020002113218"
     * per un cliente e "BRL003020002113218" per un altro, "-0819352" e "0819352" —
     * e confrontarle alla lettera crea due macchine per un solo apparecchio
     * (successo davvero nell'import degli installati del 02/09/2026).
     * Spazi, trattini e punti non portano informazione in un numero di serie.
     */
    public static function chiaveMatricola(?string $matricola): string
    {
        $matricola = preg_replace('/[\s\-.\/]+/u', '', (string) $matricola);

        return mb_strtolower(trim((string) $matricola));
    }

    /**
     * La macchina viva in cui questa e' confluita, seguendo le fusioni a
     * catena. Null se questa non e' stata fusa.
     */
    public function superstiteFusione(): ?self
    {
        $corrente = $this;
        $visti = [];

        while ($corrente->fusa_in_id && ! isset($visti[$corrente->id])) {
            $visti[$corrente->id] = true;
            $corrente = self::withTrashed()->find($corrente->fusa_in_id);

            if (! $corrente) {
                return null;
            }
        }

        return $corrente->is($this) || $corrente->trashed() ? null : $corrente;
    }

    /**
     * Dopo una fusione che ha tenuto la matricola che Eureka non usa: questa
     * macchina (la tenuta) prende la matricola della fusa, e la fusa, che
     * resta archiviata, prende la sua. Id, storico e rapportini non si
     * muovono; cambia solo quale scrittura porta la macchina viva.
     *
     * Lo scambio passa da un valore provvisorio: tenant+matricola e' unico
     * anche fra le archiviate.
     */
    public function scambiaMatricolaCon(self $fusa): void
    {
        if ($fusa->fusa_in_id !== $this->id || ! $fusa->trashed()) {
            throw new \LogicException('Si scambia la matricola solo con una macchina fusa in questa.');
        }

        DB::transaction(function () use ($fusa) {
            $mia = $this->serial_number;
            $sua = $fusa->serial_number;

            self::withoutEvents(fn () => $fusa->forceFill(['serial_number' => '~scambio~'.$fusa->id])->save());
            $this->update(['serial_number' => $sua]);
            self::withoutEvents(fn () => $fusa->forceFill(['serial_number' => $mia])->save());
        });

        RegistroSync::movimento('macchine', 'matricola presa da Eureka', [
            'prima' => $fusa->serial_number,
            'ora' => $this->serial_number,
        ]);
    }

    /** La macchina che il sync propone di assorbire in questa. */
    public function fusioneSuggerita(): BelongsTo
    {
        return $this->belongsTo(self::class, 'fusione_suggerita_id')->withTrashed();
    }

    /**
     * Assorbe l'altra macchina in questa: rapportini, collocazioni e storico
     * passano di qua, e l'altra viene archiviata.
     *
     * Si tiene QUESTA perche' e' la piu' attendibile (vedi
     * ConfrontoMacchine::proposte()), ma non si butta via quello che l'altra
     * sapeva: modello e codice gestionale riempiono i vuoti di qui, mai il
     * contrario. Il soft delete lascia la strada per tornare indietro.
     */
    public function assorbe(self $altra): void
    {
        if ($altra->is($this)) {
            return;
        }

        DB::transaction(function () use ($altra) {
            // Una consegna che questa macchina ha gia' (stesso cliente, stessa
            // data) non si copia: l'import degli installati crea le due
            // macchine dalla stessa bolla, e spostarle tutte raddoppiava lo
            // storico. Si tiene quella di qui, completata con il pagante
            // dell'altra se qui mancava; il doppione se ne va con l'altra.
            foreach ($altra->placements()->get() as $sua) {
                $mia = $this->placements()
                    ->where('customer_id', $sua->customer_id)
                    ->where('placed_at', $sua->placed_at)
                    ->first();

                if (! $mia) {
                    $sua->update(['machine_unit_id' => $this->id]);

                    continue;
                }

                $mia->update(array_filter([
                    'billing_customer_id' => $mia->billing_customer_id ?? $sua->billing_customer_id,
                    'eureka_billing_customer_code' => $mia->eureka_billing_customer_code ?? $sua->eureka_billing_customer_code,
                ], fn ($v) => $v !== null));
            }

            foreach ([
                ['service_reports', 'machine_unit_id'],
                ['maintenance_schedules', 'machine_unit_id'],
            ] as [$tabella, $colonna]) {
                if (Schema::hasColumn($tabella, $colonna)) {
                    DB::table($tabella)
                        ->where($colonna, $altra->id)
                        ->update([$colonna => $this->id]);
                }
            }

            // I vuoti si riempiono, i valori esistenti non si toccano.
            $this->fill(array_filter([
                'model_name' => trim((string) $this->model_name) === '' ? $altra->model_name : null,
                'gestionale_code' => $this->gestionale_code === null ? $altra->gestionale_code : null,
                'product_id' => $this->product_id === null ? $altra->product_id : null,
                'current_customer_id' => $this->current_customer_id === null ? $altra->current_customer_id : null,
                'billing_customer_id' => $this->billing_customer_id === null ? $altra->billing_customer_id : null,
            ], fn ($v) => $v !== null));

            $this->fusione_suggerita_id = null;
            $this->fusione_suggerita_motivo = null;
            $this->save();

            // fusa_in_id resta anche da archiviata: e' quello che dice al sync
            // degli installati di non ripristinarla (vedi
            // GestionaleSyncRunner::importInstalledMachines()).
            $altra->update(['fusione_suggerita_id' => null, 'fusione_suggerita_motivo' => null, 'fusa_in_id' => $this->id]);
            $altra->delete();
        });

        RegistroSync::movimento('macchine', 'macchine fuse', [
            'tenuta' => $this->serial_number,
            'assorbita' => $altra->serial_number,
            'deciso_da' => auth()->user()?->email,
        ]);
    }

    /** Scarta la proposta: le due macchine restano distinte. */
    public function scartaFusione(): void
    {
        $this->update(['fusione_suggerita_id' => null, 'fusione_suggerita_motivo' => null]);
    }

    /** Dove Eureka dice che la macchina e' ora (GestionaleSyncRunner::proponiSpostamentiMacchine()). */
    public function spostamentoSuggerito(): BelongsTo
    {
        return $this->belongsTo(Customer::class, 'spostamento_suggerito_customer_id');
    }

    public static function chiaveSpostamento(?string $customerId, ?\DateTimeInterface $il): string
    {
        return $customerId.'|'.($il?->format('Y-m-d') ?? '');
    }

    /** Conferma: la macchina va dove dice Eureka, con la data della bolla. */
    public function accettaSpostamento(): bool
    {
        $cliente = $this->spostamentoSuggerito;

        if (! $cliente || ! $this->spostamento_suggerito_il) {
            $this->scartaSpostamento();

            return false;
        }

        // Il pagante che Eureka indica per quella consegna, se lo sappiamo.
        $codice = $this->spostamento_suggerito_pagante_code;
        $pagante = $codice ? Customer::withoutGlobalScopes()->where('tenant_id', $this->tenant_id)->where('gestionale_code', $codice)->first() : null;

        $this->moveTo($cliente, 'Da Eureka: '.$this->spostamento_suggerito_motivo, $this->spostamento_suggerito_il->copy()->startOfDay(), $pagante, $codice);
        $this->update([
            'spostamento_suggerito_customer_id' => null,
            'spostamento_suggerito_il' => null,
            'spostamento_suggerito_motivo' => null,
            'spostamento_suggerito_pagante_code' => null,
        ]);

        return true;
    }

    /** Non e' vero: non si ripropone finche' Eureka non dice qualcosa di diverso. */
    public function scartaSpostamento(): void
    {
        $this->update([
            'spostamento_scartato' => self::chiaveSpostamento($this->spostamento_suggerito_customer_id, $this->spostamento_suggerito_il),
            'spostamento_suggerito_customer_id' => null,
            'spostamento_suggerito_il' => null,
            'spostamento_suggerito_motivo' => null,
            'spostamento_suggerito_pagante_code' => null,
        ]);
    }
}
