@extends('layouts.app')
@section('title', 'Content Analytics')
@section('content')

<div class="p-8">

    {{-- Header --}}
    <div class="flex items-start justify-between mb-8">
        <div>
            <h1 class="text-4xl font-extrabold text-[#191c1c]">Content Analytics</h1>
            <p class="text-gray-500 mt-2">Performa konten terpublikasi lintas client &amp; platform.</p>
        </div>

        <div class="flex items-center gap-3">
            {{-- Filter periode --}}
            <form method="GET" class="flex items-center gap-3">
                @if ($selectedClientId)
                    <input type="hidden" name="client_id" value="{{ $selectedClientId }}">
                @endif

                <select name="client_id" onchange="this.form.submit()"
                        class="text-sm font-medium border border-gray-200 rounded-xl px-4 py-2.5 bg-white focus:outline-none focus:border-[#044b46]">
                    <option value="">Semua Client</option>
                    @foreach ($clientOptions as $client)
                        <option value="{{ $client->id }}" {{ (string) $selectedClientId === (string) $client->id ? 'selected' : '' }}>
                            {{ $client->name }}
                        </option>
                    @endforeach
                </select>

                <select name="period" onchange="this.form.submit()"
                        class="text-sm font-medium border border-gray-200 rounded-xl px-4 py-2.5 bg-white focus:outline-none focus:border-[#044b46]">
                    <option value="7" {{ $period === 7 ? 'selected' : '' }}>Last 7 Days</option>
                    <option value="30" {{ $period === 30 ? 'selected' : '' }}>Last 30 Days</option>
                    <option value="90" {{ $period === 90 ? 'selected' : '' }}>Last 90 Days</option>
                </select>
            </form>

            <button type="button"
                    class="flex items-center gap-2 bg-[#044b46] text-white text-sm font-semibold px-5 py-3 rounded-xl hover:bg-[#044b46]/90 transition-colors duration-150 shrink-0">
                <span class="material-symbols-outlined text-[18px]">download</span>
                Export
            </button>
        </div>
    </div>

    {{-- Stat cards --}}
    <div class="grid grid-cols-4 gap-6 mb-6">
        @foreach ($stats as $stat)
            <div class="relative bg-white rounded-2xl shadow-sm p-6 overflow-hidden">
                <div class="absolute -right-6 -top-6 w-24 h-24 rounded-full bg-[#044b46]/5 blur-xl"></div>

                <div class="flex items-center gap-3 mb-5">
                    <div class="w-10 h-10 rounded-xl bg-[#044b46]/10 flex items-center justify-center">
                        <span class="material-symbols-outlined text-[#044b46] text-[20px]">{{ $stat['icon'] }}</span>
                    </div>
                    <span class="text-sm font-semibold text-gray-500">{{ $stat['label'] }}</span>
                </div>

                <p class="text-3xl font-extrabold text-[#191c1c] mb-3">{{ $stat['value'] }}</p>

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

    <div class="flex gap-6 items-start">

        {{-- Main column --}}
        <div class="flex-1 min-w-0 space-y-6">

            {{-- Views trend chart --}}
            <div class="bg-white rounded-2xl shadow-sm p-6">
                <div class="flex items-start justify-between mb-8">
                    <div>
                        <h2 class="text-2xl font-extrabold text-[#191c1c]">Views Over Time</h2>
                        <p class="text-sm text-gray-500 mt-1">Total views seluruh konten pada periode terpilih.</p>
                    </div>
                </div>

                @if (collect($trend)->sum('value') === 0)
                    <p class="text-sm text-gray-400 text-center py-16">Belum ada data metrik pada periode ini.</p>
                @else
                    @php
                        $max = max(collect($trend)->max('value'), 1);
                        $peak = collect($trend)->sortByDesc('value')->keys()->first();
                    @endphp

                    <div class="flex items-end justify-between gap-2 h-56 overflow-x-auto">
                        @foreach ($trend as $i => $point)
                            <div class="flex-1 min-w-[20px] flex flex-col items-center gap-2">
                                <span class="text-[10px] font-semibold text-gray-400">{{ $point['value'] > 0 ? number_format($point['value']) : '' }}</span>
                                <div
                                    class="w-full max-w-8 rounded-t-lg transition-all duration-300 {{ $i === $peak && $point['value'] > 0 ? 'bg-[#044b46]' : 'bg-[#044b46]/25' }}"
                                    style="height: {{ max(($point['value'] / $max) * 100, 4) }}%"
                                ></div>
                                <span class="text-[10px] font-medium text-gray-400">{{ $point['label'] }}</span>
                            </div>
                        @endforeach
                    </div>
                @endif
            </div>

            {{-- Top performing content --}}
            <div class="bg-white rounded-2xl shadow-sm p-6">
                <div class="flex items-center justify-between mb-5">
                    <h2 class="text-xl font-extrabold text-[#191c1c]">Top Performing Content</h2>
                </div>

                @if ($topContent->isEmpty())
                    <p class="text-sm text-gray-400 py-6 text-center">Belum ada konten dengan data performa.</p>
                @else
                    <table class="w-full text-sm text-left">
                        <thead class="text-gray-400 text-xs uppercase">
                            <tr>
                                <th class="py-2 pr-4 font-semibold">Konten</th>
                                <th class="py-2 pr-4 font-semibold">Client</th>
                                <th class="py-2 pr-4 font-semibold">Platform</th>
                                <th class="py-2 pr-4 font-semibold">Views</th>
                                <th class="py-2 pr-4 font-semibold">Engagement</th>
                                <th class="py-2 pr-4 font-semibold"></th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($topContent as $content)
                                <tr class="border-t border-gray-50">
                                    <td class="py-3 pr-4 font-medium text-[#191c1c]">
                                        <div class="flex items-center gap-2">
                                            @if ($loop->first)
                                                <span class="text-[10px] font-bold px-2 py-0.5 rounded-full bg-amber-100 text-amber-700">Top</span>
                                            @endif
                                            {{ $content['title'] }}
                                        </div>
                                        <p class="text-xs text-gray-400 mt-0.5">{{ $content['type'] }}</p>
                                    </td>
                                    <td class="py-3 pr-4 text-gray-500">{{ $content['client'] }}</td>
                                    <td class="py-3 pr-4 text-gray-500">{{ $content['platform'] }}</td>
                                    <td class="py-3 pr-4 text-gray-700 font-semibold">{{ number_format($content['views']) }}</td>
                                    <td class="py-3 pr-4">
                                        <span class="text-xs px-2 py-1 rounded-full bg-[#044b46]/10 text-[#044b46]">
                                            {{ $content['engagement_rate'] }}%
                                        </span>
                                    </td>
                                    <td class="py-3 pr-4 text-right">
                                        <a href="{{ route('analytics.show', $content['id']) }}"
                                           class="text-xs font-semibold text-[#044b46] hover:underline">
                                            Detail
                                        </a>
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

            {{-- Platform breakdown --}}
            <div class="bg-white rounded-2xl shadow-sm p-6">
                <div class="flex items-center justify-between mb-5">
                    <h2 class="text-xl font-extrabold text-[#191c1c]">Traffic per Platform</h2>
                    <div class="w-9 h-9 rounded-full bg-[#044b46]/10 flex items-center justify-center">
                        <span class="material-symbols-outlined text-[#044b46] text-[18px]">bar_chart</span>
                    </div>
                </div>

                @if ($platformBreakdown->isEmpty())
                    <p class="text-sm text-gray-400 text-center py-6">Belum ada data.</p>
                @else
                    @php $maxPlatform = max($platformBreakdown->max('value'), 1); @endphp
                    <div class="space-y-4">
                        @foreach ($platformBreakdown as $row)
                            <div>
                                <div class="flex items-center justify-between mb-1.5">
                                    <span class="text-sm font-medium text-gray-600">{{ $row['label'] }}</span>
                                    <span class="text-sm font-semibold text-[#191c1c]">{{ number_format($row['value']) }}</span>
                                </div>
                                <div class="w-full h-2 rounded-full bg-gray-100 overflow-hidden">
                                    <div class="h-full bg-[#044b46] rounded-full"
                                         style="width: {{ max(($row['value'] / $maxPlatform) * 100, 4) }}%"></div>
                                </div>
                            </div>
                        @endforeach
                    </div>
                @endif
            </div>

        </div>

    </div>
</div>

@endsection