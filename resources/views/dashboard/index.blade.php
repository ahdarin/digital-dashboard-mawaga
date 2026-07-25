@extends('layouts.app')
@section('title', 'Dashboard')
@section('content')

<div class="p-8">

    {{-- Header --}}
    <div class="flex items-start justify-between mb-8">
        <div>
            <h1 class="text-4xl font-extrabold text-[#191c1c]">Overview</h1>
            <p class="text-gray-500 mt-2">Ringkasan aktivitas tim dan klien hari ini.</p>
        </div>

        <a href="{{ Route::has('client-onboarding.create') ? route('client-onboarding.create') : '#' }}"
           class="flex items-center gap-2 bg-gradient-to-r from-[#044b46] to-[#0a6b5c] text-white text-sm font-semibold px-5 py-3 rounded-xl hover:opacity-90 transition-opacity duration-150 shrink-0 shadow-[0_6px_16px_rgba(4,75,70,0.25)]">
            <span class="material-symbols-outlined text-[18px]">add</span>
            Tambah Klien
        </a>
    </div>

    <div class="flex gap-6 items-start">

        {{-- Main column --}}
        <div class="flex-1 min-w-0 space-y-6">

            {{-- Stat cards --}}
            @php
                $statColors = [
                    ['icon' => 'text-[#044b46]', 'chip' => 'bg-[#044b46]/10', 'glow' => 'bg-[#044b46]/10'],
                    ['icon' => 'text-indigo-500', 'chip' => 'bg-indigo-50', 'glow' => 'bg-indigo-200/30'],
                    ['icon' => 'text-sky-500', 'chip' => 'bg-sky-50', 'glow' => 'bg-sky-200/30'],
                    ['icon' => 'text-rose-500', 'chip' => 'bg-rose-50', 'glow' => 'bg-rose-200/30'],
                ];
            @endphp
            <div class="grid grid-cols-2 gap-6">
                @foreach ($stats as $stat)
                    @php $c = $statColors[$loop->index % 4]; @endphp
                    <div class="relative bg-white rounded-2xl shadow-[0_4px_24px_rgba(15,23,42,0.06)] p-6 overflow-hidden">
                        <div class="absolute -right-6 -top-6 w-24 h-24 rounded-full {{ $c['glow'] }} blur-xl"></div>

                        <div class="flex items-center gap-3 mb-5">
                            <div class="w-10 h-10 rounded-xl {{ $c['chip'] }} flex items-center justify-center">
                                <span class="material-symbols-outlined {{ $c['icon'] }} text-[20px]">{{ $stat['icon'] }}</span>
                            </div>
                            <span class="text-sm font-semibold text-gray-500">{{ $stat['label'] }}</span>
                        </div>

                        <p class="text-4xl font-extrabold text-[#191c1c] mb-3">{{ $stat['value'] }}</p>

                        <p class="text-xs font-medium flex items-center gap-1
                            {{ $stat['trend'] === 'up' ? 'text-emerald-600' : '' }}
                            {{ $stat['trend'] === 'down' ? 'text-rose-500' : '' }}
                            {{ $stat['trend'] === 'flat' ? 'text-gray-400' : '' }}">
                            @if ($stat['trend'] === 'up')
                                <span class="material-symbols-outlined text-[14px]">trending_up</span>
                            @elseif ($stat['trend'] === 'down')
                                <span class="material-symbols-outlined text-[14px]">trending_down</span>
                            @else
                                <span>&mdash;</span>
                            @endif
                            {{ $stat['change'] }}
                        </p>
                    </div>
                    
                @endforeach
            </div>

            {{-- Performance chart --}}
            <div class="bg-white rounded-2xl shadow-[0_4px_24px_rgba(15,23,42,0.06)] p-6">
                <div class="flex items-start justify-between mb-8">
                    <div>
                        <h2 class="text-2xl font-extrabold text-[#191c1c]">Performa Konten</h2>
                        <p class="text-sm text-gray-500 mt-1">Jumlah konten berdasarkan deadline, 7 bulan terakhir</p>
                    </div>
                </div>

                @php
                    $max = max(collect($performance)->max('value'), 1);
                    $peak = collect($performance)->sortByDesc('value')->keys()->first();
                @endphp

                <div class="flex items-end justify-between gap-4 h-56">
                    @foreach ($performance as $i => $bar)
                        <div class="flex-1 flex flex-col items-center gap-3">
                            <span class="text-xs font-semibold text-gray-400">{{ $bar['value'] }}</span>
                            <div
                                class="w-full max-w-14 rounded-t-lg transition-all duration-300 {{ $i === $peak && $bar['value'] > 0 ? 'bg-gradient-to-t from-[#0a8f76] to-[#044b46]' : 'bg-gradient-to-t from-[#044b46]/15 to-[#044b46]/25' }}"
                                style="height: {{ max(($bar['value'] / $max) * 100, 4) }}%"
                            ></div>
                            <span class="text-xs font-medium text-gray-400">{{ $bar['label'] }}</span>
                        </div>
                    @endforeach
                </div>
            </div>

            {{-- Recent projects table --}}
            <div class="bg-white rounded-2xl shadow-[0_4px_24px_rgba(15,23,42,0.06)] p-6">
                <div class="flex items-center justify-between mb-5">
                    <h2 class="text-xl font-extrabold text-[#191c1c]">Proyek Terbaru</h2>
                    <a href="{{ Route::has('production-workflow.index') ? route('production-workflow.index') : '#' }}"
                       class="text-sm font-semibold text-[#044b46] hover:underline">
                        Lihat semua
                    </a>
                </div>

                @if ($recentItems->isEmpty())
                    <p class="text-sm text-gray-400 py-6 text-center">Belum ada konten yang tercatat.</p>
                @else
                    <table class="w-full text-sm text-left">
                        <thead class="text-gray-400 text-xs uppercase">
                            <tr>
                                <th class="py-2 pr-4 font-semibold">Judul</th>
                                <th class="py-2 pr-4 font-semibold">Klien</th>
                                <th class="py-2 pr-4 font-semibold">Tipe</th>
                                <th class="py-2 pr-4 font-semibold">Deadline</th>
                                <th class="py-2 pr-4 font-semibold">Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($recentItems as $item)
                                <tr class="border-t border-gray-50">
                                    <td class="py-3 pr-4 font-medium text-[#191c1c]">{{ $item['title'] }}</td>
                                    <td class="py-3 pr-4 text-gray-500">{{ $item['client'] }}</td>
                                    <td class="py-3 pr-4 text-gray-500">{{ $item['type'] }}</td>
                                    <td class="py-3 pr-4 text-gray-500">
                                        {{ $item['deadline'] ? $item['deadline']->translatedFormat('d M Y') : '-' }}
                                    </td>
                                    <td class="py-3 pr-4">
                                        <span class="text-xs px-2 py-1 rounded-full
                                            {{ $item['is_overdue'] ? 'bg-rose-100 text-rose-600' : 'bg-[#044b46]/10 text-[#044b46]' }}">
                                            {{ $item['status'] }}
                                        </span>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                @endif
            </div>

        </div>

        

