<?php

namespace Tests\Feature;

use App\Filament\Pages\RiepilogoOre;
use App\Filament\Resources\MaterialOrderResource;
use App\Filament\Resources\MaterialOrderResource\Pages\EditMaterialOrder;
use App\Filament\Resources\MaterialOrderResource\Pages\ListMaterialOrders;
use App\Models\Material;
use App\Models\MaterialOrder;
use App\Models\Supplier;
use App\Models\Tenant;
use App\Models\TimeEntry;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Concerns\AssignsPermissionRoles;
use Tests\TestCase;

/**
 * Ticket 4.2 (docs/roadmap-tickets.md, Epic 4): copre esplicitamente i casi
 * negativi sugli asset scaricabili generati dal server.
 *
 * Censimento di PDF/export/allegati scaricabili trovati in app/ (Pdf::loadView,
 * Excel::download, streamDownload, ->stream(), esclusi Quote/preventivi -
 * coperti separatamente in QuotePdfCrossTenantTest - e il rapportino tecnico,
 * gia' testato in ServiceReportPdfCrossTenantTest):
 *
 * 1. App\Filament\Resources\MaterialOrderResource::streamPdf() / streamExcel()
 *    (azione riga tabella + azione header su EditMaterialOrder). Il candidato
 *    piu' rilevante rimasto: coperto qui, sia per l'accesso negato sia per
 *    quello corretto.
 * 2. App\Filament\Pages\RiepilogoOre (Excel::download + Pdf::loadView/
 *    streamDownload): non prende in input un ID di record di un altro tenant
 *    (la query e' derivata esplicitamente da Filament::getTenant()), quindi
 *    il rischio principale e' una fuga di dati fra tenant nel calcolo delle
 *    righe piuttosto che un bypass di route binding: coperto qui con un test
 *    di isolamento dati.
 * 3. App\Filament\Resources\ServiceReportResource (azione "Invia", riga
 *    ~277) e MaterialOrderResource::buildPdf() usato da "Invia al fornitore":
 *    generano un PDF da allegare a un'email, non un download diretto
 *    esposto all'utente; condividono lo stesso confine di sicurezza gia'
 *    verificato per streamPdf/streamExcel (record risolto tramite la query
 *    Eloquent scoped-per-tenant di Filament), quindi non duplicati qui.
 * 4. App\Http\Controllers\ServiceReportController::pdf() (route
 *    service-reports.pdf): gia' protetto e testato in
 *    ServiceReportPdfCrossTenantTest, fuori perimetro di questo file.
 * 5. App\Filament\Resources\QuoteResource::streamPdf(): dominio Quote,
 *    coperto separatamente in QuotePdfCrossTenantTest.
 */
class SensitiveDownloadsAuthorizationTest extends TestCase
{
    use RefreshDatabase, AssignsPermissionRoles;

    private Tenant $tenant;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create(['name' => 'Gifar', 'slug' => 'gifar']);
        $this->admin = User::create(['tenant_id' => $this->tenant->id, 'name' => 'A', 'email' => 'a@gifar.it', 'password' => bcrypt('x')]);
        $this->giveRole($this->admin, $this->tenant, 'admin');

