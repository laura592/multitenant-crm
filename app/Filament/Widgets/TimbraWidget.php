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
}