{{-- Kolom kanan --}}
<div class="w-[340px] shrink-0 flex flex-col gap-6">

    {{-- AI Insights --}}
    <div class="bg-white rounded-2xl shadow-[0_4px_24px_rgba(15,23,42,0.06)] p-6 flex flex-col">
        <div class="flex items-center justify-between mb-5">
            <h2 class="text-xl font-extrabold text-[#191c1c]">AI Insights</h2>
            <div class="w-9 h-9 rounded-full bg-gradient-to-br from-[#044b46] to-[#0a8f76] flex items-center justify-center shadow-[0_4px_10px_rgba(4,75,70,0.3)]">
                <span class="material-symbols-outlined text-white text-[18px]">auto_awesome</span>
            </div>
        </div>

        <div class="space-y-4">
            @forelse ($insights as $insight)
                <div class="bg-[#f8faf8] rounded-xl p-4">
                    <p class="text-sm font-semibold text-[#191c1c] flex gap-2">
                        <span class="w-1.5 h-1.5 rounded-full bg-[#044b46] mt-1.5 shrink-0"></span>
                        {{ $insight['title'] }}
                    </p>
                    <p class="text-xs text-gray-500 mt-1.5 pl-3.5">{{ $insight['description'] }}</p>
                </div>
            @empty
                <p class="text-sm text-gray-400 text-center py-4">Belum cukup data untuk insight.</p>
            @endforelse
        </div>
    </div>

    {{-- Perlu Perhatian (overdue) --}}
    <div class="bg-white rounded-2xl shadow-[0_4px_24px_rgba(15,23,42,0.06)] p-6 flex flex-col">
        <div class="flex items-center justify-between mb-5">
            <h2 class="text-xl font-extrabold text-[#191c1c]">Perlu Perhatian</h2>
            <div class="w-9 h-9 rounded-full bg-rose-50 flex items-center justify-center">
                <span class="material-symbols-outlined text-rose-500 text-[18px]">priority_high</span>
            </div>
        </div>

        <div class="space-y-4 flex-1">
            @forelse ($attentionItems as $item)
                <div class="bg-[#f8faf8] rounded-xl p-4">
                    <p class="text-sm font-semibold text-[#191c1c] flex gap-2">
                        <span class="w-1.5 h-1.5 rounded-full bg-rose-500 mt-1.5 shrink-0"></span>
                        {{ $item['title'] }}
                    </p>
                    <p class="text-xs text-gray-500 mt-1.5 pl-3.5">
                        {{ $item['client'] }} &middot; PIC: {{ $item['pic'] }} &middot; {{ $item['status'] }}
                    </p>
                </div>
            @empty
                <div class="text-center py-8">
                    <span class="material-symbols-outlined text-emerald-500 text-[32px]">check_circle</span>
                    <p class="text-sm text-gray-400 mt-2">Tidak ada item overdue. Semua on track 🎉</p>
                </div>
            @endforelse
        </div>

        <a href="{{ Route::has('production-workflow.index') ? route('production-workflow.index') : '#' }}"
           class="mt-4 w-full bg-gradient-to-r from-[#f4f9f7] to-[#eef6f3] text-[#044b46] text-sm font-semibold py-3 rounded-xl hover:from-[#044b46]/10 hover:to-[#044b46]/10 transition-colors duration-150 flex items-center justify-center gap-1 border border-[#044b46]/10">
            Buka Production Workflow
            <span class="material-symbols-outlined text-[16px]">arrow_forward</span>
        </a>
    </div>

</div>

    </div>
</div>

@endsection