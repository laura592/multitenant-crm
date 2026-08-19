<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Support\DisplayName;
use App\Support\LavaggioDescrizione;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

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
    use BelongsToTenant, HasUuids;

    protected $table = 'lavaggi';

    protected $fillable = [
        'tenant_id',
        'customer_id',
        'machine_unit_id',
        'maintenance_schedule_id',
        'service_report_id',
        'data',
        'descrizione',
        'filtro_sostituito',
    ];

    protected $casts = [
        'data' => 'date',
        'filtro_sostituito' => 'boolean',
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

        return $this->machineUnit?->billingCustomer ?? $this->customer->invoiceRecipient();
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
