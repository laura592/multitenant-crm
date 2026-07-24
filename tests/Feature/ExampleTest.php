<?php

namespace Tests\Feature;

// use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExampleTest extends TestCase
{
    /**
     * L'app non ha una home page pubblica (e' un gestionale interno, unico
     * ingresso e' il pannello Filament): "/" reindirizza sempre a "/admin",
     * vedi routes/web.php. Il test scaffold originale si aspettava ancora la
     * welcome page di Laravel, gia' rimossa.
     */
    public function test_root_redirects_to_the_admin_panel(): void
    {
        $response = $this->get('/');

        $response->assertRedirect('/admin');
    }
}
