<?php

namespace App\Livewire;

use App\Models\TourView;
use App\Support\HelpGuide\TourRegistry;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\On;
use Livewire\Component;

/**
 * Tour guidato a scomparsa (icona accanto alla campana notifiche, vedi il
 * render hook 'panels::topbar.end' in AdminPanelProvider): per la pagina
 * corrente evidenzia gli elementi reali uno alla volta (vedi
 * TourRegistry). Parte da solo alla prima visita di ogni utente su ogni
 * pagina (App\Models\TourView tiene traccia di quali ha gia' visto);
 * l'icona lo rilancia a mano in qualunque momento dopo.
 */
class TourGuide extends Component
{
    public ?string $slug = null;

    public bool $hasSteps = false;

    public function mount(): void
    {
        $this->slug = $this->resolveSlugFromRoute();
        $this->hasSteps = $this->slug && TourRegistry::forSlug($this->slug) !== null;

        if (! $this->hasSteps) {
            return;
        }

        $alreadySeen = TourView::where('user_id', Auth::id())
            ->where('page_slug', $this->slug)
            ->exists();

        if (! $alreadySeen) {
            $this->dispatchTour();
        }
    }

    public function startTour(): void
    {
        $this->dispatchTour();
    }

    #[On('tour-finished')]
    public function markAsSeen(string $slug): void
    {
        if ($slug !== $this->slug) {
            return;
        }

        // Filament::getTenant() (il tenant del pannello attivo), non
        // Auth::user()->tenant_id: un account super-admin ha tenant_id NULL
        // sul proprio utente per design (puo' entrare in qualsiasi tenant,
        // vedi UserResource — Hidden::make('tenant_id')->dehydrated(fn () =>
        // ! is_super_admin)), quindi con l'utente falliva qui con una
        // violazione NOT NULL su tour_views.tenant_id ad ogni chiusura del
        // tour, su ogni pagina — successo davvero in produzione 2026-08-25.
        TourView::firstOrCreate(
            ['user_id' => Auth::id(), 'page_slug' => $slug],
            ['tenant_id' => Filament::getTenant()?->id, 'viewed_at' => now()],
        );
    }

    public function render()
    {
        return view('livewire.tour-guide');
    }

    private function dispatchTour(): void
    {
        $this->dispatch('start-tour', steps: TourRegistry::forSlug($this->slug), slug: $this->slug);
    }

    private function resolveSlugFromRoute(): ?string
    {
        $routeName = request()->route()?->getName();

        if (! $routeName) {
            return null;
        }

        if (preg_match('/filament\.[^.]+\.resources\.([^.]+)\./', $routeName, $matches)) {
            return $matches[1];
        }

        if (preg_match('/filament\.[^.]+\.pages\.([^.]+)$/', $routeName, $matches)) {
            return $matches[1];
        }

        return null;
    }
}
