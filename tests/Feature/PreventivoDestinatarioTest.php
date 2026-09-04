<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Quote;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Un preventivo va a chi lo ha chiesto, mai a chi paga.
 *
 * Il destinatario si prendeva da Customer::invoiceRecipient(), che segue il
 * pagante impostato sull'anagrafica: su Mariver quello e' Dersut, e la mail
 * partiva verso il torrefattore invece che verso il cliente (visto dal vivo
 * il 03/09/2026). Stessa regola gia' valida per i rapportini.
 */
class PreventivoDestinatarioTest extends TestCase
{
    use RefreshDatabase;

    private function scenario(): array
    {
        $tenant = Tenant::create(['name' => 'Alex', 'slug' => 'alex', 'is_master' => true]);

        $pagante = Customer::create([
            'tenant_id' => $tenant->id, 'company_name' => 'Dersut Caffe SPA',
            'emails' => ['ordini@dersut.example'],
        ]);
        $cliente = Customer::create([
            'tenant_id' => $tenant->id, 'company_name' => 'Mariver Srl',
            'emails' => ['info@mariver.example'],
            'billing_customer_id' => $pagante->id,
        ]);

        $utente = User::create([
            'tenant_id' => $tenant->id, 'name' => 'U', 'email' => 'u@alex.it', 'password' => bcrypt('x'),
        ]);

        $preventivo = Quote::create([
            'tenant_id' => $tenant->id, 'customer_id' => $cliente->id, 'user_id' => $utente->id,
            'number' => 'PRV-2026-0001', 'date' => now(), 'status' => 'bozza',
        ]);

        return [$preventivo, $cliente, $pagante];
    }

    public function test_la_mail_va_al_cliente_non_al_pagante(): void
    {
        [$preventivo, $cliente, $pagante] = $this->scenario();

        // Il pagante c'e' davvero: senza, il test non proverebbe niente.
        $this->assertSame($pagante->id, $cliente->invoiceRecipient()->id);

        $this->assertSame(
            'info@mariver.example',
            \App\Filament\Resources\QuoteResource::emailDestinatario($preventivo),
            'il preventivo va a chi lo ha chiesto',
        );
        $this->assertNotSame($pagante->primaryEmail(), \App\Filament\Resources\QuoteResource::emailDestinatario($preventivo));
    }

    /** Anche il testo saluta il cliente. */
    public function test_il_testo_saluta_il_cliente(): void
    {
        [$preventivo] = $this->scenario();

        $metodo = new \ReflectionMethod(\App\Filament\Resources\QuoteResource::class, 'defaultQuoteEmailBody');
        $metodo->setAccessible(true);
        $corpo = $metodo->invoke(null, $preventivo);

        $this->assertStringContainsString('Mariver', $corpo);
        $this->assertStringNotContainsString('Dersut', $corpo);
    }
}
