{{-- wrapper sempre presente: Livewire richiede esattamente un elemento
     radice, un @if nudo che a volte non rende nulla (pagine ancora senza
     un tour in TourRegistry) e' un "missing root tag" fatale — successo
     davvero, vedi log 2026-08-24. --}}
<div>
    @if($hasSteps)
        <button
            type="button"
            wire:click="startTour"
            title="Rifai il tour guidato di questa pagina"
            class="fi-tour-guide-btn relative flex h-9 w-9 items-center justify-center rounded-lg text-gray-500 outline-none transition duration-75 hover:bg-gray-100 hover:text-gray-700 focus-visible:ring-2 focus-visible:ring-primary-600"
        >
            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" class="h-6 w-6">
                <path stroke-linecap="round" stroke-linejoin="round" d="M9.879 7.519c1.171-1.025 3.071-1.025 4.242 0 1.172 1.025 1.172 2.687 0 3.712-.203.179-.43.326-.67.442-.745.361-1.45.999-1.45 1.827v.75M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z" />
                <path stroke-linecap="round" stroke-linejoin="round" d="M12 17.25h.007v.008H12v-.008Z" />
            </svg>
        </button>
    @endif
</div>
