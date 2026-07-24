<?php

namespace App\Providers;

use App\Models\User;
use Filament\Forms\Components\DatePicker;
use Filament\Infolists\Infolist;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Jeffgreco13\FilamentBreezy\Livewire\PersonalInfo;
use Jeffgreco13\FilamentBreezy\Livewire\TwoFactorAuthentication;
use Jeffgreco13\FilamentBreezy\Livewire\UpdatePassword;
use Livewire\Livewire;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Staff Alex (is_super_admin): bypassa i permessi Shield/spatie in ogni
        // tenant in cui opera. E' un flag distinto dai ruoli per-tenant di
        // §5.3 (docs/architecture.md) - i ruoli restano scoped per team/tenant,
        // questo e solo per lo staff master che deve avere accesso completo
        // ovunque, senza dover replicare un'assegnazione di ruolo per ogni tenant.
        Gate::before(fn (User $user) => $user->is_super_admin ? true : null);

        // Bug segnalato: i campi data nei form mostravano il formato nativo
        // del browser (es. 07/20/2026, mese/giorno USA) invece che italiano,
        // perche' un <input type=date> nativo lo decide il browser/OS, non
        // l'app. Disattivando il nativo su ogni DatePicker dell'app (una sola
        // volta, qui) si ottiene un formato coerente ovunque senza doverlo
        // ripetere Resource per Resource. L'icona calendario esplicita serve
        // perche' senza nativo il campo non e' piu' riconoscibile a colpo
        // d'occhio come selettore data (segnalato su Automezzi, ma il fix va
        // qui perche' vale per ogni DatePicker dell'app).
        DatePicker::configureUsing(function (DatePicker $component) {
            $component->native(false)->displayFormat('d/m/Y')->prefixIcon('heroicon-o-calendar');
        });

        // Stesso bug del DatePicker sopra, ma per le colonne/entry ->date()
        // di tabelle e infolist: senza un formato esplicito usano il default
        // Filament in inglese ("Jul 23, 2026") invece che italiano, in modo
        // incoerente con i DatePicker dei form (gia' 'd/m/Y'). Questi sono i
        // default usati SOLO quando ->date() e' chiamato senza un formato
        // esplicito, quindi non tocca le colonne che gia' passano un formato
        // proprio (es. ->dateTime('d/m/Y H:i')).
        Table::$defaultDateDisplayFormat = 'd/m/Y';
        Infolist::$defaultDateDisplayFormat = 'd/m/Y';

        // Bug: cliccare "Abilita" sulla 2FA (o salvare nome/password) in
        // /my-profile falliva sempre con 419. filament-breezy registra questi
        // alias Livewire dentro BreezyCore::boot(Panel), che Filament esegue
        // solo per richieste instradate su una rotta con prefisso pannello
        // (via il middleware SetUpPanel). Il POST che Livewire usa per ogni
        // azione/interazione va pero' alla rotta globale /livewire/update,
        // che non ha quel middleware: l'alias non risultava mai registrato e
        // Livewire rispondeva "componente non trovato" con lo stesso status
        // HTTP (419) di un CSRF scaduto. Registrandoli qui (un ServiceProvider
        // di app, sempre eseguito) l'alias esiste per qualunque richiesta.
        Livewire::component('personal_info', PersonalInfo::class);
        Livewire::component('update_password', UpdatePassword::class);
        Livewire::component('two_factor_authentication', TwoFactorAuthentication::class);
    }
}
