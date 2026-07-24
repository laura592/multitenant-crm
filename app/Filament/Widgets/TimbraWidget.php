<?php

namespace App\Filament\Widgets;

use App\Models\TimeEntry;
use BezhanSalleh\FilamentShield\Traits\HasWidgetShield;
use Filament\Notifications\Notification;
use Filament\Widgets\Widget;
use Illuminate\Support\Facades\Auth;

class TimbraWidget extends Widget
{
    use HasWidgetShield;

    protected static string $view = 'filament.widgets.timbra-widget';

    protected int|string|array $columnSpan = 'full';

    protected static ?int $sort = -1;

    public function getOpenEntry(): ?TimeEntry
    {
        return TimeEntry::where('user_id', Auth::id())->whereNull('clock_out')->latest('clock_in')->first();
    }

    /**
     * Ultima timbratura chiusa OGGI (se c'e' e non e' aperta una nuova):
     * distingue "in pausa pranzo, rientra piu' tardi" da "non e' mai entrato
     * oggi" nel widget, anche se a livello di dati sono entrambe "nessuna
     * timbratura aperta" (vedi nota su clockOutForBreak/clockOut).
     */
    public function getLastClosedEntryToday(): ?TimeEntry
    {
        return TimeEntry::where('user_id', Auth::id())
            ->whereNotNull('clock_out')
            ->whereDate('clock_in', today())
            ->latest('clock_in')
            ->first();
    }

    public function clockIn(): void
    {
        if ($this->getOpenEntry()) {
            return;
        }

        TimeEntry::create([
            'user_id' => Auth::id(),
            'clock_in' => now(),
            'source' => 'app',
            'status' => 'aperta',
        ]);

        Notification::make()->title('Entrata registrata alle '.now()->format('H:i'))->success()->send();
    }

    public function clockOut(): void
    {
        $entry = $this->getOpenEntry();

        if (! $entry) {
            return;
        }

        $entry->update(['clock_out' => now(), 'status' => 'chiusa']);

        Notification::make()->title('Uscita registrata alle '.now()->format('H:i'))->success()->send();
    }

    /**
     * A livello di dati e' identica a clockOut() (chiude la timbratura
     * aperta): il riepilogo mensile gia' somma le ore per giorno su piu'
     * timbrature separate, quindi il vuoto fra questa chiusura e la
     * prossima Entrata viene escluso automaticamente dalle ore lavorate,
     * senza bisogno di un campo dedicato. Un metodo/bottone distinto serve
     * solo a rendere chiaro al dipendente che quella e' la timbratura da
     * usare per la pausa pranzo (altrimenti rischia di trattare l'intera
     * giornata come un turno unico e la pausa risulterebbe pagata).
     */
    public function clockOutForBreak(): void
    {
        $entry = $this->getOpenEntry();

        if (! $entry) {
            return;
        }

        $entry->update(['clock_out' => now(), 'status' => 'chiusa']);

        Notification::make()->title('Pausa pranzo registrata alle '.now()->format('H:i'))->success()->send();
    }
}
