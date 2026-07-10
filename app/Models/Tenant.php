<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;

class Tenant extends Model
{
    use HasUuids;

    protected $fillable = [
        'name',
        'legal_name',
        'vat_number',
        'tax_code',
        'email',
        'phone',
        'street',
        'postal_code',
        'city',
        'province',
        'slug',
        'is_master',
        'is_active',
        'logo_path',
        'primary_color',
        'machine_discount_percent',
        'default_commission_scenario',
        'scenario_a_commission_percent',
        'scenario_b_installation_fee',
        'scenario_c_preinstallation_fee',
        'exclusive_supply_required',
        'territory_exclusive',
        'territory_notes',
        'contract_start_date',
        'contract_duration_months',
        'notice_period_days',
        'saas_billing_enabled',
        'saas_plan_fee',
        'saas_billing_cycle',
    ];

    protected $casts = [
        'is_master' => 'boolean',
        'is_active' => 'boolean',
        'machine_discount_percent' => 'decimal:2',
        'scenario_a_commission_percent' => 'decimal:2',
        'scenario_b_installation_fee' => 'decimal:2',
        'scenario_c_preinstallation_fee' => 'decimal:2',
        'exclusive_supply_required' => 'boolean',
        'territory_exclusive' => 'boolean',
        'contract_start_date' => 'date',
        'contract_duration_months' => 'integer',
        'notice_period_days' => 'integer',
        'saas_billing_enabled' => 'boolean',
        'saas_plan_fee' => 'decimal:2',
    ];

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    public function deadlines(): MorphMany
    {
        return $this->morphMany(Deadline::class, 'deadlinable');
    }
}
