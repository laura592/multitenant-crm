<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Models\Concerns\LogsAuditTrail;
use Carbon\Carbon;
use Database\Factories\UserFactory;
use Filament\Models\Contracts\FilamentUser;
use Filament\Models\Contracts\HasTenants;
use Filament\Panel;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Jeffgreco13\FilamentBreezy\Traits\TwoFactorAuthenticatable;
use Spatie\Activitylog\Support\LogOptions;
use Spatie\Permission\Traits\HasRoles;

class User extends Authenticatable implements FilamentUser, HasTenants
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, HasRoles, HasUuids, LogsAuditTrail, Notifiable, TwoFactorAuthenticatable;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'legacy_id',
        'tenant_id',
        'name',
        'email',
        'password',
        'is_super_admin',
        'is_active',
        'daily_contract_hours',
        'weekly_contract_hours',
        'annual_leave_days',
        'default_morning_in',
        'default_morning_out',
        'default_afternoon_in',
        'default_afternoon_out',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * Valori di default sull'oggetto in memoria (non solo a livello di DB): senza
     * questo, un User appena creato senza specificare is_active resterebbe null
     * finche' non viene ricaricato dal DB, facendo fallire canAccessPanel().
     */
    protected $attributes = [
        'is_active' => true,
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_super_admin' => 'boolean',
            'is_active' => 'boolean',
            'daily_contract_hours' => 'decimal:2',
            'weekly_contract_hours' => 'decimal:2',
            'annual_leave_days' => 'integer',
            'default_morning_in' => 'datetime:H:i',
            'default_morning_out' => 'datetime:H:i',
            'default_afternoon_in' => 'datetime:H:i',
            'default_afternoon_out' => 'datetime:H:i',
        ];
    }

    /**
     * Come LogsAuditTrail di default (logFillable + logOnlyDirty +
     * dontLogEmptyChanges), ma esclude "password": e' fillable (serve al
     * form di creazione/reset) ma non ha senso tracciarne l'hash nell'audit
     * log, ne' come valore ne' come semplice "e' cambiata" - chi ha accesso
     * all'audit non deve avere alcun segnale sulle credenziali altrui.
     */
    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logFillable()
            ->logExcept(['password'])
            ->logOnlyDirty()
            ->dontLogEmptyChanges();
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function canAccessPanel(Panel $panel): bool
    {
        return (bool) $this->is_active;
    }

    /**
     * Tenant su cui questo utente puo operare nel pannello Filament. Lo staff
     * Alex (is_super_admin) vede tutti i tenant nello switcher; un utente
     * normale vede solo il proprio (Filament nasconde lo switcher se c'e una
     * sola opzione). Vedi docs/architecture.md §5.1.
     */
    public function getTenants(Panel $panel): Collection
    {
        return $this->is_super_admin
            ? Tenant::query()->orderBy('name')->get()
            : Tenant::query()->whereKey($this->tenant_id)->get();
    }

    public function canAccessTenant(Model $tenant): bool
    {
        return $this->is_super_admin || $this->tenant_id === $tenant->getKey();
    }

    /**
     * Giorni di ferie ancora disponibili nell'anno indicato (default l'anno
     * corrente): monte annuo meno le richieste di tipo "ferie" gia' approvate
     * che iniziano in quell'anno.
     */
    public function remainingFerieDays(?int $year = null): ?int
    {
        if ($this->annual_leave_days === null) {
            return null;
        }

        $year ??= now()->year;

        $usedDays = $this->leaveRequests()
            ->where('type', LeaveRequest::TYPE_FERIE)
            ->where('status', 'approvato')
            ->whereYear('date_from', $year)
            ->get()
            ->sum(fn (LeaveRequest $request) => $request->days);

        return $this->annual_leave_days - $usedDays;
    }

    public function leaveRequests(): HasMany
    {
        return $this->hasMany(LeaveRequest::class);
    }

    /**
     * Turni dell'orario standard (es. 8-12/13-17) configurati per questo
     * dipendente, usati per pre-compilare il form Presenze e dal pulsante
     * "Turno standard di oggi" (TimeEntryResource). Un turno compare solo se
     * ha sia entrata sia uscita configurate: con un solo orario impostato
     * non si potrebbe comunque creare una timbratura valida.
     *
     * @return array<int, array{shift: string, label: string, clock_in: Carbon, clock_out: Carbon}>
     */
    public function standardShifts(?Carbon $date = null): array
    {
        $date ??= now();

        $shifts = [
            'mattina' => ['label' => 'Mattina', 'in' => $this->default_morning_in, 'out' => $this->default_morning_out],
            'pomeriggio' => ['label' => 'Pomeriggio', 'in' => $this->default_afternoon_in, 'out' => $this->default_afternoon_out],
        ];

        return collect($shifts)
            ->filter(fn (array $shift) => $shift['in'] && $shift['out'])
            ->map(fn (array $shift, string $key) => [
                'shift' => $key,
                'label' => $shift['label'],
                'clock_in' => $date->copy()->setTime($shift['in']->hour, $shift['in']->minute),
                'clock_out' => $date->copy()->setTime($shift['out']->hour, $shift['out']->minute),
            ])
            ->values()
            ->all();
    }

    public function hasStandardSchedule(): bool
    {
        return $this->default_morning_in && $this->default_morning_out
            || $this->default_afternoon_in && $this->default_afternoon_out;
    }
}
