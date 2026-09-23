<?php

namespace Tests\Feature;

use App\Filament\Resources\OffertaCaffeResource\Pages\CreateOffertaCaffe;
use App\Models\Tenant;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Concerns\AssignsPermissionRoles;
use Tests\TestCase;

/**
 * Le griglie del pannello devono impilarsi sul telefono.
 *
 * In Filament ->columns(6) vale SOLO da "lg" in su (1024px): sotto, la
 * griglia e' a colonna unica. ->columnSpan(3) invece, scritto cosi', vale a
 * OGNI larghezza — anche dentro quella colonna unica. Il risultato e' una
 * riga larga tre colonne dentro una griglia che ne ha una: il browser crea
 * le tracce che mancano, la pagina straborda di lato e i campi si
 * schiacciano. E' il motivo per cui "le offerte caffe' a mobile si vedono
 * male" (segnalato il 23/09/2026); toccava anche preventivi, rapportini,
 * piani manutenzione e automezzi.
 *
 * La regola: lo span deve portare lo stesso breakpoint della sua griglia,
 * ->columnSpan(['default' => 1, 'lg' => 3]). Era gia' cosi' nel modulo a
 * passi dei rapportini (RapportiniAPassi, con 'md' perche' la' la griglia e'
 * Grid::make(['default' => 1, 'md' => 3])): qui si estende a tutto il resto.
 */
class LayoutSuTelefonoTest extends TestCase
{
    use AssignsPermissionRoles, RefreshDatabase;

    /**
     * Guardia sul codice: un ->columnSpan(3) scritto a mano non si vede a
     * occhio in una form di trecento righe, e il danno si scopre solo con un
     * telefono in mano. Qui si scopre subito.
     */
    public function test_nessuno_span_senza_breakpoint_nel_pannello(): void
    {
        $colpevoli = [];

        $file = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(app_path('Filament')));

        foreach ($file as $f) {
            if ($f->getExtension() !== 'php') {
                continue;
            }

            foreach (file($f->getPathname()) as $n => $riga) {
                // columnSpan(1) va bene (in una griglia a una colonna e'
                // esattamente la larghezza piena), come columnSpanFull() e
                // ogni forma con l'array dei breakpoint.
                if (preg_match('/->columnSpan\((\d+)\)/', $riga, $m) && (int) $m[1] > 1) {
                    $colpevoli[] = str_replace(base_path().'/', '', $f->getPathname()).':'.($n + 1);
                }
            }
        }

        $this->assertSame([], $colpevoli, "Questi span valgono anche sul telefono, dove la griglia ha una colonna sola.\nUsa ->columnSpan(['default' => 1, 'lg' => N]):\n".implode("\n", $colpevoli));
    }

    /**
     * E la prova sul reso: l'HTML vero della schermata da cui e' partita la
     * segnalazione. Su telefono ogni elemento occupa la sua unica colonna.
     */
    public function test_l_offerta_caffe_si_impila_sul_telefono(): void
    {
        $tenant = Tenant::create(['name' => 'Alex', 'slug' => 'alex', 'is_master' => true]);
        $utente = User::create(['tenant_id' => $tenant->id, 'name' => 'A', 'email' => 'a@alex.it', 'password' => bcrypt('x')]);
        $this->giveRole($utente, $tenant, 'admin');
        $this->actingAs($utente);
        Filament::setTenant($tenant);

        $html = Livewire::test(CreateOffertaCaffe::class)->html();

        preg_match_all('/--col-span-default:\s*span (\d+)/', $html, $suTelefono);
        $this->assertSame(
            [],
            array_values(array_unique(array_filter($suTelefono[1], fn (string $n) => (int) $n > 1))),
            'Su telefono la griglia ha una colonna sola: nessuno deve chiederne di piu\'.'
        );

        // La griglia larga non e' sparita: da lg in su c'e' ancora.
        preg_match_all('/--col-span-lg:\s*span (\d+)/', $html, $daLg);
        $this->assertNotEmpty($daLg[1], 'Da schermo grande le colonne affiancate devono restare.');
    }
}
