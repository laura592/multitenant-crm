<?php

namespace Tests\Feature;

use App\Filament\Resources\CustomerResource\Pages\CreateCustomer;
use App\Models\Customer;
use App\Models\Tenant;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Concerns\AssignsPermissionRoles;
use Tests\TestCase;

class CustomerMultiContactTest extends TestCase
{
    use AssignsPermissionRoles, RefreshDatabase;

    public function test_customer_can_be_created_with_multiple_emails_phones_and_pec(): void
    {
        $tenant = Tenant::create(['name' => 'Gifar', 'slug' => 'gifar']);
        $user = User::create([
            'tenant_id' => $tenant->id, 'name' => 'Test Gifar', 'email' => 'test@gifar.it', 'password' => bcrypt('password'),
        ]);
        $this->giveRole($user, $tenant, 'admin');

        $this->actingAs($user);
        Filament::setTenant($tenant);

        Livewire::test(CreateCustomer::class)
            ->fillForm([
                'company_name' => 'Multi Contact SRL',
                'emails' => [
                    'item-1' => ['email' => 'amministrazione@multicontact.it'],
                    'item-2' => ['email' => 'referente@multicontact.it'],
                ],
                'phones' => [
                    'item-1' => ['phone' => '0438 486794'],
                    'item-2' => ['phone' => '+39 333 1234567'],
                ],
                'pec' => 'multicontact@pec.it',
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $customer = Customer::where('company_name', 'Multi Contact SRL')->firstOrFail();

        $this->assertSame([
            'amministrazione@multicontact.it',
            'referente@multicontact.it',
        ], $customer->emails);

        $this->assertSame([
            '+390438486794',
            '+393331234567',
        ], $customer->phones);

        $this->assertSame('multicontact@pec.it', $customer->pec);
        $this->assertSame('amministrazione@multicontact.it', $customer->primaryEmail());
        $this->assertSame('+390438486794', $customer->primaryPhone());
    }
}
