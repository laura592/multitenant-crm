<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Concerns\AssignsPermissionRoles;
use Tests\TestCase;

/**
 * Quante colonne resta a vedere chi apre una lista dal telefono.
 *
 * Una tabella da sette-undici colonne su uno schermo da 360px scorre di
 * lato: per leggere lo stato di un'offerta bisogna trascinare la tabella,
 * e quello che serve per decidere sta fuori campo. Le colonne secondarie
 * hanno ->visibleFrom('md'): restano nel DOM (la ricerca continua a
 * trovarle) ma compaiono da tablet in su.
 *
 * Questo test guarda le colonne vere costruite da Filament, non il codice
 * sorgente: il limite serve a non far ricrescere le liste una colonna alla
 * volta, e il minimo a non ritrovarsi una lista vuota sul telefono per una
 * riga di troppo.
 */
class ListeSuTelefonoTest extends TestCase
{
    use AssignsPermissionRoles, RefreshDatabase;

    /** Oltre questo, sul telefono si torna a trascinare di lato. */
    private const MASSIMO_SU_TELEFONO = 4;

    public function test_ogni_lista_sta_in_un_telefono(): void
    {
        $tenant = Tenant::create(['name' => 'Alex', 'slug' => 'alex', 'is_master' => true]);
        $utente = User::create([
            'tenant_id' => $tenant->id, 'name' => 'Test Admin', 'email' => 'admin@alex.it',
            'password' => bcrypt('password'), 'is_super_admin' => true,
        ]);
        $this->giveRole($utente, $tenant, 'admin');
        $this->actingAs($utente);
        Filament::setTenant($tenant);

        $troppoLarghe = [];
        $vuote = [];

        foreach (Filament::getPanel('admin')->getResources() as $risorsa) {
            $pagina = $risorsa::getPages()['index'] ?? null;

            if (! $pagina) {
                continue;
            }

            $componente = $pagina->getPage();

            try {
                $tabella = Livewire::test($componente)->instance()->getTable();
            } catch (\Throwable) {
                // Pagine indice che non sono tabelle (o che vogliono un
                // contesto loro): non e' questo il test che se ne occupa.
                continue;
            }

            $suTelefono = collect($tabella->getColumns())
                ->reject(fn ($colonna) => $colonna->isToggledHiddenByDefault())
                ->reject(fn ($colonna) => $colonna->getVisibleFrom() !== null)
                ->keys();

            $nome = class_basename($risorsa);

            if ($suTelefono->count() > self::MASSIMO_SU_TELEFONO) {
                $troppoLarghe[] = $nome.' ('.$suTelefono->count().': '.$suTelefono->implode(', ').')';
            }

            if ($suTelefono->isEmpty() && $tabella->getColumns() !== []) {
                $vuote[] = $nome;
            }
        }

        $this->assertSame([], $vuote, "Su telefono queste liste non mostrerebbero nessuna colonna:\n".implode("\n", $vuote));
        $this->assertSame(
            [],
            $troppoLarghe,
            "Queste liste hanno piu' di ".self::MASSIMO_SU_TELEFONO." colonne sul telefono.\nMetti ->visibleFrom('md') sulle secondarie:\n".implode("\n", $troppoLarghe)
        );
    }
}
