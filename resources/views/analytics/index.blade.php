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
            <a href="{{ route('analytics.table') }}"
               class="text-sm font-semibold text-gray-500 hover:text-[#044b46] flex items-center gap-1.5 px-3 py-2.5 rounded-xl hover:bg-gray-50 transition-colors duration-150">
                <span class="material-symbols-outlined text-[18px]">table_rows</span>
                Table
            </a>

            <a href="{{ route('audience') }}"
               class="text-sm font-semibold text-gray-500 hover:text-[#044b46] flex items-center gap-1.5 px-3 py-2.5 rounded-xl hover:bg-gray-50 transition-colors duration-150">
                <span class="material-symbols-outlined text-[18px]">groups</span>
                Audience
            </a>

            {{-- Filter periode --}}
            <form method="GET" class="flex items-center gap-3">
                @if ($selectedClientId)
                    <input type="hidden" name="client_id" value="{{ $selectedClientId }}">
                @endif

                <select name="client_id" onchange="this.form.submit()"
                        class="text-sm font-medium border border-gray-200 rounded-xl px-4 py-2.5 bg-white focus:outline-none focus:border-[#044b46]">
                    <option value="">Pilih Client...</option>
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

            @if ($selectedClientId)
                <a href="{{ route('analytics.export', ['client_id' => $selectedClientId, 'period' => $period]) }}"
                        class="flex items-center gap-2 bg-gradient-to-r from-[#044b46] to-[#0a6b5c] text-white text-sm font-semibold px-5 py-3 rounded-xl hover:opacity-90 transition-opacity duration-150 shrink-0 shadow-[0_6px_16px_rgba(4,75,70,0.25)]">
                    <span class="material-symbols-outlined text-[18px]">download</span>
                    Export
                </a>
            @endif
        </div>
    </div>

    @if (! empty($noClientSelected))
        {{-- Empty state: belum pilih client --}}
        <div class="bg-white rounded-2xl shadow-[0_4px_24px_rgba(15,23,42,0.06)] p-16 flex flex-col items-center justify-center text-center">
            <div class="w-16 h-16 rounded-full bg-[#044b46]/10 flex items-center justify-center mb-5">
                <span class="material-symbols-outlined text-[#044b46] text-[30px]">filter_alt</span>
            </div>
            <h2 class="text-xl font-extrabold text-[#191c1c] mb-2">Pilih client dulu, yuk</h2>
            <p class="text-sm text-gray-500 max-w-sm">
                Biar datanya fokus &amp; nggak numpuk, performa konten cuma ditampilkan
                per client. Pilih salah satu client di dropdown atas untuk mulai lihat datanya.
            </p>
        </div>
    @else

    {{-- Stat cards --}}
    @php
        $statColors = [
            ['icon' => 'text-[#044b46]', 'chip' => 'bg-[#044b46]/10', 'glow' => 'bg-[#044b46]/10'],
            ['icon' => 'text-rose-500', 'chip' => 'bg-rose-50', 'glow' => 'bg-rose-200/30'],
            ['icon' => 'text-indigo-500', 'chip' => 'bg-indigo-50', 'glow' => 'bg-indigo-200/30'],
            ['icon' => 'text-sky-500', 'chip' => 'bg-sky-50', 'glow' => 'bg-sky-200/30'],
        ];
    @endphp
    <div class="grid grid-cols-4 gap-6 mb-6">
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
            <div class="bg-white rounded-2xl shadow-[0_4px_24px_rgba(15,23,42,0.06)] p-6">
                <div class="flex items-start justify-between mb-8">
                    <div>
                        <h2 class="text-2xl font-extrabold text-[#191c1c]">Views Over Time</h2>
                        <p class="text-sm text-gray-500 mt-1">Total views seluruh konten pada periode terpilih.</p>
                    </div>
                </div>

                @if (collect($trend)->sum('value') === 0)
                    <p class="text-sm text-gray-400 text-center py-16">Belum ada data metrik pada periode ini.</p>
                @else
                    <x-trend-chart :trend="$trend" />
                @endif
            </div>

            {{-- Top performing content --}}
            <div class="bg-white rounded-2xl shadow-[0_4px_24px_rgba(15,23,42,0.06)] p-6">
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
            <div class="bg-gradient-to-br from-white to-[#f4f9f7] rounded-2xl shadow-[0_4px_24px_rgba(15,23,42,0.06)] p-6 border border-[#044b46]/5">
                <div class="flex items-center justify-between mb-5">
                    <h2 class="text-xl font-extrabold text-[#191c1c]">Traffic per Platform</h2>
                    <div class="w-9 h-9 rounded-full bg-gradient-to-br from-[#044b46] to-[#0a8f76] flex items-center justify-center shadow-[0_4px_10px_rgba(4,75,70,0.3)]">
                        <span class="material-symbols-outlined text-white text-[18px]">bar_chart</span>
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
                                    <div class="h-full bg-gradient-to-r from-[#044b46] to-[#0a8f76] rounded-full"
                                         style="width: {{ max(($row['value'] / $maxPlatform) * 100, 4) }}%"></div>
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