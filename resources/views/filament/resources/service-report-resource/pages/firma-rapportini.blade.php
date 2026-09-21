<x-filament-panels::page>
    <form wire:submit="firma" class="space-y-6">
        {{ $this->form }}

        <div class="flex flex-wrap items-center gap-3">
            <x-filament::button type="submit" size="lg" color="success" icon="heroicon-o-pencil-square">
                Firma i rapportini scelti
            </x-filament::button>
            <x-filament::link :href="\App\Filament\Resources\ServiceReportResource::getUrl('index')" color="gray">
                Annulla
            </x-filament::link>
        </div>
    </form>
</x-filament-panels::page>
