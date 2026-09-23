<?php

namespace App\Models;

use App\Filament\Resources\MaintenanceScheduleResource;
use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\LogsAuditTrail;
use App\Support\DisplayName;
use App\Support\LavaggioDescrizione;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Lavaggio periodico di una macchina presso un cliente (es. "5 vie + apertura",
 * "chiusura stagionale"): registro dei singoli interventi, area separata
 * dagli interventi tecnici veri e propri (ServiceReport) perche' i lavaggi
 * hanno una natura diversa (pulizia, non riparazione/manutenzione). La
 * cadenza/scadenza vive sul MaintenanceSchedule di tipo 'lavaggio' collegato
 * (maintenance_schedule_id), non piu' sul Customer.
 */
class Lavaggio extends Model
{
    use BelongsToTenant, HasUuids, LogsAuditTrail;

    protected $table = 'lavaggi';

    protected $fillable = [
        'tenant_id',
        'customer_id',
        'machine_unit_id',
        'maintenance_schedule_id',
        'service_report_id',
        'data',
        'descrizione',
        'lines_washed',
        'filtro_sostituito',
    ];

    protected $casts = [
        'data' => 'date',
        'filtro_sostituito' => 'boolean',
        'lines_washed' => 'integer',
    ];

    // Proprieta' reale (non un attributo Eloquent): dichiararla esplicitamente
    // evita che l'assegnazione finisca nel magic __set() di Eloquent, che la
    // tratterebbe come una colonna da scrivere e romperebbe la UPDATE.
    public ?string $previousMaintenanceScheduleId = null;

    protected static function booted(): void
    {
        // La prossima scadenza lavaggio (MaintenanceSchedule::next_due_date)
        // e' calcolata dall'ultimo lavaggio registrato: va ricalcolata ogni
        // volta che un lavaggio viene salvato o cancellato, non solo alla
        // creazione, altrimenti modificare/eliminare un lavaggio storico
        // lascerebbe la scadenza disallineata.
        static::updating(function (self $lavaggio) {
            $lavaggio->previousMaintenanceScheduleId = $lavaggio->getOriginal('maintenance_schedule_id');
        });

        static::saved(function (self $lavaggio) {
            // Query fresca invece della relation `maintenanceSchedule`: se il
            // record era gia' stato caricato con la relazione in cache (es.
            // dal form di Filament) e poi si cambia maintenance_schedule_id,
            // Eloquent non invalida la relazione BelongsTo gia' risolta e
            // ricalcolerebbe il piano sbagliato (quello vecchio).
            if ($lavaggio->maintenance_schedule_id) {
                MaintenanceSchedule::find($lavaggio->maintenance_schedule_id)?->recalculateLavaggioNextDue();
            }

            // Se il lavaggio e' stato spostato su un altro piano (es. cambio
            // cliente), anche il piano precedente va ricalcolato: altrimenti
            // resterebbe con last_lavaggio_id/next_due_date basati su un
            // lavaggio che non gli appartiene piu'.
            $previousScheduleId = $lavaggio->previousMaintenanceScheduleId ?? null;

            if ($previousScheduleId && $previousScheduleId !== $lavaggio->maintenance_schedule_id) {
                MaintenanceSchedule::find($previousScheduleId)?->recalculateLavaggioNextDue();
            }
        });

        static::deleted(function (self $lavaggio) {
            if ($lavaggio->maintenance_schedule_id) {
                MaintenanceSchedule::find($lavaggio->maintenance_schedule_id)?->recalculateLavaggioNextDue();
            }
        });
    }

    /**
     * Il campo e' testo libero digitato a mano (vedi migration): senza
     * normalizzazione qui, ogni RelationManager che lo edita dovrebbe
     * ripetere la stessa pulizia, e i vecchi import da foglio elettronico
     * l'avrebbero comunque scritto grezzo. Le stringhe "Generato da
     * rapportino ..." restano intatte (vedi LavaggioDescrizione::normalize).
     */
    public function setDescrizioneAttribute(?string $value): void
    {
        $this->attributes['descrizione'] = LavaggioDescrizione::normalize($value);
    }

    /**
     * Una riga per visita e per impianto. I lavaggi restano uno per piano
     * (birra, vino, bibite hanno vie e scadenze proprie anche sullo stesso
     * impianto spina), ma nello storico del cliente la stessa visita
     * appariva ripetuta per ogni bevanda. Qui si tiene una riga
     * rappresentante per (cliente, data, impianto); le altre si leggono con
     * visitSiblings().
     */
    public function scopePerVisita(Builder $query): Builder
    {
        $representatives = DB::table('lavaggi as l')
            ->leftJoin('maintenance_schedules as ms', 'ms.id', '=', 'l.maintenance_schedule_id')
            ->selectRaw('MIN(l.id)')
            ->groupBy('l.customer_id', 'l.data', DB::raw(self::impiantoSql()));

        return $query->whereIn($query->getModel()->qualifyColumn('id'), $representatives);
    }

    /**
     * L'impianto della visita: la macchina del piano, o quella scritta sul
     * lavaggio se il piano non ce l'ha.
     */
    private static function impiantoSql(): string
    {
        return "COALESCE(ms.machine_unit_id, l.machine_unit_id, '')";
    }

