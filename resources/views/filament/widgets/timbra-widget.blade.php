<x-filament-widgets::widget>
    <x-filament::section>
        <div class="flex items-center justify-between">
            <div>
                <h2 class="text-base font-semibold">Timbratura</h2>
                @php $open = $this->getOpenEntry(); @endphp
                @if($open)
                    <p class="text-sm text-gray-500">In servizio dalle {{ $open->clock_in->format('H:i') }}</p>
                @else
                    <p class="text-sm text-gray-500">Non sei in servizio</p>
                @endif
            </div>
            <div class="flex gap-2">
                <x-filament::button color="success" wire:click="clockIn" :disabled="(bool) $open">
                    Entrata
                </x-filament::button>
                <x-filament::button color="danger" wire:click="clockOut" :disabled="! $open">
                    Uscita
                </x-filament::button>
            </div>
        </div>
    </x-filament::section>
</x-filament-widgets::widget>