        $this->actingAs($this->admin);
        Filament::setTenant($this->tenant);
    }

    private function makeOrderWithItem(Tenant $tenant, string $materialCode = 'PI0108S'): MaterialOrder
    {
        $supplier = Supplier::create(['name' => 'John Guest']);
        $material = Material::create(['code' => $materialCode, 'category' => 'Raccordi grigi', 'type' => 'Terminale diritto', 'supplier_id' => $supplier->id]);
        $order = MaterialOrder::create(['tenant_id' => $tenant->id, 'supplier_id' => $supplier->id]);
        MaterialOrderResource::addSelectedMaterialsToOrder($order, [$material->id => 2]);

        return $order;
    }

    public function test_admin_can_generate_pdf_and_excel_of_a_material_order_in_their_own_tenant(): void
    {
        $order = $this->makeOrderWithItem($this->tenant);

        // response()->streamDownload() non imposta un content-type esplicito
        // (dompdf/Excel non lo dichiarano su questa response), quindi la
        // verifica corretta e' sul content-disposition/nome file generato,
        // non su un header content-type che qui resta assente.
        $pdfResponse = MaterialOrderResource::streamPdf($order->fresh());
        $this->assertSame(200, $pdfResponse->getStatusCode());
        $this->assertStringContainsString("{$order->number}.pdf", $pdfResponse->headers->get('content-disposition'));

        $excelResponse = MaterialOrderResource::streamExcel($order->fresh());
        $this->assertSame(200, $excelResponse->getStatusCode());
        $this->assertStringContainsString("{$order->number}.xlsx", $excelResponse->headers->get('content-disposition'));
    }

    public function test_edit_page_of_a_material_order_from_another_tenant_is_not_found(): void
    {
        $otherTenant = Tenant::create(['name' => 'Altro Partner', 'slug' => 'altro-partner']);
        $foreignOrder = $this->makeOrderWithItem($otherTenant);

        // La route dell'edit page e' l'unico punto da cui si arriva alle
        // azioni "PDF"/"Excel" in header: se il record di un altro tenant
        // non e' risolvibile, quelle azioni non sono raggiungibili.
        $this->actingAs($this->admin)
            ->get("/admin/{$this->tenant->slug}/material-orders/{$foreignOrder->id}/edit")
            ->assertNotFound();
    }

    public function test_edit_record_component_cannot_bind_a_material_order_from_another_tenant(): void
    {
        $otherTenant = Tenant::create(['name' => 'Altro Partner', 'slug' => 'altro-partner-2']);
        $foreignOrder = $this->makeOrderWithItem($otherTenant);

        // Stesso confine ma bypassando l'HTTP layer: anche costruendo
        // direttamente il componente Livewire della edit page con l'ID di un
        // ordine di un altro tenant (come farebbe una richiesta AJAX
        // "forgiata" a mano), il binding fallisce perche' la query di
        // MaterialOrderResource resta scoped al tenant corrente
        // (App\Models\Concerns\BelongsToTenant).
        $this->expectException(\Illuminate\Database\Eloquent\ModelNotFoundException::class);

        Livewire::test(EditMaterialOrder::class, ['record' => $foreignOrder->getRouteKey()]);
    }

    public function test_material_order_list_does_not_expose_rows_from_another_tenant(): void
    {
        $otherTenant = Tenant::create(['name' => 'Altro Partner', 'slug' => 'altro-partner-3']);
        $ownOrder = $this->makeOrderWithItem($this->tenant, 'PI0108S-OWN');
        $foreignOrder = $this->makeOrderWithItem($otherTenant, 'PI0108S-FOREIGN');

        Livewire::test(ListMaterialOrders::class)
            ->assertCanSeeTableRecords([$ownOrder])
            ->assertCanNotSeeTableRecords([$foreignOrder]);
    }

    /**
     * Ruoli senza il permesso material::order (es. partner, amministrazione)
     * non arrivano nemmeno alla lista da cui si aprono le
     * azioni PDF/Excel. Un metodo di test per ruolo (invece di un foreach
     * con piu' richieste HTTP reali in sequenza nello stesso test) evita un
     * artefatto noto del test harness Livewire/Filament: piu' richieste
     * full-page a Livewire con actingAs() diversi in sequenza nello stesso
     * metodo di test possono corrompere lo stato interno del componente
     * della richiesta precedente e produrre un 500 spurio invece del 403
     * reale (riproducibile solo nel processo di test, mai in produzione
     * dove ogni richiesta HTTP e' un processo PHP a se').
     */
    public function test_partner_role_cannot_reach_material_orders_panel(): void
    {
        $user = User::create([
            'tenant_id' => $this->tenant->id, 'name' => 'Partner', 'email' => 'partner@gifar.it', 'password' => bcrypt('x'),
        ]);
        $this->giveRole($user, $this->tenant, 'partner');

        $this->actingAs($user)
            ->get("/admin/{$this->tenant->slug}/material-orders")
            ->assertForbidden();
    }

    public function test_amministrazione_role_cannot_reach_material_orders_panel(): void
    {
        $user = User::create([
            'tenant_id' => $this->tenant->id, 'name' => 'Amministrazione', 'email' => 'amministrazione@gifar.it', 'password' => bcrypt('x'),
        ]);
        $this->giveRole($user, $this->tenant, 'amministrazione');

        $this->actingAs($user)
            ->get("/admin/{$this->tenant->slug}/material-orders")
            ->assertForbidden();
    }

    /**
     * Candidato #2 del censimento: RiepilogoOre calcola le righe filtrando
     * esplicitamente per Filament::getTenant()->id (non da un parametro di
     * route), quindi il rischio non e' un binding cross-tenant ma una fuga
     * di dati nel calcolo: verifica che un utente di un altro tenant non
     * compaia mai nel riepilogo, anche se esiste nel DB nello stesso mese.
     */
    public function test_riepilogo_ore_rows_never_include_users_of_another_tenant(): void
    {
        $otherTenant = Tenant::create(['name' => 'Altro Partner', 'slug' => 'altro-partner-4']);
        $foreignUser = User::create([
            'tenant_id' => $otherTenant->id, 'name' => 'Utente Estraneo', 'email' => 'estraneo@altro-partner.it',
            'password' => bcrypt('x'), 'daily_contract_hours' => 8,
        ]);

        $today = now()->startOfMonth()->addDays(3);
        TimeEntry::create([
            'tenant_id' => $otherTenant->id, 'user_id' => $foreignUser->id,
            'clock_in' => $today->copy()->setTime(8, 0), 'clock_out' => $today->copy()->setTime(17, 0),
        ]);

        $page = new RiepilogoOre();
        $page->mount();
        $page->month = $today->month;
        $page->year = $today->year;

        $rows = $page->getRows();

        $this->assertFalse($rows->contains('user', 'Utente Estraneo'));
    }
}
