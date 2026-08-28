<?php

namespace Tests\Unit;

use App\Models\Customer;
use App\Support\DisplayName;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * L'etichetta usata da tutte le select cliente (preventivi, rapportini,
 * richieste informazioni): ragioni sociali identiche fra clienti diversi sono
 * la norma nelle catene, e senza il paese si vedono tutte uguali nell'elenco.
 */
class CustomerOptionLabelTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_town_is_appended_and_the_name_normalized(): void
    {
        $customer = new Customer(['company_name' => 'HOTEL UNIVERSAL SRL', 'city' => 'Jesolo']);

        $this->assertSame('Hotel Universal Srl (Jesolo)', DisplayName::customerOption($customer));
    }

    public function test_without_a_town_only_the_name_is_shown(): void
    {
        $customer = new Customer(['company_name' => 'Bar Centrale']);

        $this->assertSame('Bar Centrale', DisplayName::customerOption($customer));
    }

    public function test_no_customer_no_label(): void
    {
        $this->assertNull(DisplayName::customerOption(null));
    }
}
