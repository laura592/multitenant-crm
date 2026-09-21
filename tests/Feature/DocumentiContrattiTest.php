<?php

namespace Tests\Feature;

use App\Filament\Resources\PriceListResource\Pages\ManagePriceLists;
use App\Models\PriceList;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Assistenza\ContrattoAssistenza;
use App\Support\Assistenza\ContrattoAssistenzaPdf;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\Concerns\AssignsPermissionRoles;
use Tests\TestCase;

/**
 * "Listini" diventa "Documenti" (21/09/2026): i modelli dei contratti di
 * assistenza si caricano dal pannello e il contratto scaricato dal
 * preventivo usa quello in vigore. Una copia di riserva nel codice non c'e':
 * senza un modello caricato il contratto non si genera.
 */
class DocumentiContrattiTest extends TestCase
{
    use AssignsPermissionRoles, RefreshDatabase;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');
        $this->tenant = Tenant::create(['name' => 'Alex', 'slug' => 'alex', 'is_master' => true]);
    }

    /** Un PDF vero, di pagine note: l'Easy (5 pagine) caricato come se fosse un altro modello. */
    private function carica(string $categoria, array $extra = []): PriceList
    {
        $percorso = 'price-lists/'.uniqid().'.pdf';
        Storage::disk('public')->put($percorso, file_get_contents(base_path('database/seeders/contratti/easy-service.pdf')));

        return PriceList::create([
            'tenant_id' => $this->tenant->id, 'category' => $categoria, 'name' => 'Modello', 'file_path' => $percorso, ...$extra,
        ]);
    }

    private function pagine(string $percorso): int
    {
        return (new \setasign\Fpdi\Tcpdf\Fpdi)->setSourceFile($percorso);
    }

    public function test_senza_documenti_non_c_e_modello(): void
    {
        $this->assertNull(ContrattoAssistenzaPdf::modello(ContrattoAssistenza::FULL, $this->tenant->id));
    }

    public function test_si_usa_il_contratto_caricato(): void
    {
        $doc = $this->carica(PriceList::CONTRATTO_FULL);

        $modello = ContrattoAssistenzaPdf::modello(ContrattoAssistenza::FULL, $this->tenant->id);

        $this->assertSame(Storage::disk('public')->path($doc->file_path), $modello);
        $this->assertSame(5, $this->pagine($modello));
        // Il Full non fa da Easy.
        $this->assertNull(ContrattoAssistenzaPdf::modello(ContrattoAssistenza::EASY, $this->tenant->id));
    }

    public function test_un_listino_non_e_un_contratto(): void
    {
        $this->carica(PriceList::LISTINO);

        $this->assertNull(PriceList::contrattoInVigore(ContrattoAssistenza::FULL, $this->tenant->id));
    }

    public function test_scaduti_e_futuri_non_valgono(): void
    {
        $this->carica(PriceList::CONTRATTO_FULL, ['valid_to' => now()->subDay()]);
        $this->carica(PriceList::CONTRATTO_FULL, ['valid_from' => now()->addDay()]);

        $this->assertNull(PriceList::contrattoInVigore(ContrattoAssistenza::FULL, $this->tenant->id));
    }

    public function test_vale_la_decorrenza_piu_recente_e_l_altro_e_sostituito(): void
    {
        $vecchio = $this->carica(PriceList::CONTRATTO_FULL, ['valid_from' => now()->subYear()]);
        $nuovo = $this->carica(PriceList::CONTRATTO_FULL, ['valid_from' => now()->subMonth()]);

        $this->assertTrue(PriceList::contrattoInVigore(ContrattoAssistenza::FULL, $this->tenant->id)->is($nuovo));
        $this->assertSame('in_uso', $nuovo->status());
        $this->assertSame('sostituito', $vecchio->status());
    }

    public function test_il_contratto_di_un_altro_tenant_non_si_usa(): void
    {
        $altro = Tenant::create(['name' => 'Gifar', 'slug' => 'gifar']);
        $this->carica(PriceList::CONTRATTO_FULL, ['tenant_id' => $altro->id]);

        $this->assertNull(PriceList::contrattoInVigore(ContrattoAssistenza::FULL, $this->tenant->id));
    }

    public function test_le_righe_di_prima_sono_listini(): void
    {
        $this->assertSame(PriceList::LISTINO, PriceList::create(['name' => 'Franke 2026'])->fresh()->category);
    }

    private function entraComeAdmin(): void
    {
        $utente = User::create([
            'tenant_id' => $this->tenant->id, 'name' => 'Admin', 'email' => 'admin@alex.it', 'password' => bcrypt('x'),
        ]);
        $this->giveRole($utente, $this->tenant, 'admin');
        $this->actingAs($utente);
        Filament::setTenant($this->tenant);
    }

    public function test_si_carica_un_contratto_dal_pannello(): void
    {
        $this->entraComeAdmin();

        Livewire::test(ManagePriceLists::class)
            ->callAction('create', [
                'category' => PriceList::CONTRATTO_EASY,
                'name' => 'Easy-Service rev. settembre 2026',
                'file_path' => [UploadedFile::fake()->createWithContent('easy.pdf', file_get_contents(base_path('database/seeders/contratti/easy-service.pdf')))],
            ])
            ->assertHasNoActionErrors();

        $doc = PriceList::where('category', PriceList::CONTRATTO_EASY)->sole();
        $this->assertTrue(PriceList::contrattoInVigore(ContrattoAssistenza::EASY, $this->tenant->id)->is($doc));
    }

    /** Un PDF che FPDI non sa leggere si ferma al caricamento, non al primo contratto scaricato. */
    public function test_un_pdf_illeggibile_non_si_carica_come_contratto(): void
    {
        $this->entraComeAdmin();

        Livewire::test(ManagePriceLists::class)
            ->callAction('create', [
                'category' => PriceList::CONTRATTO_FULL,
                'name' => 'Rotto',
                'file_path' => [UploadedFile::fake()->createWithContent('rotto.pdf', "%PDF-1.4\nnon e' un pdf\n%%EOF")],
            ])
            ->assertHasActionErrors(['file_path']);

        $this->assertSame(0, PriceList::count());
    }

    public function test_un_contratto_senza_pdf_non_si_salva(): void
    {
        $this->entraComeAdmin();

        Livewire::test(ManagePriceLists::class)
            ->callAction('create', ['category' => PriceList::CONTRATTO_FULL, 'name' => 'Senza file'])
            ->assertHasActionErrors(['file_path' => 'required']);
    }

    public function test_un_file_caricato_va_nella_sua_cartella_col_nome_del_documento(): void
    {
        $this->entraComeAdmin();

        Livewire::test(ManagePriceLists::class)
            ->callAction('create', [
                'category' => PriceList::LISTINO,
                'name' => 'Liebherr listino 2026',
                'file_path' => [UploadedFile::fake()->createWithContent('scan0001.pdf', file_get_contents(base_path('database/seeders/contratti/easy-service.pdf')))],
            ])
            ->assertHasNoActionErrors();

        $this->assertSame('price-lists/listini/liebherr-listino-2026.pdf', PriceList::sole()->file_path);
    }

    public function test_cambiando_categoria_il_file_cambia_cartella(): void
    {
        $doc = $this->carica(PriceList::LISTINO);
        $this->assertStringStartsWith('price-lists/listini/', $doc->file_path);

        $doc->update(['category' => PriceList::CATALOGO]);

        $this->assertStringStartsWith('price-lists/cataloghi/', $doc->fresh()->file_path);
        Storage::disk('public')->assertExists($doc->fresh()->file_path);
    }
}
