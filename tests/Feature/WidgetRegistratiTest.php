<?php

namespace Tests\Feature;

use Filament\Widgets\Widget;
use Illuminate\Support\Facades\File;
use Livewire\Mechanisms\ComponentRegistry;
use Tests\TestCase;

/**
 * Ogni widget usato dentro una Page DEVE essere risolvibile da Livewire.
 *
 * Filament registra come componenti Livewire solo i widget dichiarati in
 * Panel::widgets() o Resource::getWidgets(): quelli usati soltanto in
 * Page::getHeaderWidgets() restano sconosciuti. Quando uno di questi si
 * carica in modo lazy, Livewire non trova il componente e solleva
 * LivewireReleaseTokenMismatchException — che arriva al browser come 419 e
 * che resources/js/app.js traduce in "sessione scaduta".
 *
 * E' successo tre volte in due giorni, ogni volta con una diagnosi diversa e
 * sbagliata, perche' il sintomo (sessione scaduta) non ha niente a che
 * vedere con la causa (componente non registrato). Questo test rende il
 * problema visibile qui invece che all'utente.
 */
class WidgetRegistratiTest extends TestCase
{
    public function test_ogni_widget_dell_app_e_risolvibile_da_livewire(): void
    {
        $registry = app(ComponentRegistry::class);
        $irrisolti = [];

        foreach (File::allFiles(app_path('Filament/Widgets')) as $file) {
            $classe = 'App\\Filament\\Widgets\\'.str_replace(
                ['/', '.php'], ['\\', ''],
                $file->getRelativePathname(),
            );

            if (! class_exists($classe) || ! is_subclass_of($classe, Widget::class)) {
                continue;
            }

            // Le classi astratte sono basi condivise (es. SezioneWidget), non
            // componenti: Livewire non puo' istanziarle e non vanno registrate.
            if ((new \ReflectionClass($classe))->isAbstract()) {
                continue;
            }

            try {
                $registry->getClass($registry->getName($classe));
            } catch (\Throwable $e) {
                $irrisolti[] = class_basename($classe);
            }
        }

        $this->assertSame([], $irrisolti,
            'Widget non registrati con Livewire: '.implode(', ', $irrisolti)
            .". Aggiungili all'elenco in AppServiceProvider, altrimenti il caricamento lazy"
            .' fallisce con un 419 che l\'utente legge come "sessione scaduta".');
    }
}
