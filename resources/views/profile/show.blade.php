@extends('layouts.app')
@section('title', $user->name . ' - Profile')
@section('content')
@php
    $statusLabels = \App\Support\WorkflowTransitions::labels();
    $isOwnProfile = auth()->id() === $user->id;

    // url()->previous() ikut Referer header - kalau halaman ini di-reload
    // (misal abis generate AI brief lalu redirect balik ke sini), Referer-nya
    // jadi halaman ini sendiri, bikin tombol back looping ke diri sendiri.
    // Fallback ke Beranda kalau ketauan previous = halaman sekarang.
    $backUrl = url()->previous();
    $backPath = parse_url($backUrl, PHP_URL_PATH) ?? '';
    if (trim($backPath, '/') === trim(request()->path(), '/')) {
        $backUrl = route('profile.me');
    }
@endphp

<div class="p-4 sm:p-6 lg:p-8 max-w-[1400px] mx-auto">

    {{-- Header --}}
    <div class="flex items-center gap-4 mb-8 flex-wrap">
        <a href="{{ $backUrl }}" class="w-9 h-9 flex items-center justify-center rounded-lg hover:bg-white text-[#9aa0a4] hover:text-[#14181a] transition-colors">
            <span class="material-symbols-outlined text-[19px]">arrow_back</span>
        </a>
        @if ($user->avatar_url)
            <img src="{{ $user->avatar_url }}" referrerpolicy="no-referrer" class="w-16 h-16 rounded-full object-cover">
        @else
            <div class="w-16 h-16 rounded-full bg-[#044b46] text-white text-xl font-semibold flex items-center justify-center">
                {{ strtoupper(substr($user->name, 0, 1)) }}
            </div>
        @endif
        <div>
            <div class="flex items-center gap-2">
                <h1 class="font-display text-2xl font-semibold text-[#14181a]">{{ $user->name }}</h1>
                @if ($isOwnProfile)
                    <span class="text-[10px] bg-[#f0f5f4] text-[#044b46] px-2 py-0.5 rounded-full font-semibold">Anda</span>
                @endif
            </div>
            <p class="text-sm text-[#5c6266]">{{ $user->role->name ?? '-' }}</p>
            <p class="text-xs text-[#9aa0a4] mt-1">Bergabung sejak {{ $user->created_at->format('d M Y') }}</p>
        </div>
    </div>

    {{-- Kartu Ringkasan --}}
    <div class="grid grid-cols-2 sm:grid-cols-4 gap-4 mb-6">
        <div class="card p-5">
            <p class="text-xs text-[#9aa0a4] mb-1">Task Aktif</p>
            <p class="font-display text-[26px] font-semibold text-[#14181a] [font-variant-numeric:tabular-nums]">{{ $activeCount }}</p>
        </div>
        <div class="card p-5">
            <p class="text-xs text-[#9aa0a4] mb-1">Overdue</p>
            <p class="font-display text-[26px] font-semibold [font-variant-numeric:tabular-nums] {{ $overdueCount > 0 ? 'text-[#b3423e]' : 'text-[#14181a]' }}">{{ $overdueCount }}</p>
        </div>
        <div class="card p-5">
            <p class="text-xs text-[#9aa0a4] mb-1">Completion Rate Bulan Ini</p>
            <p class="font-display text-[26px] font-semibold text-[#14181a] [font-variant-numeric:tabular-nums]">{{ $completionRate }}%</p>
        </div>
        <div class="card p-5">
            <p class="text-xs text-[#9aa0a4] mb-1">Revisi Diterima</p>
            <p class="font-display text-[26px] font-semibold text-[#14181a] [font-variant-numeric:tabular-nums]">{{ $revisionCount }}</p>
        </div>
    </div>

    <div class="grid grid-cols-1 lg:grid-cols-3 gap-5">

        {{-- Client yang ditangani --}}
        <div class="card p-5">
            <h2 class="font-display text-base font-semibold text-[#14181a] mb-4">Client yang Ditangani</h2>
            <div class="space-y-3.5">
                @forelse ($assignedClients as $client)
                    <div class="flex items-center gap-2.5">
                        <div class="w-8 h-8 rounded-full bg-[#f0f5f4] text-[#044b46] text-xs font-semibold flex items-center justify-center shrink-0">
                            {{ strtoupper(substr($client->brand_name, 0, 1)) }}
                        </div>
                        <div>
                            <p class="text-xs font-semibold text-[#14181a]">{{ $client->name }}</p>
                            <p class="text-[10px] text-[#9aa0a4]">{{ $client->brand_name }}</p>
                        </div>
                    </div>
                @empty
                    <p class="text-xs text-[#9aa0a4] italic">Belum ada client ditugaskan.</p>
                @endforelse
            </div>
        </div>

        {{-- Task List --}}
        <div class="lg:col-span-2 card overflow-hidden flex flex-col">
            <div class="p-5 pb-4 shrink-0">
                <h2 class="font-display text-base font-semibold text-[#14181a]">Task ({{ $assignments->count() }})</h2>
            </div>
            <div class="overflow-auto max-h-[420px] thin-autohide-scrollbar hidden sm:block">
                <table class="w-full text-sm text-left">
                    <thead class="bg-[#f7f8fc] text-[#9aa0a4] text-[11px] uppercase tracking-wide sticky top-0 z-10">
                        <tr>
                            <th class="px-6 py-3 font-medium whitespace-nowrap">Content Item</th>
                            <th class="px-4 py-3 font-medium whitespace-nowrap">Client</th>
                            <th class="px-4 py-3 font-medium whitespace-nowrap">Status</th>
                            <th class="px-4 py-3 font-medium whitespace-nowrap">Deadline</th>
                            <th class="px-4 py-3 font-medium whitespace-nowrap">Delay Risk</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($assignments as $assignment)
                            @php
                                $item = $assignment->contentItem;
                                $riskColors = [
                                    'high' => ['bg' => '#fdf2f1', 'text' => '#b3423e'],
                                    'medium' => ['bg' => '#fdf6ec', 'text' => '#8a6423'],
                                    'low' => ['bg' => '#f0f5f4', 'text' => '#0f7a5f'],
                                ];
                                $risk = $item->latestDelayRisk;
                                $riskColor = $risk ? ($riskColors[$risk->risk_level] ?? $riskColors['low']) : null;
                            @endphp
                            <tr class="border-t border-[#f2f3f6] hover:bg-[#f7f8fc] transition-colors cursor-pointer"
                                onclick="window.location='{{ route('content-items.show', $item) }}'">
                                <td class="px-6 py-3.5 font-medium text-[#14181a] whitespace-nowrap">{{ $item->title }}</td>
                                <td class="px-4 py-3.5 text-[#5c6266] whitespace-nowrap">{{ $item->client->name ?? '-' }}</td>
                                <td class="px-4 py-3.5">
                                    <div class="flex items-center gap-1.5 whitespace-nowrap">
                                        <span class="text-xs font-medium px-2.5 py-1 rounded-full bg-[#f0f5f4] text-[#044b46]">
                                            {{ $statusLabels[$item->workflow->current_status] ?? $item->workflow->current_status }}
                                        </span>
                                        @if ($item->workflow->is_overdue)
                                            <span class="text-xs font-medium px-2.5 py-1 rounded-full bg-[#fdf2f1] text-[#b3423e]">Overdue</span>
                                        @endif
                                    </div>
                                </td>
                                <td class="px-4 py-3.5 text-[#5c6266] whitespace-nowrap">{{ $item->deadline_at->format('d M Y') }}</td>
                                <td class="px-4 py-3.5">
                                    @if ($risk)
                                        <span class="text-xs font-semibold px-2.5 py-1 rounded-full"
                                              style="background-color: {{ $riskColor['bg'] }}; color: {{ $riskColor['text'] }};"
                                              title="{{ $risk->top_factor }}">
                                            {{ $risk->risk_score }}%
                                        </span>
                                    @else
                                        <span class="text-xs text-[#c3c7cb]">-</span>
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="5" class="px-6 py-10 text-center text-[#9aa0a4] text-sm">Belum ada task ditugaskan.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            {{-- Mobile accordion --}}
            <div class="sm:hidden p-3.5 space-y-3 max-h-[420px] overflow-auto thin-autohide-scrollbar">
                @forelse ($assignments as $assignment)
                    @php
                        $item = $assignment->contentItem;
                        $riskColors = [
                            'high' => ['bg' => '#fdf2f1', 'text' => '#b3423e'],
                            'medium' => ['bg' => '#fdf6ec', 'text' => '#8a6423'],
                            'low' => ['bg' => '#f0f5f4', 'text' => '#0f7a5f'],
                        ];
                        $risk = $item->latestDelayRisk;
                        $riskColor = $risk ? ($riskColors[$risk->risk_level] ?? $riskColors['low']) : null;
                    @endphp
                    <div class="card p-3.5" x-data="{ open: false }">
                        <div class="flex items-start gap-2 cursor-pointer" @click="open = !open">
                            <div class="flex-1 min-w-0">
                                <p class="font-medium text-[#14181a] text-sm">{{ $item->title }}</p>
                                <div class="flex items-center gap-1.5 flex-wrap mt-1.5">
                                    <span class="text-xs font-medium px-2.5 py-1 rounded-full bg-[#f0f5f4] text-[#044b46] whitespace-nowrap">
                                        {{ $statusLabels[$item->workflow->current_status] ?? $item->workflow->current_status }}
                                    </span>
                                    @if ($item->workflow->is_overdue)
                                        <span class="text-xs font-medium px-2.5 py-1 rounded-full bg-[#fdf2f1] text-[#b3423e] whitespace-nowrap">Overdue</span>
                                    @endif
                                    @if ($risk)
                                        <span class="text-xs font-semibold px-2.5 py-1 rounded-full whitespace-nowrap"
                                              style="background-color: {{ $riskColor['bg'] }}; color: {{ $riskColor['text'] }};"
                                              title="{{ $risk->top_factor }}">
                                            {{ $risk->risk_score }}%
                                        </span>
                                    @endif
                                </div>
                            </div>
                            <div class="w-7 h-7 shrink-0 flex items-center justify-center rounded-lg text-[#9aa0a4]">
                                <span class="material-symbols-outlined text-[19px] transition-transform" :class="open && 'rotate-180'">expand_more</span>
                            </div>
                        </div>
                        <div x-show="open" x-cloak x-transition class="mt-3 pt-3 border-t border-[#f2f3f6] space-y-2">
                            <div class="flex items-center justify-between text-xs">
                                <span class="text-[#9aa0a4]">Client</span>
                                <span class="text-[#14181a] font-medium">{{ $item->client->name ?? '-' }}</span>
                            </div>
                            <div class="flex items-center justify-between text-xs">
                                <span class="text-[#9aa0a4]">Deadline</span>
                                <span class="text-[#14181a] font-medium">{{ $item->deadline_at->format('d M Y') }}</span>
                            </div>
                            <a href="{{ route('content-items.show', $item) }}" class="mt-2 flex items-center justify-center gap-1.5 text-xs font-semibold text-[#044b46] bg-[#f0f5f4] hover:bg-[#e4ede9] rounded-lg py-2 transition-colors">Lihat Detail <span class="material-symbols-outlined text-[15px]">arrow_forward</span></a>
                        </div>
                    </div>
                @empty
                    <p class="px-2 py-10 text-center text-[#9aa0a4] text-sm">Belum ada task ditugaskan.</p>
                @endforelse
            </div>
        </div>
    </div>
</div>
@endsection
