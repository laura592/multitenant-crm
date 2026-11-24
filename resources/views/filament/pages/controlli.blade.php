@php
    // Classi scritte per esteso e non costruite in PHP: resources/css/app.css
    // scansiona i .blade.php ma NON app/**, quindi una classe composta a
    // codice viene buttata via da Tailwind al build. E niente bg-info-*,
    // bg-success-*, bg-warning-*: non esistono nel CSS precompilato di
    // Filament, solo la tavolozza standard esiste davvero.
    [$daFare, $aPosto] = $this->divisi();
@endphp

<x-filament-panels::page>
    @if ($daFare->isNotEmpty())
        <div class="grid gap-4 sm:grid-cols-2">
            @foreach ($daFare as $riga)
                @php($c = $riga['controllo'])
                <div class="rounded-xl border border-amber-300 bg-amber-50 p-4 dark:border-amber-700 dark:bg-amber-950">
                    <div class="flex items-start gap-3">
                        <x-filament::icon
                            :icon="$c->icona"
                            class="mt-0.5 h-6 w-6 shrink-0 text-amber-600 dark:text-amber-400"
                        />
                        <div class="min-w-0 flex-1">
                            <div class="flex items-baseline justify-between gap-3">
                                <h3 class="font-semibold text-gray-900 dark:text-gray-100">
                                    {{ $c->titolo }}
                                </h3>
                                <span class="shrink-0 rounded-md bg-amber-200 px-2 py-0.5 text-sm font-semibold text-amber-900 dark:bg-amber-800 dark:text-amber-100">
                                    {{ $riga['quanti'] }}
                                </span>
                            </div>

                            <p class="mt-1 text-sm text-gray-600 dark:text-gray-400">
                                {{ $c->spiegazione }}
                            </p>

                            @if ($c->url())
                                <a
                                    href="{{ $c->url() }}"
                                    class="mt-3 inline-flex items-center gap-1 text-sm font-medium text-amber-700 hover:underline dark:text-amber-300"
                                >
                                    {{ $c->azione }}
                                    <x-filament::icon icon="heroicon-m-arrow-right" class="h-4 w-4" />
                                </a>
                            @endif
                        </div>
                    </div>
                </div>
            @endforeach
        </div>
    @endif

    @if ($aPosto->isNotEmpty())
        <div class="mt-2">
            {{-- I controlli a posto restano in pagina. Uno zero qui non e'
                 "nessun risultato" ma "questa cosa e' sotto controllo": se
                 sparissero, non si saprebbe piu' che vengono controllati. --}}
            <h2 class="mb-3 text-sm font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">
                A posto
            </h2>

            <div class="grid gap-2 sm:grid-cols-2">
                @foreach ($aPosto as $riga)
                    @php($c = $riga['controllo'])
                    <div class="flex items-center gap-3 rounded-lg border border-gray-200 bg-white px-4 py-3 dark:border-gray-700 dark:bg-gray-900">
                        <x-filament::icon
                            icon="heroicon-o-check-circle"
                            class="h-5 w-5 shrink-0 text-emerald-500"
                        />
                        <div class="min-w-0">
                            <div class="text-sm font-medium text-gray-900 dark:text-gray-100">
                                {{ $c->titolo }}
                            </div>
                            <div class="text-xs text-gray-500 dark:text-gray-400">
                                {{ $c->quandoZero }}
                            </div>
                        </div>
                    </div>
                @endforeach
            </div>
        </div>
    @endif

    @if ($daFare->isEmpty() && $aPosto->isEmpty())
        <div class="rounded-xl border border-gray-200 bg-white p-6 text-center dark:border-gray-700 dark:bg-gray-900">
            <p class="text-sm text-gray-500 dark:text-gray-400">
                Non hai i permessi per nessuno dei controlli disponibili.
            </p>
        </div>
    @endif
</x-filament-panels::page>
