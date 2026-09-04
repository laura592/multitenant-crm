<?php

namespace Tests\Feature;

use App\Filament\Resources\QuoteResource\Pages\ListQuotes;
use App\Models\Customer;
use App\Models\Quote;
use App\Models\Tenant;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Concerns\AssignsPermissionRoles;
use Tests\TestCase;

/**
 * Bug reale segnalato (due volte): prima "non sono in ordine cronologico",
 * poi - dopo un primo tentativo di ordinare per data - "non sono in ordine
 * numerico". Il problema di fondo e' che ne' created_at ne' date sono
 * affidabili per un ordine stabile sui dati importati dal legacy:
 * created_at e' quasi identico su tutti i preventivi (l'istante
 * dell'import), e "date" ha molte righe con lo stesso giorno. "number"
 * (PRV-AAAA-NNNN, zero-padded) invece e' univoco per riga e cresce in
 * ordine di creazione: l'unico campo che da' un ordine deterministico.
 */
class QuoteChronologicalSortTest extends TestCase
{
    use RefreshDatabase, AssignsPermissionRoles;

    public function test_quotes_list_defaults_to_number_order_even_when_created_at_and_date_tie(): void
    {
        $tenant = Tenant::create(['name' => 'Alex', 'slug' => 'alex']);
        $user = User::create(['tenant_id' => $tenant->id, 'name' => 'D', 'email' => 'd@alex.it', 'password' => bcrypt('x')]);
        $this->giveRole($user, $tenant, 'admin');
        $this->actingAs($user);
        Filament::setTenant($tenant);

        $customer = Customer::create(['tenant_id' => $tenant->id, 'company_name' => 'Cliente']);

        // Stesso created_at (simula l'import legacy) e stessa date: solo il
        // numero distingue l'ordine di creazione.
        $sameImportInstant = now();
        $sameDate = '2026-07-01';

        $first = Quote::create(['tenant_id' => $tenant->id, 'customer_id' => $customer->id, 'date' => $sameDate, 'created_at' => $sameImportInstant]);
        $second = Quote::create(['tenant_id' => $tenant->id, 'customer_id' => $customer->id, 'date' => $sameDate, 'created_at' => $sameImportInstant]);
        $third = Quote::create(['tenant_id' => $tenant->id, 'customer_id' => $customer->id, 'date' => $sameDate, 'created_at' => $sameImportInstant]);

        $this->assertSame($first->created_at->timestamp, $third->created_at->timestamp, 'precondizione: stesso created_at, come i dati importati');
        $this->assertSame($first->date->toDateString(), $third->date->toDateString(), 'precondizione: stessa date');

        $component = Livewire::test(ListQuotes::class);
        $records = $component->instance()->getTable()->getRecords()->pluck('number')->values();

        $this->assertSame(
            [$third->number, $second->number, $first->number],
            $records->all(),
            'Deve seguire il numero preventivo (piu recente/alto prima), l\'unico campo univoco per riga'
        );
    }
}
