<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Filament\Models\Contracts\FilamentUser;
use Filament\Models\Contracts\HasTenants;
use Filament\Panel;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Spatie\Permission\Traits\HasRoles;

class User extends Authenticatable implements FilamentUser, HasTenants
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, HasRoles, HasUuids, Notifiable;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'tenant_id',
        'name',
        'email',
        'password',
        'is_super_admin',
        'daily_contract_hours',
        'weekly_contract_hours',
        'annual_leave_days',
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
            'daily_contract_hours' => 'decimal:2',
            'weekly_contract_hours' => 'decimal:2',
            'annual_leave_days' => 'integer',
        ];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function canAccessPanel(Panel $panel): bool
    {
        return true;
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
}
