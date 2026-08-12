<div x-data="{ expandedDate: null }">

    @php
        $daysInMonth = \Carbon\Carbon::create($year, $month, 1)->daysInMonth;
        $firstDayOfWeek = \Carbon\Carbon::create($year, $month, 1)->dayOfWeek;
        $maxVisibleClients = 1;

        $typeBadge = fn(?string $typeName) => match ($typeName) {
            'Video' => 'V',
            'Desain' => 'D',
            default => null,
        };

        $fallbackColor = function (int $clientId) {
            $palette = [
                '#044b46',
                '#3452a8',
                '#b8873a',
                '#b3427e',
                '#7c5cbf',
                '#0e7490'
            ];

            return $palette[$clientId % count($palette)];
        };
    @endphp

    {{-- Legenda warna client --}}
    <div class="flex flex-wrap gap-3 mb-4">
        @foreach ($clientOptions as $c)
            <span class="flex items-center gap-1.5 text-xs text-[#5c6266]">
                <span class="w-2.5 h-2.5 rounded-full"
                    style="background-color: {{ $c->color ?? $fallbackColor($c->id) }}"></span>
                {{ $c->name }}
            </span>
        @endforeach
    </div>

    <div class="card p-5">
        <div class="grid grid-cols-7 gap-2 text-center text-[11px] font-medium text-[#9aa0a4] uppercase mb-2">
            @foreach (['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'] as $d)
                <div>{{ $d }}</div>
            @endforeach
        </div>

        <div class="grid grid-cols-7 gap-2">
            @for ($i = 0; $i < $firstDayOfWeek; $i++)
                <div></div>
            @endfor

            @for ($day = 1; $day <= $daysInMonth; $day++)
                @php
                    $dateKey = \Carbon\Carbon::create($year, $month, $day)->format('Y-m-d');
                    $clientGroups = $itemsByDateClient->get($dateKey, collect());
                    $visibleGroups = $clientGroups->take($maxVisibleClients);
                    $overflowCount = max(0, $clientGroups->count() - $maxVisibleClients);
                @endphp

                <div class="border border-[#f2f3f6] rounded-lg p-2 min-h-[100px] flex flex-col gap-1">

                    <p class="text-xs text-[#9aa0a4] mb-0.5">{{ $day }}</p>

                    @foreach ($visibleGroups as $clientId => $clientItems)
                        @php
                            $client = $clientItems->first()->client;
                            $color = $client->color ?? $fallbackColor($clientId);
                        @endphp

                        @foreach ($clientItems as $item)
                            <a href="{{ route('content-items.show', $item) }}"
                                class="flex items-center justify-between gap-1.5 rounded-md px-2 py-1 hover:opacity-80 transition-opacity"
                                style="background-color: {{ $color }}14; border-left: 3px solid {{ $color }}">

                                <span class="text-[11px] font-semibold truncate" style="color: {{ $color }}">
                                    {{ $client->name }}
                                </span>

                                <span
                                    title="{{ $item->contentType->name ?? '-' }}"
                                    class="w-4 h-4 rounded flex items-center justify-center text-white text-[10px] font-semibold shrink-0 cursor-help"
                                    style="background-color: {{ $color }}">
                                    {{ $typeBadge($item->contentType->name ?? null) }}
                                </span>
                            </a>
                        @endforeach
                    @endforeach

                    @if ($overflowCount > 0)
                        <button type="button"
                            x-on:click="expandedDate = (expandedDate === '{{ $dateKey }}' ? null : '{{ $dateKey }}')"
                            class="text-[11px] font-medium text-[#044b46] text-left hover:underline mt-0.5">
                            <span x-show="expandedDate !== '{{ $dateKey }}'">+{{ $overflowCount }} lainnya</span>
                            <span x-show="expandedDate === '{{ $dateKey }}'" x-cloak>Sembunyikan</span>
                        </button>
                    @endif

                    <div x-show="expandedDate === '{{ $dateKey }}'" x-cloak x-transition
                        class="mt-1 pt-1.5 border-t border-[#eef0f4] flex flex-col gap-1">

                        @foreach ($clientGroups as $clientId => $clientItems)
                            @php
                                $client = $clientItems->first()->client;
                                $color = $client->color ?? $fallbackColor($clientId);
                            @endphp

                            @foreach ($clientItems as $item)
                                <a href="{{ route('content-items.show', $item) }}"
                                    class="flex items-center justify-between gap-1.5 rounded-md px-2 py-1 hover:opacity-80 transition-opacity"
                                    style="background-color: {{ $color }}14; border-left: 3px solid {{ $color }}">

                                    <span class="text-[11px] font-semibold truncate" style="color: {{ $color }}">
                                        {{ $client->name }}
                                    </span>

                                    <span
                                        title="{{ $item->contentType->name ?? '-' }}"
                                        class="w-4 h-4 rounded flex items-center justify-center text-white text-[10px] font-semibold shrink-0 cursor-help"
                                        style="background-color: {{ $color }}">
                                        {{ $typeBadge($item->contentType->name ?? null) }}
                                    </span>
                                </a>
                            @endforeach
                        @endforeach

                    </div>

                </div>
            @endfor
        </div>
    </div>
</div>