    /**
     * I lavaggi della stessa visita (stesso cliente, data e impianto),
     * questo compreso.
     *
     * @return Collection<int, self>
     */
    public function visitSiblings(): Collection
    {
        $impianto = $this->maintenanceSchedule?->machine_unit_id ?? $this->machine_unit_id;

        return self::query()
            ->with('maintenanceSchedule')
            ->where('customer_id', $this->customer_id)
            ->whereDate('data', $this->data)
            ->get()
            ->filter(fn (self $l) => ($l->maintenanceSchedule?->machine_unit_id ?? $l->machine_unit_id) === $impianto)
            ->values();
    }

    /**
     * "Birra 2 vie · Vino 2 vie · Bibite 1 via": cosa e' stato lavato in
     * questa visita, una voce per piano.
     */
    public function visitLinesLabel(): string
    {
        // Una voce per bevanda: due rapportini dello stesso giorno sullo
        // stesso impianto non devono ripeterla. Le vie sono le piu' alte.
        return $this->visitSiblings()
            ->groupBy(fn (self $l) => MaintenanceScheduleResource::beverageLabels()[$l->maintenanceSchedule?->beverage_type] ?? '')
            ->map(function (Collection $righe, string $bevanda) {
                $vie = (int) $righe->max('lines_washed');
                $vie = $vie ? $vie.($vie === 1 ? ' via' : ' vie') : null;

                return trim(($bevanda !== '' ? $bevanda : ($vie ? 'Lavaggio' : '')).' '.($vie ?? ''));
            })
            ->filter()
            ->values()
            ->implode(' · ') ?: '—';
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function machineUnit(): BelongsTo
    {
        return $this->belongsTo(MachineUnit::class);
    }

    public function maintenanceSchedule(): BelongsTo
    {
        return $this->belongsTo(MaintenanceSchedule::class);
    }

    /**
     * Valorizzata solo per i lavaggi generati automaticamente da
     * ServiceReport::syncMaintenanceSchedule() — vedi commento sulla colonna
     * nella migration.
     */
    public function serviceReport(): BelongsTo
    {
        return $this->belongsTo(ServiceReport::class);
    }

    /**
     * Vedi ServiceReport::invoiceRecipient(): la macchina (se ha un pagatore
     * proprio) prevale sul billing_customer_id generico del cliente.
     */
    public function invoiceRecipient(): Customer
    {
        if (! $this->customer) {
            throw new \RuntimeException('Cliente collegato a questo lavaggio non trovato (probabilmente eliminato).');
        }

        return $this->machineUnit?->paganteEffettivo() ?? $this->customer->invoiceRecipient();
    }

    /**
     * Senza machine_unit_id sulla visita, la macchina e' quella collegata al
     * piano di lavaggio (il caso normale: il piano copre una sola macchina,
     * vedi MaintenanceSchedule::machine_unit_id). Solo se nemmeno il piano ha
     * una macchina collegata (dato legacy non ancora sistemato) si torna al
     * vecchio riepilogo su tutto il parco macchine del cliente. Centralizzata
     * qui perche' era duplicata identica fra i due LavaggiRelationManager
     * (su MaintenanceScheduleResource e su CustomerResource).
     */
    public function machineLabel(): string
    {
        if ($this->machine_unit_id && $this->machineUnit) {
            return $this->machineUnit->serial_number;
        }

        if ($scheduleUnit = $this->maintenanceSchedule?->machineUnit) {
            return $scheduleUnit->serial_number;
        }

        $units = MachineUnit::where('current_customer_id', $this->customer_id)->pluck('model_name');

        return $units->count() > 1 ? 'Tutti ('.$units->implode(', ').')' : ($units->first() ?? '—');
    }

    /**
     * Se il cliente ha impianti con pagante diverso (es. Gigi Marchetto) e la
     * visita non specifica la macchina, invoiceRecipient() da solo darebbe un
     * pagante di default fuorviante: qui serve il dettaglio "misto" - a meno
     * che il piano di lavaggio non abbia gia' una macchina collegata, nel
     * qual caso il suo pagante e' quello giusto senza bisogno di indovinare.
     */
    public function billingLabel(): string
    {
        // Il rapportino collegato (se importato da Eureka con --with-detail)
        // sa chi ha pagato DAVVERO quella visita specifica (destinazione,
        // vedi ServiceReport::eureka_destinazione_label) - piu' affidabile del
        // billing_customer_id impostato a mano su macchina/cliente, che puo'
        // essere non ancora sistemato o non riflettere un cambio di pagante
        // nel tempo (es. Il Filare SRL -> Ristoalma SRL sullo stesso impianto).
        if ($this->service_report_id && ($label = $this->serviceReport?->eureka_destinazione_label)) {
            return DisplayName::titleCase($label);
        }

        if (! $this->machine_unit_id) {
            if ($scheduleUnit = $this->maintenanceSchedule?->machineUnit) {
                return DisplayName::titleCase($scheduleUnit->billingCustomer?->full_name) ?? DisplayName::titleCase($this->invoiceRecipient()->full_name);
            }

            $units = MachineUnit::where('current_customer_id', $this->customer_id)->get();
            $targets = $units->map(fn (MachineUnit $u) => DisplayName::titleCase($u->billingCustomer?->full_name) ?? 'se stesso');

            if ($targets->unique()->count() > 1) {
                return 'Misto: '.$units->map(fn (MachineUnit $u) => $u->model_name.'='.(DisplayName::titleCase($u->billingCustomer?->full_name) ?? 'se stesso'))->implode(', ');
            }
        }

        return DisplayName::titleCase($this->invoiceRecipient()->full_name);
    }
}
