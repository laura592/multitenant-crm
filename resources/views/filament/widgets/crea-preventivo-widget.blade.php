<x-filament-widgets::widget>
    <x-filament::section
        :class="'rounded-2xl border border-slate-200 bg-gradient-to-br from-slate-50 via-white to-sky-50 shadow-sm dark:border-slate-800 dark:from-slate-900 dark:via-slate-950 dark:to-slate-900'"
    >
        <div class="flex items-center justify-between gap-4">
            <div>
                <h2 class="text-base font-semibold">Nuovo preventivo</h2>
                <p class="text-sm text-gray-500">Crea rapidamente un preventivo per un cliente</p>
            </div>
            <x-filament::button tag="a" :href="$this->getCreateUrl()" icon="heroicon-o-document-plus" size="lg">
                Crea preventivo
            </x-filament::button>
        </div>
    </x-filament::section>
</x-filament-widgets::widget>
