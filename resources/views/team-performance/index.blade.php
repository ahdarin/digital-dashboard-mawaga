@extends('layouts.app')
@section('title', 'Performa Tim')
@section('content')
<div class="p-4 sm:p-6 lg:p-8 max-w-[1400px] mx-auto">

    <div class="mb-7">
        <h1 class="font-display text-[26px] sm:text-[32px] font-semibold text-[var(--text-primary)]">Performa Tim</h1>
        <p class="text-[var(--text-secondary)] text-sm mt-1">Beban kerja dan produktivitas tim internal.</p>
    </div>

    <div class="flex items-center gap-1 bg-[var(--surface-muted)] rounded-lg p-1 mb-6 w-fit" role="tablist">
        <a href="{{ route('team-performance.index', ['tab' => 'ringkasan']) }}" role="tab" aria-selected="{{ $tab === 'ringkasan' ? 'true' : 'false' }}"
           class="text-sm font-medium px-4 py-2 rounded-md transition-colors {{ $tab === 'ringkasan' ? 'bg-[var(--surface-card)] text-[var(--text-primary)] shadow-sm' : 'text-[var(--text-muted)] hover:text-[var(--text-secondary)]' }}">
            Ringkasan Tim
        </a>
        <a href="{{ route('team-performance.index', ['tab' => 'anggota']) }}" role="tab" aria-selected="{{ $tab === 'anggota' ? 'true' : 'false' }}"
           class="text-sm font-medium px-4 py-2 rounded-md transition-colors {{ $tab === 'anggota' ? 'bg-[var(--surface-card)] text-[var(--text-primary)] shadow-sm' : 'text-[var(--text-muted)] hover:text-[var(--text-secondary)]' }}">
            Anggota
        </a>
        <a href="{{ route('team-performance.index', ['tab' => 'kehadiran']) }}" role="tab" aria-selected="{{ $tab === 'kehadiran' ? 'true' : 'false' }}"
           class="text-sm font-medium px-4 py-2 rounded-md transition-colors {{ $tab === 'kehadiran' ? 'bg-[var(--surface-card)] text-[var(--text-primary)] shadow-sm' : 'text-[var(--text-muted)] hover:text-[var(--text-secondary)]' }}">
            Kehadiran
        </a>
    </div>

    @if ($tab === 'kehadiran')
        @include('team-performance.partials.tab-kehadiran')
    @elseif ($tab === 'anggota')
        @include('team-performance.partials.tab-anggota')
    @else
        @include('team-performance.partials.tab-ringkasan')
    @endif
</div>
@endsection
