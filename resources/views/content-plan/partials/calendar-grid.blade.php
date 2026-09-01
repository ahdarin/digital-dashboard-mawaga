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

        // "Sudah Dikerjakan" = current_status uploaded, "Telat Dikerjakan" =
        // is_overdue (dan belum uploaded), "Belum Dikerjakan" = sisanya -
        // disamakan dengan definisi WorkflowTransitions::DONE_STATUSES &
        // is_overdue yang dipakai modul lain (Dashboard, Team Performance).
        $statusMeta = function ($workflow) {
            if (! $workflow) {
                return ['label' => 'Belum Dikerjakan', 'color' => '#9aa0a4'];
            }

            if ($workflow->current_status === 'uploaded') {
                return ['label' => 'Sudah Dikerjakan', 'color' => '#0f7a5f'];
            }

            if ($workflow->is_overdue) {
                return ['label' => 'Telat Dikerjakan', 'color' => '#c0392b'];
            }

            return ['label' => 'Belum Dikerjakan', 'color' => '#9aa0a4'];
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
            <span class="text-xs font-medium text-[var(--text-muted)]">Tipe:</span>

            <a href="{{ request()->fullUrlWithQuery(['view' => 'calendar', 'type' => 'all', 'date' => null]) }}" class="px-3 py-1.5 rounded-lg text-xs font-medium
               {{ ($selectedType ?? 'all') === 'all' ? 'bg-[var(--brand-solid)] text-white' : 'bg-[var(--surface-muted)] text-[var(--text-secondary)]' }}">
                Semua
            </a>

            <a href="{{ request()->fullUrlWithQuery(['view' => 'calendar', 'type' => 'Desain']) }}"
               class="group relative overflow-hidden flex items-center justify-center h-8 w-8 hover:w-[4.5rem] rounded-lg text-xs font-medium transition-[width] duration-300 ease-out
               {{ ($selectedType ?? '') === 'Desain' ? 'bg-[var(--brand-solid)] text-white' : 'bg-[var(--surface-muted)] text-[var(--text-secondary)]' }}">
                <span class="absolute transition-opacity duration-150 group-hover:opacity-0">D</span>
                <span class="absolute whitespace-nowrap opacity-0 transition-opacity duration-200 delay-150 group-hover:opacity-100">Desain</span>
            </a>

            <a href="{{ request()->fullUrlWithQuery(['view' => 'calendar', 'type' => 'Video']) }}"
               class="group relative overflow-hidden flex items-center justify-center h-8 w-8 hover:w-[4.5rem] rounded-lg text-xs font-medium transition-[width] duration-300 ease-out
               {{ ($selectedType ?? '') === 'Video' ? 'bg-[var(--brand-solid)] text-white' : 'bg-[var(--surface-muted)] text-[var(--text-secondary)]' }}">
                <span class="absolute transition-opacity duration-150 group-hover:opacity-0">V</span>
                <span class="absolute whitespace-nowrap opacity-0 transition-opacity duration-200 delay-150 group-hover:opacity-100">Video</span>
            </a>
        </div>

        <div class="flex items-center gap-2">
            <span class="text-xs font-medium text-[var(--text-muted)]">Status:</span>

            <a href="{{ request()->fullUrlWithQuery(['view' => 'calendar', 'status' => 'all']) }}" class="px-3 py-1.5 rounded-lg text-xs font-medium
               {{ ($selectedStatus ?? 'all') === 'all' ? 'bg-[var(--brand-solid)] text-white' : 'bg-[var(--surface-muted)] text-[var(--text-secondary)]' }}">
                Semua
            </a>

            <a href="{{ request()->fullUrlWithQuery(['view' => 'calendar', 'status' => 'done']) }}" class="flex items-center gap-1.5 px-3 py-1.5 rounded-lg text-xs font-medium
               {{ ($selectedStatus ?? '') === 'done' ? 'bg-[var(--brand-solid)] text-white' : 'bg-[var(--surface-muted)] text-[var(--text-secondary)]' }}">
                <span class="w-2 h-2 rounded-full shrink-0" style="background-color: {{ ($selectedStatus ?? '') === 'done' ? '#fff' : '#0f7a5f' }}"></span>
                Sudah Dikerjakan
            </a>

            <a href="{{ request()->fullUrlWithQuery(['view' => 'calendar', 'status' => 'not_done']) }}" class="flex items-center gap-1.5 px-3 py-1.5 rounded-lg text-xs font-medium
               {{ ($selectedStatus ?? '') === 'not_done' ? 'bg-[var(--brand-solid)] text-white' : 'bg-[var(--surface-muted)] text-[var(--text-secondary)]' }}">
                <span class="w-2 h-2 rounded-full shrink-0" style="background-color: {{ ($selectedStatus ?? '') === 'not_done' ? '#fff' : '#9aa0a4' }}"></span>
                Belum Dikerjakan
            </a>

            <a href="{{ request()->fullUrlWithQuery(['view' => 'calendar', 'status' => 'late']) }}" class="flex items-center gap-1.5 px-3 py-1.5 rounded-lg text-xs font-medium
               {{ ($selectedStatus ?? '') === 'late' ? 'bg-[var(--brand-solid)] text-white' : 'bg-[var(--surface-muted)] text-[var(--text-secondary)]' }}">
                <span class="w-2 h-2 rounded-full shrink-0" style="background-color: {{ ($selectedStatus ?? '') === 'late' ? '#fff' : '#c0392b' }}"></span>
                Telat Dikerjakan
            </a>
        </div>

    </div>

    {{-- Legenda warna client --}}
    <div class="flex flex-wrap gap-3 mb-2">
        @foreach ($clientOptions as $c)
            <span class="flex items-center gap-1.5 text-xs text-[var(--text-secondary)]">
                <span class="w-2.5 h-2.5 rounded-full"
                    style="background-color: {{ $c->color ?? $fallbackColor($c->id) }}"></span>
                {{ $c->name }}
            </span>
        @endforeach
    </div>

    {{-- Legenda titik status (dot kecil di pojok tiap item kalender) --}}
    <div class="flex flex-wrap gap-3 mb-4">
        <span class="flex items-center gap-1.5 text-xs text-[var(--text-muted)]">
            <span class="w-2 h-2 rounded-full" style="background-color: #0f7a5f"></span> Sudah Dikerjakan
        </span>
        <span class="flex items-center gap-1.5 text-xs text-[var(--text-muted)]">
            <span class="w-2 h-2 rounded-full" style="background-color: #9aa0a4"></span> Belum Dikerjakan
        </span>
        <span class="flex items-center gap-1.5 text-xs text-[var(--text-muted)]">
            <span class="w-2 h-2 rounded-full" style="background-color: #c0392b"></span> Telat Dikerjakan
        </span>
    </div>

    {{-- Grid bulan penuh (7 kolom) nggak muat di layar sempit walau dikasih
         overflow-x-auto - user cuma lihat 1-2 hari lalu mentok tanpa ada
         petunjuk visual buat scroll, keliatan "kepotong". Di mobile diganti
         agenda list vertikal per hari (cuma hari yang ada isinya) - lebih
         gampang di-scan lewat scroll biasa, tanpa scroll horizontal. --}}
    <div class="sm:hidden space-y-3">
        @php
            $agendaDays = collect(range(1, $daysInMonth))
                ->map(fn ($d) => \Carbon\Carbon::create($year, $month, $d))
                ->filter(fn ($date) => $itemsByDateClient->get($date->format('Y-m-d'), collect())->isNotEmpty());
        @endphp

        @forelse ($agendaDays as $date)
            @php $dateKey = $date->format('Y-m-d'); @endphp
            <div class="card p-4">
                <p class="text-xs font-semibold text-[var(--text-muted)] uppercase mb-2.5">{{ $date->translatedFormat('l, d F') }}</p>
                <div class="flex flex-col gap-1.5">
                    @foreach ($itemsByDateClient->get($dateKey, collect()) as $clientId => $clientItems)
                        @php
                            $client = $clientItems->first()->client;
                            $color = $client->color ?? $fallbackColor($clientId);
                        @endphp
                        @foreach ($clientItems as $item)
                            <a href="{{ route('content-items.show', $item) }}"
                                class="flex items-center justify-between gap-2 rounded-md px-2.5 py-2 hover:opacity-80 transition-opacity"
                                style="background-color: {{ $color }}14; border-left: 3px solid {{ $color }}">
                                <div class="min-w-0">
                                    <span class="text-xs font-semibold block truncate" style="color: {{ $color }}">{{ $client->name }}</span>
                                    <span class="text-[11px] text-[var(--text-secondary)] truncate block">{{ $item->title }}</span>
                                </div>
                                <span class="flex items-center gap-1 shrink-0">
                                    @php $status = $statusMeta($item->workflow); @endphp
                                    <span title="{{ $status['label'] }}" class="w-2 h-2 rounded-full" style="background-color: {{ $status['color'] }}"></span>
                                    <span title="{{ $item->contentType->name ?? '-' }}"
                                        class="w-5 h-5 rounded flex items-center justify-center text-white text-[10px] font-semibold shrink-0"
                                        style="background-color: {{ $color }}">
                                        {{ $typeBadge($item->contentType->name ?? null) }}
                                    </span>
                                </span>
                            </a>
                        @endforeach
                    @endforeach
                </div>
            </div>
        @empty
            <div class="card p-6 text-center">
                <span class="material-symbols-outlined text-[var(--icon-disabled)] text-[28px] mb-2 block">event_busy</span>
                <p class="text-sm text-[var(--text-muted)]">Tidak ada konten terjadwal bulan ini.</p>
            </div>
        @endforelse
    </div>

    <div class="card p-5 hidden sm:block">
      {{-- Exception documented (responsive sweep): 7-day grid is intrinsically
           wide - each day cell needs enough room to stay tappable/readable,
           can't be narrowed like a data table. Desktop-only (hidden sm:block),
           mobile gets a separate list view, so this scroll never reaches
           mobile users. --}}
      <div class="overflow-x-auto">
        <div class="min-w-[700px]">
        <div class="grid grid-cols-7 gap-2 text-center text-[11px] font-medium text-[var(--text-muted)] uppercase mb-2">
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

                <div class="border border-[var(--surface-muted)] rounded-lg p-2 min-h-[100px] flex flex-col gap-1">

                    <p class="text-xs text-[var(--text-muted)] mb-0.5">{{ $day }}</p>

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

                                <span class="flex items-center gap-1 shrink-0">
                                    @php $status = $statusMeta($item->workflow); @endphp
                                    <span title="{{ $status['label'] }}" class="w-1.5 h-1.5 rounded-full" style="background-color: {{ $status['color'] }}"></span>
                                    <span
                                        title="{{ $item->contentType->name ?? '-' }}"
                                        class="w-4 h-4 rounded flex items-center justify-center text-white text-[10px] font-semibold shrink-0 cursor-help"
                                        style="background-color: {{ $color }}">
                                        {{ $typeBadge($item->contentType->name ?? null) }}
                                    </span>
                                </span>
                            </a>
                        @endforeach
                    @endforeach

                    @if ($overflowCount > 0)
                        <button type="button"
                            x-on:click="expandedDate = (expandedDate === '{{ $dateKey }}' ? null : '{{ $dateKey }}')"
                            class="text-[11px] font-medium text-[var(--brand)] text-left hover:underline mt-0.5">
                            <span x-show="expandedDate !== '{{ $dateKey }}'">+{{ $overflowCount }} lainnya</span>
                            <span x-show="expandedDate === '{{ $dateKey }}'" x-cloak>Sembunyikan</span>
                        </button>
                    @endif

                    <div x-show="expandedDate === '{{ $dateKey }}'" x-cloak x-transition
                        class="mt-1 pt-1.5 border-t border-[var(--border)] flex flex-col gap-1">

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

                                    <span class="flex items-center gap-1 shrink-0">
                                        @php $status = $statusMeta($item->workflow); @endphp
                                        <span title="{{ $status['label'] }}" class="w-1.5 h-1.5 rounded-full" style="background-color: {{ $status['color'] }}"></span>
                                        <span
                                            title="{{ $item->contentType->name ?? '-' }}"
                                            class="w-4 h-4 rounded flex items-center justify-center text-white text-[10px] font-semibold shrink-0 cursor-help"
                                            style="background-color: {{ $color }}">
                                            {{ $typeBadge($item->contentType->name ?? null) }}
                                        </span>
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
    </div>
</div>