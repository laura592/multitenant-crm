<?php

namespace Tests\Unit;

use App\Models\Customer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CustomerFullNameTest extends TestCase
{
    use RefreshDatabase;

    public function test_full_name_prioritizes_company_name_over_contact_person(): void
    {
        $customer = new Customer([
            'company_name' => 'Hotel Universal',
            'first_name' => 'Luca',
            'last_name' => 'Barillari',
        ]);

        $this->assertSame('Hotel Universal (Luca Barillari)', $customer->full_name);
    }

    public function test_full_name_falls_back_to_contact_person_without_company(): void
    {
        $customer = new Customer(['first_name' => 'Patrizia', 'last_name' => 'Morchio']);

        $this->assertSame('Patrizia Morchio', $customer->full_name);
    }

    public function test_full_name_falls_back_to_company_without_contact(): void
    {
        $customer = new Customer(['company_name' => 'Gelateria Mozart']);

        $this->assertSame('Gelateria Mozart', $customer->full_name);
    }
}
