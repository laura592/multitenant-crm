<x-filament-widgets::widget>
    <x-filament::section>
        <x-slot name="heading">
            Piani in scadenza
        </x-slot>

        @php
            [$left, $right] = $this->getColumnsSplit();
            $columns = array_filter([$left, $right]);
        @endphp

        @if($left->isEmpty())
            <p class="text-sm text-gray-500">Nessun piano in scadenza.</p>
        @else
            <div class="grid grid-cols-1 md:grid-cols-2">
                @foreach($columns as $i => $column)
                    @if($column->isNotEmpty())
                        <div @class(['md:border-l md:border-gray-200 md:pl-6 md:dark:border-white/10' => $i === 1, 'md:pr-6' => $i === 0])>
                            <table class="w-full text-sm">
                                <thead>
                                    <tr class="border-b border-gray-200 dark:border-white/10">
                                        <th class="px-3 py-3.5 text-start text-sm font-semibold text-gray-950 first:ps-0 dark:text-white">Cliente</th>
                                        <th class="px-3 py-3.5 text-start text-sm font-semibold text-gray-950 dark:text-white">Impianto</th>
                                        <th class="px-3 py-3.5 text-start text-sm font-semibold text-gray-950 last:pe-0 dark:text-white">Scadenza</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-gray-100 dark:divide-white/5">
                                    @foreach($column as $schedule)
                                        <tr
                                            x-on:click="window.location = @js($this->recordUrl($schedule))"
                                            class="cursor-pointer transition duration-75 hover:bg-gray-50 dark:hover:bg-white/5"
                                        >
                                            <td class="px-3 py-4 first:ps-0">
                                                <span class="font-medium text-gray-950 group-hover:underline dark:text-white">
                                                    {{ \App\Support\DisplayName::titleCase($schedule->customer?->company_name) }}
                                                </span>
                                            </td>
                                            <td class="px-3 py-4">
                                                <x-filament::badge :color="$this->beverageColor($schedule)">
                                                    {{ $this->impiantoHero($schedule) }}
                                                </x-filament::badge>
                                            </td>
                                            <td class="px-3 py-4 last:pe-0">
                                                <span @class([
                                                    'inline-flex items-center gap-1 whitespace-nowrap font-medium',
                                                    'text-danger-600 dark:text-danger-400' => $schedule->next_due_date?->isPast(),
                                                    'text-gray-700 dark:text-gray-300' => ! $schedule->next_due_date?->isPast(),
                                                ])>
                                                    @if($schedule->next_due_date?->isPast())
                                                        <x-filament::icon icon="heroicon-m-exclamation-triangle" class="h-4 w-4" />
                                                    @endif
                                                    {{ $schedule->next_due_date?->translatedFormat('d M Y') }}
                                                </span>
                                            </td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    @endif
                @endforeach
            </div>
        @endif
    </x-filament::section>
</x-filament-widgets::widget>
