@php
    $daysInMonth = \Carbon\Carbon::create($year, $month, 1)->daysInMonth;
    $firstDayOfWeek = \Carbon\Carbon::create($year, $month, 1)->dayOfWeek;

    $typeBadge = fn (?string $typeName) => match ($typeName) {
        'Video' => 'V',
        'Desain' => 'D',
        default => '?',
    };
@endphp

{{-- Sama pola mobile-agenda-list vs desktop-grid dari
     content-plan/partials/calendar-grid.blade.php (internal), tapi
     disederhanakan - cuma 1 client jadi nggak perlu grouping/legenda
     warna per-client, dan link-nya ke client.approval.show (bukan
     content-items.show yang internal). --}}
<div class="sm:hidden space-y-3">
    @php
        $agendaDays = collect(range(1, $daysInMonth))
            ->map(fn ($d) => \Carbon\Carbon::create($year, $month, $d))
            ->filter(fn ($date) => $itemsByDate->get($date->format('Y-m-d'), collect())->isNotEmpty());
    @endphp

    @forelse ($agendaDays as $date)
        @php $dateKey = $date->format('Y-m-d'); @endphp
        <div class="bg-[var(--surface-card)] rounded-2xl border border-[var(--border)] p-4 shadow-[0_1px_2px_rgba(20,24,26,0.03)]">
            <p class="text-xs font-semibold text-[var(--text-muted)] uppercase mb-2.5">{{ $date->translatedFormat('l, d F') }}</p>
            <div class="flex flex-col gap-1.5">
                @foreach ($itemsByDate->get($dateKey, collect()) as $item)
                    <a href="{{ route('client.approval.show', $item) }}"
                        class="flex items-center justify-between gap-2 rounded-md px-2.5 py-2 bg-[var(--brand-tint)] hover:bg-[var(--brand-tint-hover)] transition-colors">
                        <span class="text-xs font-medium text-[var(--text-primary)] truncate">{{ $item->title }}</span>
                        <span title="{{ $item->contentType->name ?? '-' }}"
                            class="w-5 h-5 rounded flex items-center justify-center bg-[var(--brand)] text-white text-[10px] font-semibold shrink-0">
                            {{ $typeBadge($item->contentType->name ?? null) }}
                        </span>
                    </a>
                @endforeach
            </div>
        </div>
    @empty
        <div class="bg-[var(--surface-card)] rounded-2xl border border-[var(--border)] p-6 text-center shadow-[0_1px_2px_rgba(20,24,26,0.03)]">
            <span class="material-symbols-outlined text-[var(--icon-disabled)] text-[28px] mb-2 block">event_busy</span>
            <p class="text-sm text-[var(--text-muted)]">Tidak ada konten terjadwal bulan ini.</p>
        </div>
    @endforelse
</div>

<div class="bg-[var(--surface-card)] rounded-2xl border border-[var(--border)] p-5 shadow-[0_1px_2px_rgba(20,24,26,0.03)] hidden sm:block">
    {{-- Exception documented (responsive sweep): 7-day grid is intrinsically
         wide - each day cell needs enough room to stay tappable/readable,
         can't be narrowed like a data table. Desktop-only (hidden sm:block),
         mobile gets a separate list view, so this scroll never reaches
         mobile users. --}}
    <div class="overflow-x-auto thin-autohide-scrollbar">
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
                        $dayItems = $itemsByDate->get($dateKey, collect());
                    @endphp

                    <div class="border border-[var(--surface-muted)] rounded-lg p-2 min-h-[90px] flex flex-col gap-1">
                        <p class="text-xs text-[var(--text-muted)] mb-0.5">{{ $day }}</p>

                        @foreach ($dayItems->take(3) as $item)
                            <a href="{{ route('client.approval.show', $item) }}"
                                class="flex items-center justify-between gap-1.5 rounded-md px-2 py-1 bg-[var(--brand-tint)] hover:bg-[var(--brand-tint-hover)] transition-colors">
                                <span class="text-[11px] font-medium text-[var(--text-primary)] truncate">{{ $item->title }}</span>
                                <span title="{{ $item->contentType->name ?? '-' }}"
                                    class="w-4 h-4 rounded flex items-center justify-center bg-[var(--brand)] text-white text-[10px] font-semibold shrink-0 cursor-help">
                                    {{ $typeBadge($item->contentType->name ?? null) }}
                                </span>
                            </a>
                        @endforeach

                        @if ($dayItems->count() > 3)
                            <p class="text-[11px] text-[var(--text-muted)]">+{{ $dayItems->count() - 3 }} lainnya</p>
                        @endif
                    </div>
                @endfor
            </div>
        </div>
    </div>
</div>
