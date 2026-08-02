@extends('layouts.app')
@section('title', 'Content Analytics')
@section('content')

<div class="p-8 max-w-[1400px]">

    {{-- Header --}}
    <div class="flex items-start justify-between mb-7">
        <div>
            <h1 class="font-display text-[32px] font-semibold text-[#14181a]">Content Analytics</h1>
            <p class="text-[#5c6266] text-sm mt-1">Analisis performa konten lintas client &amp; platform.</p>
        </div>

        <div class="flex items-center gap-2">
            <a href="{{ route('analytics.table') }}"
               class="text-sm font-medium text-[#5c6266] hover:text-[#14181a] flex items-center gap-1.5 px-3 py-2 rounded-lg hover:bg-white transition-colors">
                <span class="material-symbols-outlined text-[17px]">table_rows</span> Table
            </a>
            <a href="{{ route('audience') }}"
               class="text-sm font-medium text-[#5c6266] hover:text-[#14181a] flex items-center gap-1.5 px-3 py-2 rounded-lg hover:bg-white transition-colors">
                <span class="material-symbols-outlined text-[17px]">groups</span> Audience
            </a>

            @if ($selectedClientId)
                <a href="{{ route('analytics.export', ['client_id' => $selectedClientId, 'period' => $period]) }}"
                   class="text-sm font-medium text-white bg-[#044b46] hover:bg-[#033b37] flex items-center gap-1.5 px-4 py-2 rounded-lg transition-colors">
                    <span class="material-symbols-outlined text-[17px]">download</span> Export
                </a>
            @endif
        </div>
    </div>

    {{-- Filter bar --}}
    <form method="GET" class="card p-4 mb-6 flex items-center gap-3 flex-wrap">
        <select name="client_id" onchange="this.form.submit()"
                class="text-sm border border-[#eef0f4] rounded-lg px-3.5 py-2 bg-white focus:outline-none focus:border-[#044b46]/40">
            <option value="">Pilih Client...</option>
            @foreach ($clientOptions as $client)
                <option value="{{ $client->id }}" {{ (string) $selectedClientId === (string) $client->id ? 'selected' : '' }}>{{ $client->name }}</option>
            @endforeach
        </select>

        <select name="period" onchange="this.form.submit()"
                class="text-sm border border-[#eef0f4] rounded-lg px-3.5 py-2 bg-white focus:outline-none focus:border-[#044b46]/40">
            <option value="7" {{ $period === 7 ? 'selected' : '' }}>Last 7 Days</option>
            <option value="30" {{ $period === 30 ? 'selected' : '' }}>Last 30 Days</option>
            <option value="90" {{ $period === 90 ? 'selected' : '' }}>Last 90 Days</option>
        </select>
    </form>

    @if (! empty($noClientSelected))
        <div class="card p-16 flex flex-col items-center justify-center text-center">
            <div class="w-14 h-14 rounded-full bg-[#f0f5f4] flex items-center justify-center mb-4">
                <span class="material-symbols-outlined text-[#044b46] text-[26px]">filter_alt</span>
            </div>
            <h2 class="font-display text-lg font-semibold text-[#14181a] mb-1.5">Pilih client dulu</h2>
            <p class="text-sm text-[#5c6266] max-w-sm">Performa konten ditampilkan per client. Pilih salah satu di dropdown atas untuk mulai.</p>
        </div>
    @else

        {{-- Stat cards --}}
        <div class="grid grid-cols-4 gap-4 mb-6">
            @foreach ($stats as $stat)
                <div class="card p-5">
                    <div class="flex items-center justify-between mb-4">
                        <span class="text-[13px] text-[#5c6266]">{{ $stat['label'] }}</span>
                        <span class="material-symbols-outlined text-[#c3c7cb] text-[18px]">{{ $stat['icon'] }}</span>
                    </div>
                    <p class="font-display text-[26px] font-semibold text-[#14181a] mb-2">{{ $stat['value'] }}</p>
                    <p class="text-xs flex items-center gap-1
                        {{ $stat['trend'] === 'up' ? 'text-[#0f7a5f]' : ($stat['trend'] === 'down' ? 'text-[#b3423e]' : 'text-[#9aa0a4]') }}">
                        @if ($stat['trend'] === 'up')
                            <span class="material-symbols-outlined text-[13px]">trending_up</span>
                        @elseif ($stat['trend'] === 'down')
                            <span class="material-symbols-outlined text-[13px]">trending_down</span>
                        @endif
                        {{ $stat['change'] }}
                    </p>
                </div>
            @endforeach
        </div>

        <div class="flex gap-5 items-start">
            <div class="flex-1 min-w-0 space-y-5">

                {{-- Trend chart --}}
                <div class="card p-6">
                    <h2 class="font-display text-lg font-semibold text-[#14181a] mb-1">Views Over Time</h2>
                    <p class="text-xs text-[#9aa0a4] mb-5">Total views seluruh konten pada periode terpilih.</p>
                    <x-trend-chart :trend="$trend" />
                </div>

                {{-- Top performing content --}}
                <div class="card p-6">
                    <h2 class="font-display text-lg font-semibold text-[#14181a] mb-4">Top Performing Content</h2>

                    @if ($topContent->isEmpty())
                        <p class="text-sm text-[#9aa0a4] py-6 text-center">Belum ada konten dengan data performa.</p>
                    @else
                        <table class="w-full text-sm text-left">
                            <thead>
                                <tr class="text-[#9aa0a4] text-[11px] uppercase tracking-wide">
                                    <th class="pb-2.5 font-medium">Konten</th>
                                    <th class="pb-2.5 font-medium">Client</th>
                                    <th class="pb-2.5 font-medium">Platform</th>
                                    <th class="pb-2.5 font-medium">Views</th>
                                    <th class="pb-2.5 font-medium">Engagement</th>
                                    <th class="pb-2.5"></th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($topContent as $content)
                                    <tr class="border-t border-[#f2f3f6]">
                                        <td class="py-3 pr-3 font-medium text-[#14181a]">
                                            {{ $content['title'] }}
                                            <p class="text-xs text-[#9aa0a4] font-normal mt-0.5">{{ $content['type'] }}</p>
                                        </td>
                                        <td class="py-3 pr-3 text-[#5c6266]">{{ $content['client'] }}</td>
                                        <td class="py-3 pr-3 text-[#5c6266]">{{ $content['platform'] }}</td>
                                        <td class="py-3 pr-3 font-medium text-[#14181a]">{{ number_format($content['views']) }}</td>
                                        <td class="py-3 pr-3">
                                            <span class="text-xs px-2 py-1 rounded-full bg-[#f0f5f4] text-[#044b46]">{{ $content['engagement_rate'] }}%</span>
                                        </td>
                                        <td class="py-3 text-right">
                                            <a href="{{ route('analytics.show', $content['id']) }}" class="text-xs font-medium text-[#044b46] hover:underline">Detail</a>
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    @endif
                </div>
            </div>

            {{-- Right column --}}
            <div class="w-[300px] shrink-0">
                <div class="card p-6">
                    <h2 class="font-display text-lg font-semibold text-[#14181a] mb-5">Traffic per Platform</h2>

                    @if ($platformBreakdown->isEmpty())
                        <p class="text-sm text-[#9aa0a4] text-center py-6">Belum ada data.</p>
                    @else
                        @php $maxPlatform = max($platformBreakdown->max('value'), 1); @endphp
                        <div class="space-y-4">
                            @foreach ($platformBreakdown as $row)
                                <div>
                                    <div class="flex items-center justify-between mb-1.5 text-sm">
                                        <span class="text-[#5c6266]">{{ $row['label'] }}</span>
                                        <span class="font-medium text-[#14181a]">{{ number_format($row['value']) }}</span>
                                    </div>
                                    <div class="w-full h-1.5 rounded-full bg-[#f2f3f6] overflow-hidden">
                                        <div class="h-full bg-[#044b46] rounded-full" style="width: {{ max(($row['value'] / $maxPlatform) * 100, 3) }}%"></div>
                                    </div>
                                </div>
                            @endforeach
                        </div>
                    @endif
                </div>
            </div>
        </div>
    @endif
</div>

@endsection