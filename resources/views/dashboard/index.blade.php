@extends('layouts.app')
@section('title', 'Dashboard')
@section('content')

    <div class="p-4 sm:p-6 lg:p-8 max-w-[1400px] mx-auto">

        <div class="mb-7">
            <h1 class="font-display text-[26px] sm:text-[32px] font-semibold text-[#14181a]">Dashboard</h1>
            <p class="text-[#5c6266] text-sm mt-1">Ringkasan eksekutif aktivitas tim dan klien.</p>
        </div>

        <div class="flex flex-col lg:flex-row gap-5 items-stretch lg:items-start">

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
                <div class="grid grid-cols-2 sm:grid-cols-3 gap-3 sm:gap-4">
                    @foreach ($stats as $stat)
                        @php $c = $statStyles[$loop->index % 4]; @endphp
                        <div class="card p-3.5 sm:p-5">
                            <div class="flex items-center gap-3 mb-4">
                                <div class="w-9 h-9 rounded-lg {{ $c['chip'] }} flex items-center justify-center">
                                    <span
                                        class="material-symbols-outlined {{ $c['icon'] }} text-[18px]">{{ $stat['icon'] }}</span>
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
                    <h2 class="font-display text-lg font-semibold text-[#14181a] mb-1">Performa Konten</h2>
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
                    <div class="flex items-center justify-between mb-1">
                        <h2 class="font-display text-lg font-semibold text-[#14181a]">Tren Views</h2>
                        <form method="GET">
                            <select name="period" onchange="this.form.submit()"
                                class="text-sm border border-[#eef0f4] rounded-lg px-3 py-1.5 bg-white focus:outline-none focus:ring-2 focus:ring-[#044b46]/15 focus:border-[#044b46]/40 transition-shadow">
                                <option value="7" {{ $period === 7 ? 'selected' : '' }}>7 Hari</option>
                                <option value="30" {{ $period === 30 ? 'selected' : '' }}>30 Hari</option>
                                <option value="90" {{ $period === 90 ? 'selected' : '' }}>90 Hari</option>
                            </select>
                        </form>
                    </div>
                    <p class="text-xs text-[#9aa0a4] mb-5">Total views seluruh konten, {{ $period }} hari terakhir.</p>
                    <x-trend-chart :trend="$viewsTrend" />
                </div>

                {{-- Recent projects --}}
                <div class="card p-6">
                    <div class="flex items-center justify-between mb-4">
                        <h2 class="font-display text-lg font-semibold text-[#14181a]">Proyek Terbaru</h2>
                        <a href="{{ Route::has('production-workflow.index') ? route('production-workflow.index') : '#' }}"
                            class="text-sm font-medium text-[#044b46] hover:underline">Lihat semua</a>
                    </div>

                    @if ($recentItems->isEmpty())
                        <p class="text-sm text-[#9aa0a4] py-6 text-center">Belum ada konten yang tercatat.</p>
                    @else
                        <div class="overflow-x-auto hidden sm:block">
                            <table class="w-full text-sm text-left">
                                <thead>
                                    <tr class="text-[#9aa0a4] text-[11px] uppercase tracking-wide">
                                        <th class="pb-2.5 font-medium">Judul</th>
                                        <th class="pb-2.5 font-medium">Client</th>
                                        <th class="pb-2.5 font-medium">Tipe</th>
                                        <th class="pb-2.5 font-medium">Deadline</th>
                                        <th class="pb-2.5 font-medium">Status</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach ($recentItems as $item)
                                        <tr class="border-t border-[#f2f3f6]">
                                            <td class="py-3 pr-4 font-medium text-[#14181a] whitespace-nowrap">{{ $item['title'] }}</td>
                                            <td class="py-3 pr-4 text-[#5c6266] whitespace-nowrap">{{ $item['client'] }}</td>
                                            <td class="py-3 pr-4 text-[#5c6266] whitespace-nowrap">{{ $item['type'] }}</td>
                                            <td class="py-3 pr-4 text-[#5c6266] whitespace-nowrap">
                                                {{ $item['deadline'] ? $item['deadline']->translatedFormat('d M Y') : '-' }}</td>
                                            <td class="py-3 pr-4">
                                                <span
                                                    class="text-xs px-2 py-1 rounded-full whitespace-nowrap {{ $item['is_overdue'] ? 'bg-[#fdf2f1] text-[#b3423e]' : 'bg-[#f0f5f4] text-[#044b46]' }}">{{ $item['status'] }}</span>
                                            </td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>

                        {{-- Mobile accordion list --}}
                        <div class="sm:hidden divide-y divide-[#f2f3f6]">
                            @foreach ($recentItems as $item)
                                <div x-data="{ open: false }">
                                    <div class="py-3 flex items-center justify-between gap-3 cursor-pointer" @click="open = !open">
                                        <p class="text-sm font-medium text-[#14181a] truncate">{{ $item['title'] }}</p>
                                        <div class="flex items-center gap-2 shrink-0">
                                            <span class="text-xs px-2 py-1 rounded-full whitespace-nowrap {{ $item['is_overdue'] ? 'bg-[#fdf2f1] text-[#b3423e]' : 'bg-[#f0f5f4] text-[#044b46]' }}">{{ $item['status'] }}</span>
                                            <span class="material-symbols-outlined text-[#9aa0a4] text-[18px] transition-transform" :class="open ? 'rotate-180' : ''">expand_more</span>
                                        </div>
                                    </div>
                                    <div x-show="open" x-cloak x-transition class="pb-3 -mt-1 space-y-2 text-sm">
                                        <div class="flex justify-between gap-3">
                                            <span class="text-[#9aa0a4]">Client</span>
                                            <span class="text-[#14181a] text-right">{{ $item['client'] }}</span>
                                        </div>
                                        <div class="flex justify-between gap-3">
                                            <span class="text-[#9aa0a4]">Tipe</span>
                                            <span class="text-[#14181a] text-right">{{ $item['type'] }}</span>
                                        </div>
                                        <div class="flex justify-between gap-3">
                                            <span class="text-[#9aa0a4]">Deadline</span>
                                            <span class="text-[#14181a] text-right">{{ $item['deadline'] ? $item['deadline']->translatedFormat('d M Y') : '-' }}</span>
                                        </div>
                                    </div>
                                </div>
                            @endforeach
                        </div>
                    @endif
                </div>

                {{-- Top performing content (teaser Analytics) --}}
                <div class="card p-6">
                    <div class="flex items-center justify-between mb-1">
                        <h2 class="font-display text-lg font-semibold text-[#14181a]">Konten Berperforma Terbaik</h2>
                        <a href="{{ Route::has('analytics') ? route('analytics') : '#' }}"
                            class="text-sm font-medium text-[#044b46] hover:underline">Lihat Analytics</a>
                    </div>
                    <p class="text-xs text-[#9aa0a4] mb-4">Konten dengan views tertinggi bulan ini, lintas semua client.</p>

                    @if ($topContent->isEmpty())
                        <p class="text-sm text-[#9aa0a4] py-6 text-center">Belum ada data performa konten bulan ini.</p>
                    @else
                        <div class="overflow-x-auto hidden sm:block">
                            <table class="w-full text-sm text-left">
                                <thead>
                                    <tr class="text-[#9aa0a4] text-[11px] uppercase tracking-wide">
                                        <th class="pb-2.5 font-medium">Judul</th>
                                        <th class="pb-2.5 font-medium">Client</th>
                                        <th class="pb-2.5 font-medium">Platform</th>
                                        <th class="pb-2.5 font-medium">Views</th>
                                        <th class="pb-2.5 font-medium">Engagement</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach ($topContent as $item)
                                        <tr class="border-t border-[#f2f3f6]">
                                            <td class="py-3 pr-4 font-medium text-[#14181a] whitespace-nowrap">{{ $item['title'] }}</td>
                                            <td class="py-3 pr-4 text-[#5c6266] whitespace-nowrap">{{ $item['client'] }}</td>
                                            <td class="py-3 pr-4 text-[#5c6266] whitespace-nowrap">{{ $item['platform'] }}</td>
                                            <td class="py-3 pr-4 text-[#5c6266] whitespace-nowrap">{{ number_format($item['views']) }}</td>
                                            <td class="py-3 pr-4 text-[#5c6266] whitespace-nowrap">{{ $item['engagement_rate'] }}%</td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>

                        {{-- Mobile accordion list --}}
                        <div class="sm:hidden divide-y divide-[#f2f3f6]">
                            @foreach ($topContent as $item)
                                <div x-data="{ open: false }">
                                    <div class="py-3 flex items-center justify-between gap-3 cursor-pointer" @click="open = !open">
                                        <p class="text-sm font-medium text-[#14181a] truncate">{{ $item['title'] }}</p>
                                        <div class="flex items-center gap-2 shrink-0">
                                            <span class="flex items-center gap-1 text-xs font-medium text-[#5c6266] whitespace-nowrap">
                                                <span class="material-symbols-outlined text-[14px] text-[#9aa0a4]">visibility</span>
                                                {{ number_format($item['views']) }}
                                            </span>
                                            <span class="material-symbols-outlined text-[#9aa0a4] text-[18px] transition-transform" :class="open ? 'rotate-180' : ''">expand_more</span>
                                        </div>
                                    </div>
                                    <div x-show="open" x-cloak x-transition class="pb-3 -mt-1 space-y-2 text-sm">
                                        <div class="flex justify-between gap-3">
                                            <span class="text-[#9aa0a4]">Client</span>
                                            <span class="text-[#14181a] text-right">{{ $item['client'] }}</span>
                                        </div>
                                        <div class="flex justify-between gap-3">
                                            <span class="text-[#9aa0a4]">Platform</span>
                                            <span class="text-[#14181a] text-right">{{ $item['platform'] }}</span>
                                        </div>
                                        <div class="flex justify-between gap-3">
                                            <span class="text-[#9aa0a4]">Engagement</span>
                                            <span class="text-[#14181a] text-right">{{ $item['engagement_rate'] }}%</span>
                                        </div>
                                    </div>
                                </div>
                            @endforeach
                        </div>
                    @endif
                </div>

                {{-- Top client ranking (Executive Dashboard, PRD 7.3.3) --}}
                <div class="card p-6">
                    <div class="flex items-center justify-between mb-1">
                        <h2 class="font-display text-lg font-semibold text-[#14181a]">Client Teratas</h2>
                        <a href="{{ Route::has('client-management.index') ? route('client-management.index') : '#' }}"
                            class="text-sm font-medium text-[#044b46] hover:underline">Lihat semua client</a>
                    </div>
                    <p class="text-xs text-[#9aa0a4] mb-4">Client dengan performa views tertinggi bulan ini.</p>

                    @if ($topClients->isEmpty())
                        <p class="text-sm text-[#9aa0a4] py-6 text-center">Belum ada data performa client bulan ini.</p>
                    @else
                        <div class="overflow-x-auto hidden sm:block">
                            <table class="w-full text-sm text-left">
                                <thead>
                                    <tr class="text-[#9aa0a4] text-[11px] uppercase tracking-wide">
                                        <th class="pb-2.5 font-medium">Client</th>
                                        <th class="pb-2.5 font-medium">Views</th>
                                        <th class="pb-2.5 font-medium">Engagement</th>
                                        <th class="pb-2.5 font-medium">Jumlah Konten</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach ($topClients as $client)
                                        <tr class="border-t border-[#f2f3f6]">
                                            <td class="py-3 pr-4 font-medium text-[#14181a] whitespace-nowrap">{{ $client['name'] }}</td>
                                            <td class="py-3 pr-4 text-[#5c6266] whitespace-nowrap">{{ number_format($client['views']) }}</td>
                                            <td class="py-3 pr-4 text-[#5c6266] whitespace-nowrap">{{ $client['engagement_rate'] }}%</td>
                                            <td class="py-3 pr-4 text-[#5c6266] whitespace-nowrap">{{ $client['content_count'] }}</td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>

                        {{-- Mobile accordion list --}}
                        <div class="sm:hidden divide-y divide-[#f2f3f6]">
                            @foreach ($topClients as $client)
                                <div x-data="{ open: false }">
                                    <div class="py-3 flex items-center justify-between gap-3 cursor-pointer" @click="open = !open">
                                        <p class="text-sm font-medium text-[#14181a] truncate">{{ $client['name'] }}</p>
                                        <div class="flex items-center gap-2 shrink-0">
                                            <span class="flex items-center gap-1 text-xs font-medium text-[#5c6266] whitespace-nowrap">
                                                <span class="material-symbols-outlined text-[14px] text-[#9aa0a4]">visibility</span>
                                                {{ number_format($client['views']) }}
                                            </span>
                                            <span class="material-symbols-outlined text-[#9aa0a4] text-[18px] transition-transform" :class="open ? 'rotate-180' : ''">expand_more</span>
                                        </div>
                                    </div>
                                    <div x-show="open" x-cloak x-transition class="pb-3 -mt-1 space-y-2 text-sm">
                                        <div class="flex justify-between gap-3">
                                            <span class="text-[#9aa0a4]">Engagement</span>
                                            <span class="text-[#14181a] text-right">{{ $client['engagement_rate'] }}%</span>
                                        </div>
                                        <div class="flex justify-between gap-3">
                                            <span class="text-[#9aa0a4]">Jumlah Konten</span>
                                            <span class="text-[#14181a] text-right">{{ $client['content_count'] }}</span>
                                        </div>
                                    </div>
                                </div>
                            @endforeach
                        </div>
                    @endif
                </div>

            </div>

            {{-- Kolom kanan --}}
            <div class="w-full lg:w-[320px] shrink-0 flex flex-col gap-5">

                <div class="card p-6 flex flex-col">
                    <div class="flex items-center justify-between mb-4">
                        <h2 class="font-display text-base font-semibold text-[#14181a]">Insight AI</h2>
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
                        <h2 class="font-display text-base font-semibold text-[#14181a]">Perlu Perhatian</h2>
                        <span class="material-symbols-outlined text-[#b3423e] text-[18px]">priority_high</span>
                    </div>

                    <div class="space-y-3 flex-1">
                        @forelse ($attentionItems as $item)
                            <div class="bg-[#f7f8fc] rounded-lg p-3.5">
                                <p class="text-sm font-medium text-[#14181a] flex gap-2">
                                    <span class="w-1.5 h-1.5 rounded-full bg-[#b3423e] mt-1.5 shrink-0"></span>
                                    {{ $item['title'] }}
                                </p>
                                <p class="text-xs text-[#9aa0a4] mt-1 pl-3.5">{{ $item['client'] }} &middot; Penanggung Jawab:
                                    {{ $item['pic'] }} &middot; {{ $item['status'] }}</p>
                            </div>
                        @empty
                            <div class="text-center py-8">
                                <span class="material-symbols-outlined text-[#0f7a5f] text-[28px]">check_circle</span>
                                <p class="text-sm text-[#9aa0a4] mt-2">Tidak ada item overdue. Semua on track.</p>
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
                        <h2 class="font-display text-base font-semibold text-[#14181a]">Risiko Tinggi (Prediksi AI)</h2>
                        <span class="material-symbols-outlined text-[#b3423e] text-[18px]">report</span>
                    </div>
                    <p class="text-xs text-[#9aa0a4] mb-4">Belum overdue, tapi diprediksi berisiko terlambat — cegah sebelum
                        kejadian.</p>

                    <div class="space-y-3 flex-1">
                        @forelse ($highRiskItems as $item)
                            <a href="{{ route('content-items.show', $item['id']) }}"
                                class="block bg-[#f7f8fc] rounded-lg p-3.5 hover:bg-[#fdf2f1] transition-colors">
                                <div class="flex items-center justify-between gap-2">
                                    <p class="text-sm font-medium text-[#14181a] flex gap-2 min-w-0">
                                        <span class="w-1.5 h-1.5 rounded-full bg-[#b3423e] mt-1.5 shrink-0"></span>
                                        <span class="truncate">{{ $item['title'] }}</span>
                                    </p>
                                    <span
                                        class="text-xs font-semibold text-[#b3423e] shrink-0">{{ $item['risk_score'] }}%</span>
                                </div>
                                <p class="text-xs text-[#9aa0a4] mt-1 pl-3.5">{{ $item['client'] }} &middot; Penanggung Jawab:
                                    {{ $item['pic'] }}</p>
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

                {{-- Akurasi prediksi AI Delay Risk (teaser Team Performance) --}}
                <div class="card p-6 flex flex-col">
                    <div class="flex items-center gap-3 mb-1">
                        <div class="w-9 h-9 rounded-lg bg-[#eef2fb] flex items-center justify-center shrink-0">
                            <span class="material-symbols-outlined text-[#3452a8] text-[18px]">verified</span>
                        </div>
                        <h2 class="font-display text-base font-semibold text-[#14181a]">Akurasi Prediksi AI</h2>
                    </div>

                    @if ($riskAccuracy['total_evaluated'] === 0)
                        <p class="text-sm text-[#9aa0a4] mt-3">Belum ada cukup data (butuh konten yang sudah upload dan pernah
                            dapat skor risiko).</p>
                    @else
                        @if ($riskAccuracy['high_risk_accuracy'] !== null)
                            <div class="flex items-baseline gap-2 mt-3">
                                <p class="font-display text-2xl font-semibold text-[#14181a]">
                                    {{ $riskAccuracy['high_risk_accuracy'] }}%</p>
                                <p class="text-xs text-[#5c6266]">prediksi <strong>High Risk</strong> benar-benar terlambat</p>
                            </div>
                        @else
                            <p class="text-sm text-[#9aa0a4] mt-3">Belum ada konten dengan prediksi High Risk yang sudah selesai
                                upload.</p>
                        @endif
                    @endif

                    <a href="{{ route('team-performance.index') }}"
                        class="mt-4 w-full bg-[#f7f8fc] text-[#044b46] text-sm font-medium py-2.5 rounded-lg hover:bg-[#f0f5f4] transition-colors flex items-center justify-center gap-1.5">
                        Lihat detail lengkap
                        <span class="material-symbols-outlined text-[15px]">arrow_forward</span>
                    </a>
                </div>

            </div>

        </div>
    </div>

@endsection