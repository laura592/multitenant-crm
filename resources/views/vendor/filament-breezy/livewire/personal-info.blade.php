<x-filament-breezy::grid-section :title="__('filament-breezy::default.profile.personal_info.heading')" :description="__('filament-breezy::default.profile.personal_info.subheading')">
    <form wire:submit.prevent="submit" class="space-y-6">

        {{ $this->form }}

        <div class="text-right">
            <x-filament::button type="submit" form="submit" class="align-right">
                {{ __('filament-breezy::default.profile.personal_info.submit.label') }}
            </x-filament::button>
        </div>
    </form>
</x-filament-breezy::grid-section>
