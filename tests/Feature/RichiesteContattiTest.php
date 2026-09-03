<?php

namespace Tests\Feature;

use App\Filament\Resources\InformationRequestResource;
use App\Models\Customer;
use App\Models\InformationRequest;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Nell'elenco delle richieste si vedono TUTTI i contatti del cliente.
 *
 * Prima si mostrava solo il primo, e l'email era pure nascosta di default:
 * 99 clienti hanno piu' di un'email e 19 piu' di un numero, e chi richiama
 * da questa pagina finiva ad aprire l'anagrafica per trovare l'altro
 * recapito (richiesta dell'ufficio, 03/09/2026).
 */
class RichiesteContattiTest extends TestCase
{
    use RefreshDatabase;

    private function colonna(string $nome): \Filament\Tables\Columns\TextColumn
    {
        $tabella = InformationRequestResource::table(
            \Filament\Tables\Table::make(new \Filament\Resources\Pages\ListRecords),
        );

        return collect($tabella->getColumns())->first(fn ($c) => $c->getName() === $nome);
    }

    public function test_si_vedono_tutte_le_email_e_tutti_i_telefoni(): void
    {
        $tenant = Tenant::create(['name' => 'Alex', 'slug' => 'alex', 'is_master' => true]);
        $cliente = Customer::create([
            'tenant_id' => $tenant->id, 'company_name' => 'Bar Centrale',
            'emails' => ['info@bar.example', 'amministrazione@bar.example'],
            'phones' => ['0421 111111', '333 2222222'],
        ]);
        $richiesta = InformationRequest::create([
            'tenant_id' => $tenant->id, 'customer_id' => $cliente->id,
            'request_details' => 'Vorrei un preventivo', 'status' => 'nuova',
        ]);

        $email = $this->colonna('customer_email');
        $telefono = $this->colonna('customer_phone');

        // getStateUsing() e' un setter: la closure si legge dalla proprieta'.
        foreach ([[$email, $cliente->emails], [$telefono, $cliente->phones]] as [$colonna, $atteso]) {
            $prop = new \ReflectionProperty($colonna, 'getStateUsing');
            $prop->setAccessible(true);
            $this->assertSame($atteso, ($prop->getValue($colonna))($richiesta));
        }
    }

    /**
     * L'email non deve piu' essere nascosta: chi apre questa pagina sta per
     * rispondere, e i due modi di rispondere si devono vedere subito.
     */
    public function test_l_email_si_vede_senza_doverla_accendere(): void
    {
        $this->assertFalse(
            $this->colonna('customer_email')->isToggledHiddenByDefault(),
            'la colonna Email deve essere visibile di default',
        );
        $this->assertFalse($this->colonna('customer_phone')->isToggledHiddenByDefault());
    }
}
