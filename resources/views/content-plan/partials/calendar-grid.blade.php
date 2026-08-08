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

    {{-- Filter Calendar: Tipe & Tanggal digabung berdekatan --}}
    <div class="flex items-center gap-6 mb-4 flex-wrap">

        <div class="flex items-center gap-2">
            <span class="text-xs font-medium text-[#9aa0a4]">Tipe:</span>

            <a href="{{ request()->fullUrlWithQuery(['view' => 'calendar', 'type' => 'all', 'date' => null]) }}" class="px-3 py-1.5 rounded-lg text-xs font-medium
               {{ ($selectedType ?? 'all') === 'all' ? 'bg-[#044b46] text-white' : 'bg-[#f2f3f6] text-[#5c6266]' }}">
                Semua
            </a>

            <a href="{{ request()->fullUrlWithQuery(['view' => 'calendar', 'type' => 'Desain']) }}"
               class="group relative overflow-hidden flex items-center justify-center h-8 w-8 hover:w-[4.5rem] rounded-lg text-xs font-medium transition-[width] duration-300 ease-out
               {{ ($selectedType ?? '') === 'Desain' ? 'bg-[#044b46] text-white' : 'bg-[#f2f3f6] text-[#5c6266]' }}">
                <span class="absolute transition-opacity duration-150 group-hover:opacity-0">D</span>
                <span class="absolute whitespace-nowrap opacity-0 transition-opacity duration-200 delay-150 group-hover:opacity-100">Desain</span>
            </a>

            <a href="{{ request()->fullUrlWithQuery(['view' => 'calendar', 'type' => 'Video']) }}"
               class="group relative overflow-hidden flex items-center justify-center h-8 w-8 hover:w-[4.5rem] rounded-lg text-xs font-medium transition-[width] duration-300 ease-out
               {{ ($selectedType ?? '') === 'Video' ? 'bg-[#044b46] text-white' : 'bg-[#f2f3f6] text-[#5c6266]' }}">
                <span class="absolute transition-opacity duration-150 group-hover:opacity-0">V</span>
                <span class="absolute whitespace-nowrap opacity-0 transition-opacity duration-200 delay-150 group-hover:opacity-100">Video</span>
            </a>
        </div>

        {{-- Filter tanggal — popup dengan dropdown bulan & tahun, ganti langsung tanpa navigasi panah --}}
        <div class="flex items-center gap-2" x-data="{
    open: false,
    selected: @js($selectedDate),
    get displayLabel() {
        if (!this.selected) return 'Semua tanggal';
        const d = new Date(this.selected);
        return d.toLocaleDateString('id-ID', { day: 'numeric', month: 'short', year: 'numeric' });
    }

}">
            <span class="text-xs font-medium text-[#9aa0a4]">Tanggal:</span>

            <div class="relative">
                <button type="button" x-on:click="open = !open"
                    class="flex items-center gap-2 border border-[#eef0f4] rounded-lg px-3 py-1.5 text-xs bg-white hover:border-[#044b46]/40 min-w-[130px]">
                    <span class="material-symbols-outlined text-[15px] text-[#9aa0a4]">calendar_today</span>
                    <span class="text-[#14181a]" x-text="displayLabel"></span>
                </button>

                <div x-show="open" x-cloak x-on:click.outside="open = false" x-transition
                    class="absolute z-20 mt-2 w-72 bg-white rounded-xl border border-[#eef0f4] shadow-lg p-4">

                    {{-- Dropdown bulan & tahun — ganti langsung, tanpa navigasi panah --}}
                    <form method="GET" class="flex items-center gap-2 mb-3">
                        <input type="hidden" name="view" value="calendar">
                        <input type="hidden" name="client_id" value="{{ $selectedClientId }}">
                        <input type="hidden" name="type" value="{{ $selectedType ?? 'all' }}">

                        <select name="month" onchange="this.form.submit()"
                            class="flex-1 border border-[#eef0f4] rounded-lg px-2 py-1.5 text-xs bg-white focus:outline-none focus:border-[#044b46]/40">
                            @foreach (range(1, 12) as $m)
                                <option value="{{ $m }}" {{ $month === $m ? 'selected' : '' }}>
                                    {{ \Carbon\Carbon::create()->month($m)->translatedFormat('F') }}
                                </option>
                            @endforeach
                        </select>

                        <select name="year" onchange="this.form.submit()"
                            class="border border-[#eef0f4] rounded-lg px-2 py-1.5 text-xs bg-white focus:outline-none focus:border-[#044b46]/40">
                            @foreach (range(now()->year - 2, now()->year + 2) as $y)
                                <option value="{{ $y }}" {{ $year === $y ? 'selected' : '' }}>{{ $y }}</option>
                            @endforeach
                        </select>
                    </form>

                    {{-- Grid tanggal untuk bulan/tahun yang sedang aktif --}}
                    <div
                        class="grid grid-cols-7 gap-1 text-center text-[10px] font-medium text-[#9aa0a4] uppercase mb-1.5">
                        <div>Su</div>
                        <div>Mo</div>
                        <div>Tu</div>
                        <div>We</div>
                        <div>Th</div>
                        <div>Fr</div>
                        <div>Sa</div>
                    </div>

                    <form method="GET" id="dateForm">
                        <input type="hidden" name="view" value="calendar">
                        <input type="hidden" name="client_id" value="{{ $selectedClientId }}">
                        <input type="hidden" name="month" value="{{ $month }}">
                        <input type="hidden" name="year" value="{{ $year }}">
                        <input type="hidden" name="type" value="{{ $selectedType ?? 'all' }}">
                        <input type="hidden" name="date" id="dateHiddenInput" value="{{ $selectedDate }}">

                        @php
                            $daysInMonthPopup = \Carbon\Carbon::create($year, $month, 1)->daysInMonth;
                            $firstDayOfWeekPopup = \Carbon\Carbon::create($year, $month, 1)->dayOfWeek;
                        @endphp

                        <div class="grid grid-cols-7 gap-1">
                            @for ($i = 0; $i < $firstDayOfWeekPopup; $i++)
                                <div></div>
                            @endfor
                            @for ($d = 1; $d <= $daysInMonthPopup; $d++)
                                @php $optionDate = \Carbon\Carbon::create($year, $month, $d)->format('Y-m-d'); @endphp
                                <button type="submit" name="date" value="{{ $optionDate }}"
                                    class="w-8 h-8 rounded-lg text-xs flex items-center justify-center hover:bg-[#f0f5f4]
                                        {{ $selectedDate === $optionDate ? 'bg-[#044b46] text-white font-semibold' : 'text-[#14181a]' }}">
                                    {{ $d }}
                                </button>
                            @endfor
                        </div>

                        <div class="flex items-center justify-between mt-3 pt-3 border-t border-[#eef0f4]">
                            <button type="submit" name="date" value=""
                                class="text-xs font-medium text-[#5c6266] hover:underline">Clear</button>
                            <button type="submit" name="date" value="{{ now()->format('Y-m-d') }}"
                                class="text-xs font-medium text-[#044b46] hover:underline">Hari ini</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>

    </div>

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