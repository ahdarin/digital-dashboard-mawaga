@extends('layouts.app')
@section('title', 'Performa Tim')
@section('content')
<div class="p-4 sm:p-6 lg:p-8 max-w-[1400px]">

    <div class="mb-7">
        <h1 class="font-display text-[26px] sm:text-[32px] font-semibold text-[#14181a]">Performa Tim</h1>
        <p class="text-[#5c6266] text-sm mt-1">Beban kerja dan produktivitas tim internal.</p>
    </div>

    <div class="flex items-center gap-1 bg-[#f2f3f6] rounded-lg p-1 mb-6 w-fit">
        <a href="{{ route('team-performance.index', ['tab' => 'performa']) }}"
           class="text-sm font-medium px-4 py-2 rounded-md transition-colors {{ $tab === 'performa' ? 'bg-white text-[#14181a] shadow-sm' : 'text-[#9aa0a4] hover:text-[#5c6266]' }}">
            Performa
        </a>
        <a href="{{ route('team-performance.index', ['tab' => 'kehadiran']) }}"
           class="text-sm font-medium px-4 py-2 rounded-md transition-colors {{ $tab === 'kehadiran' ? 'bg-white text-[#14181a] shadow-sm' : 'text-[#9aa0a4] hover:text-[#5c6266]' }}">
            Kehadiran
        </a>
    </div>

    @if ($tab === 'kehadiran')
        @include('team-performance.partials.tab-kehadiran')
    @else
        @include('team-performance.partials.tab-performa')
    @endif
</div>
@endsection
