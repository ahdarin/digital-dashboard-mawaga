@extends('layouts.app')
@section('title', 'Dashboard')
@section('content')

<div class="p-8 max-w-[1400px]">

    <div class="flex items-start justify-between mb-7">
        <div>
            <h1 class="font-display text-[32px] font-semibold text-[#14181a]">Overview</h1>
            <p class="text-[#5c6266] text-sm mt-1">Ringkasan aktivitas tim dan klien hari ini.</p>
        </div>

        <a href="{{ Route::has('client-onboarding.create') ? route('client-onboarding.create') : '#' }}"
           class="flex items-center gap-2 bg-[#044b46] text-white text-sm font-medium px-5 py-2.5 rounded-lg hover:bg-[#033b37] transition-colors shrink-0">
            <span class="material-symbols-outlined text-[17px]">add</span> Tambah Client
        </a>
    </div>

    <div class="flex gap-5 items-start">

        <div class="flex-1 min-w-0 space-y-5">

            {{-- Stat cards --}}
            @php
                $statStyles = [
                    ['chip' => 'bg-[#f0f5f4]', 'icon' => 'text-[#044b46]'],
                    ['chip' => 'bg-[#eef2fb]', 'icon' => 'text-[#3452a8]'],
                    ['chip' => 'bg-[#e8f2f7]', 'icon' => 'text-[#0e7490]'],
                    ['chip' => 'bg-[#fdf2f1]', 'icon' => 'text-[#b3423e]'],
                ];
            @endphp
            <div class="grid grid-cols-3 gap-4">
                @foreach ($stats as $stat)
                    @php $c = $statStyles[$loop->index % 4]; @endphp
                    <div class="card p-5">
                        <div class="flex items-center gap-3 mb-4">
                            <div class="w-9 h-9 rounded-lg {{ $c['chip'] }} flex items-center justify-center">
                                <span class="material-symbols-outlined {{ $c['icon'] }} text-[18px]">{{ $stat['icon'] }}</span>
                            </div>
                            <span class="text-sm text-[#5c6266]">{{ $stat['label'] }}</span>
                        </div>

                        <p class="font-display text-[28px] font-semibold text-[#14181a] mb-2">{{ $stat['value'] }}</p>

                        <p class="text-xs font-medium flex items-center gap-1
                            {{ $stat['trend'] === 'up' ? 'text-[#0f7a5f]' : '' }}
                            {{ $stat['trend'] === 'down' ? 'text-[#b3423e]' : '' }}
                            {{ $stat['trend'] === 'flat' ? 'text-[#9aa0a4]' : '' }}">
                            @if ($stat['trend'] === 'up')
                                <span class="material-symbols-outlined text-[13px]">trending_up</span>
                            @elseif ($stat['trend'] === 'down')
                                <span class="material-symbols-outlined text-[13px]">trending_down</span>
                            @else
                                <span>&mdash;</span>
                            @endif
                            {{ $stat['change'] }}
                        </p>
                    </div>
                @endforeach
            </div>

            {{-- Performance chart --}}
            <div class="card p-6">
                <h2 class="font-display text-lg font-semibold text-[#14181a] mb-1">Content Performance</h2>
                <p class="text-xs text-[#9aa0a4] mb-6">Jumlah konten berdasarkan deadline, 7 bulan terakhir</p>

                @php
                    $max = max(collect($performance)->max('value'), 1);
                    $peak = collect($performance)->sortByDesc('value')->keys()->first();
                @endphp

                <div class="flex items-end justify-between gap-4 h-48">
                    @foreach ($performance as $i => $bar)
                        <div class="flex-1 flex flex-col items-center gap-2.5">
                            <span class="text-xs font-medium text-[#9aa0a4]">{{ $bar['value'] }}</span>
                            <div class="w-full max-w-12 rounded-t-[3px] transition-all duration-300 {{ $i === $peak && $bar['value'] > 0 ? 'bg-[#044b46]' : 'bg-[#dbe6e4]' }}"
                                 style="height: {{ max(($bar['value'] / $max) * 100, 3) }}%"></div>
                            <span class="text-xs text-[#9aa0a4]">{{ $bar['label'] }}</span>
                        </div>
                    @endforeach
                </div>
            </div>

            {{-- Views trend (domain PIC 3) --}}
            <div class="card p-6">
                <h2 class="font-display text-lg font-semibold text-[#14181a] mb-1">Views Trend</h2>
                <p class="text-xs text-[#9aa0a4] mb-5">Total views seluruh konten, 8 minggu terakhir.</p>
                <x-trend-chart :trend="$viewsTrend" />
            </div>

            {{-- Recent projects --}}
            <div class="card p-6">
                <div class="flex items-center justify-between mb-4">
                    <h2 class="font-display text-lg font-semibold text-[#14181a]">Recent Projects</h2>
                    <a href="{{ Route::has('production-workflow.index') ? route('production-workflow.index') : '#' }}" class="text-sm font-medium text-[#044b46] hover:underline">Lihat semua</a>
                </div>

                @if ($recentItems->isEmpty())
                    <p class="text-sm text-[#9aa0a4] py-6 text-center">Belum ada konten yang tercatat.</p>
                @else
                    <table class="w-full text-sm text-left">
                        <thead>
                            <tr class="text-[#9aa0a4] text-[11px] uppercase tracking-wide">
                                <th class="pb-2.5 font-medium">Title</th>
                                <th class="pb-2.5 font-medium">Client</th>
                                <th class="pb-2.5 font-medium">Type</th>
                                <th class="pb-2.5 font-medium">Deadline</th>
                                <th class="pb-2.5 font-medium">Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($recentItems as $item)
                                <tr class="border-t border-[#f2f3f6]">
                                    <td class="py-3 pr-4 font-medium text-[#14181a]">{{ $item['title'] }}</td>
                                    <td class="py-3 pr-4 text-[#5c6266]">{{ $item['client'] }}</td>
                                    <td class="py-3 pr-4 text-[#5c6266]">{{ $item['type'] }}</td>
                                    <td class="py-3 pr-4 text-[#5c6266]">{{ $item['deadline'] ? $item['deadline']->translatedFormat('d M Y') : '-' }}</td>
                                    <td class="py-3 pr-4">
                                        <span class="text-xs px-2 py-1 rounded-full {{ $item['is_overdue'] ? 'bg-[#fdf2f1] text-[#b3423e]' : 'bg-[#f0f5f4] text-[#044b46]' }}">{{ $item['status'] }}</span>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                @endif
            </div>

        </div>

        {{-- Kolom kanan --}}
        <div class="w-[320px] shrink-0 flex flex-col gap-5">

            <div class="card p-6 flex flex-col">
                <div class="flex items-center justify-between mb-4">
                    <h2 class="font-display text-base font-semibold text-[#14181a]">AI Insights</h2>
                    <span class="material-symbols-outlined text-[#044b46] text-[18px]">auto_awesome</span>
                </div>
                <div class="space-y-3">
                    @forelse ($insights as $insight)
                        <div class="bg-[#f7f8fc] rounded-lg p-3.5">
                            <p class="text-sm font-medium text-[#14181a] flex gap-2">
                                <span class="w-1.5 h-1.5 rounded-full bg-[#044b46] mt-1.5 shrink-0"></span>
                                {{ $insight['title'] }}
                            </p>
                            <p class="text-xs text-[#9aa0a4] mt-1 pl-3.5">{{ $insight['description'] }}</p>
                        </div>
                    @empty
                        <p class="text-sm text-[#9aa0a4] text-center py-4">Belum cukup data untuk insight.</p>
                    @endforelse
                </div>
            </div>

            <div class="card p-6 flex flex-col">
                <div class="flex items-center justify-between mb-4">
                    <h2 class="font-display text-base font-semibold text-[#14181a]">Needs Attention</h2>
                    <span class="material-symbols-outlined text-[#b3423e] text-[18px]">priority_high</span>
                </div>

                <div class="space-y-3 flex-1">
                    @forelse ($attentionItems as $item)
                        <div class="bg-[#f7f8fc] rounded-lg p-3.5">
                            <p class="text-sm font-medium text-[#14181a] flex gap-2">
                                <span class="w-1.5 h-1.5 rounded-full bg-[#b3423e] mt-1.5 shrink-0"></span>
                                {{ $item['title'] }}
                            </p>
                            <p class="text-xs text-[#9aa0a4] mt-1 pl-3.5">{{ $item['client'] }} &middot; PIC: {{ $item['pic'] }} &middot; {{ $item['status'] }}</p>
                        </div>
                    @empty
                        <div class="text-center py-8">
                            <span class="material-symbols-outlined text-[#0f7a5f] text-[28px]">check_circle</span>
                            <p class="text-sm text-[#9aa0a4] mt-2">Tidak ada item overdue. Semua on track 🎉</p>
                        </div>
                    @endforelse
                </div>

                <a href="{{ Route::has('production-workflow.index') ? route('production-workflow.index') : '#' }}"
                   class="mt-4 w-full bg-[#f7f8fc] text-[#044b46] text-sm font-medium py-2.5 rounded-lg hover:bg-[#f0f5f4] transition-colors flex items-center justify-center gap-1.5">
                    Buka Production Workflow
                    <span class="material-symbols-outlined text-[15px]">arrow_forward</span>
                </a>
            </div>

            <div class="card p-6 flex flex-col">
                <div class="flex items-center justify-between mb-1">
                    <h2 class="font-display text-base font-semibold text-[#14181a]">High Risk (AI Prediction)</h2>
                    <span class="material-symbols-outlined text-[#b3423e] text-[18px]">report</span>
                </div>
                <p class="text-xs text-[#9aa0a4] mb-4">Belum overdue, tapi diprediksi berisiko terlambat — cegah sebelum kejadian.</p>

                <div class="space-y-3 flex-1">
                    @forelse ($highRiskItems as $item)
                        <a href="{{ route('content-items.show', $item['id']) }}" class="block bg-[#f7f8fc] rounded-lg p-3.5 hover:bg-[#fdf2f1] transition-colors">
                            <div class="flex items-center justify-between gap-2">
                                <p class="text-sm font-medium text-[#14181a] flex gap-2 min-w-0">
                                    <span class="w-1.5 h-1.5 rounded-full bg-[#b3423e] mt-1.5 shrink-0"></span>
                                    <span class="truncate">{{ $item['title'] }}</span>
                                </p>
                                <span class="text-xs font-semibold text-[#b3423e] shrink-0">{{ $item['risk_score'] }}%</span>
                            </div>
                            <p class="text-xs text-[#9aa0a4] mt-1 pl-3.5">{{ $item['client'] }} &middot; PIC: {{ $item['pic'] }}</p>
                            <p class="text-xs text-[#9aa0a4] pl-3.5">{{ $item['top_factor'] }}</p>
                        </a>
                    @empty
                        <div class="text-center py-8">
                            <span class="material-symbols-outlined text-[#0f7a5f] text-[28px]">verified</span>
                            <p class="text-sm text-[#9aa0a4] mt-2">Tidak ada item risiko tinggi saat ini.</p>
                        </div>
                    @endforelse
                </div>
            </div>

        </div>

    </div>
</div>

@endsection