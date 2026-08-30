@extends('layouts.client')
@section('title', 'Kalender')
@section('content')

    @php
        $current = \Carbon\Carbon::create($year, $month, 1);
        $prev = $current->copy()->subMonthNoOverflow();
        $next = $current->copy()->addMonthNoOverflow();
    @endphp

    <div class="p-4 sm:p-5">
        <div class="flex items-center justify-between gap-2 mb-4">
            <span x-data="tooltipHover('Bulan sebelumnya')" class="contents">
                <a href="{{ route('client.portal.calendar', ['token' => $portalToken, 'month' => $prev->month, 'year' => $prev->year]) }}"
                   @mouseenter="onEnter($event)" @mouseleave="onLeave()"
                   aria-label="Bulan sebelumnya"
                   class="w-9 h-9 shrink-0 flex items-center justify-center rounded-lg border border-[var(--border)] text-[var(--text-secondary)] hover:bg-[var(--surface-page)] transition-colors">
                    <span class="material-symbols-outlined text-[18px]">chevron_left</span>
                </a>
                @include('components.action-tooltip')
            </span>
            <p class="font-display text-base font-semibold text-[var(--text-primary)]">{{ $current->translatedFormat('F Y') }}</p>
            <span x-data="tooltipHover('Bulan berikutnya')" class="contents">
                <a href="{{ route('client.portal.calendar', ['token' => $portalToken, 'month' => $next->month, 'year' => $next->year]) }}"
                   @mouseenter="onEnter($event)" @mouseleave="onLeave()"
                   aria-label="Bulan berikutnya"
                   class="w-9 h-9 shrink-0 flex items-center justify-center rounded-lg border border-[var(--border)] text-[var(--text-secondary)] hover:bg-[var(--surface-page)] transition-colors">
                    <span class="material-symbols-outlined text-[18px]">chevron_right</span>
                </a>
                @include('components.action-tooltip')
            </span>
        </div>

        @include('client.calendar.partials.grid')
    </div>
@endsection
