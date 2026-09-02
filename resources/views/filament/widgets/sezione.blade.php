{{-- Titolo di sezione della dashboard: volutamente NON una x-filament::section
     (niente card, niente bordo), altrimenti sembrerebbe un widget vuoto in
     mezzo ai widget veri invece di intestare quelli che seguono. --}}
<x-filament-widgets::widget>
    <div class="flex items-center gap-3 pt-4 first:pt-0">
        <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg bg-gray-100 text-gray-500 dark:bg-white/5 dark:text-gray-400">
            <x-filament::icon :icon="$icona" class="h-5 w-5" />
        </span>

        <div class="min-w-0">
            <h2 class="text-sm font-bold uppercase tracking-wide text-gray-950 dark:text-white">
                {{ $titolo }}
            </h2>
            <p class="truncate text-sm text-gray-500 dark:text-gray-400">
                {{ $sottotitolo }}
            </p>
        </div>

        {{-- Filo orizzontale fino a fine riga: e' quello che fa leggere il
             blocco sotto come "appartenente" a questo titolo. --}}
        <div class="h-px flex-1 bg-gray-200 dark:bg-white/10"></div>
    </div>
</x-filament-widgets::widget>
