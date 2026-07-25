@extends('layouts.app')
@section('title', $contentItem->title . ' — Performance')
@section('content')

<div class="p-8 max-w-6xl">

    {{-- Breadcrumb --}}
    <div class="flex items-center gap-2 text-xs text-gray-400 mb-3">
        <a href="{{ route('analytics') }}" class="hover:text-[#044b46] font-medium">Analytics</a>
        <span class="material-symbols-outlined text-[14px]">chevron_right</span>
        <span>{{ $contentItem->client->name ?? '-' }}</span>
        <span class="material-symbols-outlined text-[14px]">chevron_right</span>
        <span class="text-gray-600 font-semibold">{{ $contentItem->title }}</span>
    </div>

    {{-- Header --}}
    <div class="flex items-center justify-between mb-8">
        <div class="flex items-center gap-3">
            <a href="{{ route('analytics') }}" class="text-gray-400 hover:text-gray-600">
                <span class="material-symbols-outlined">arrow_back</span>
            </a>
            <div>
                <h1 class="text-3xl font-extrabold text-[#191c1c]">{{ $contentItem->title }}</h1>
                <p class="text-gray-500 mt-1 text-sm">{{ $contentItem->contentType->name ?? '-' }} &middot; {{ $contentItem->platform->name ?? '-' }}</p>
            </div>
        </div>

        <span class="text-xs font-semibold px-3 py-1.5 rounded-full shadow-sm
            {{ $contentItem->is_posted ? 'bg-gradient-to-r from-[#044b46] to-[#0a8f76] text-white' : 'bg-amber-100 text-amber-700' }}">
            {{ $contentItem->is_posted ? 'Published' : 'Belum Terpublikasi' }}
        </span>
    </div>

    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">

        {{-- Kolom Kiri: Performance overview --}}
        <div class="lg:col-span-2 space-y-6">

            {{-- Mini stat cards --}}
            <div class="grid grid-cols-3 gap-4">
                <div class="bg-white rounded-xl shadow-[0_4px_20px_rgba(15,23,42,0.05)] border border-gray-100 p-5">
                    <p class="text-xs font-semibold text-gray-400 uppercase mb-2">Total Views</p>
                    <p class="text-2xl font-extrabold text-[#191c1c]">{{ number_format($totalViews) }}</p>
                </div>
                <div class="bg-white rounded-xl shadow-[0_4px_20px_rgba(15,23,42,0.05)] border border-gray-100 p-5">
                    <p class="text-xs font-semibold text-gray-400 uppercase mb-2">Avg. Engagement</p>
                    <p class="text-2xl font-extrabold text-[#191c1c]">{{ $avgEngagement }}%</p>
                </div>
                <div class="bg-white rounded-xl shadow-[0_4px_20px_rgba(15,23,42,0.05)] border border-gray-100 p-5">
                    <p class="text-xs font-semibold text-gray-400 uppercase mb-2">Hari Terlacak</p>
                    <p class="text-2xl font-extrabold text-[#191c1c]">{{ $daysTracked }}</p>
                </div>
            </div>

            {{-- Performance trend chart --}}
            <div class="bg-white rounded-xl shadow-[0_4px_20px_rgba(15,23,42,0.05)] border border-gray-100 p-5">
                <div class="flex items-center justify-between mb-6">
                    <h3 class="text-sm font-bold text-gray-700">Views Trend</h3>
                    <div class="flex items-center gap-2">
                        <span class="material-symbols-outlined text-gray-400 text-[18px] cursor-pointer">zoom_in</span>
                        <span class="material-symbols-outlined text-gray-400 text-[18px] cursor-pointer">zoom_out</span>
                    </div>
                </div>

                @if ($trend->isEmpty())
                    <p class="text-sm text-gray-400 text-center py-16">Belum ada metrik yang tercatat untuk konten ini.</p>
                @else
                    <x-trend-chart :trend="$trend" />
                @endif
            </div>

            {{-- Metric history table --}}
            <div class="bg-white rounded-xl shadow-[0_4px_20px_rgba(15,23,42,0.05)] border border-gray-100 p-5">
                <h3 class="text-sm font-bold text-gray-700 mb-4">Metric History ({{ $metrics->count() }})</h3>

                @if ($metrics->isEmpty())
                    <p class="text-xs text-gray-400 italic">Belum ada data metrik yang diimpor.</p>
                @else
                    <table class="w-full text-sm text-left">
                        <thead class="text-gray-400 text-xs uppercase">
                            <tr>
                                <th class="py-2 pr-4 font-semibold">Tanggal</th>
                                <th class="py-2 pr-4 font-semibold">Platform</th>
                                <th class="py-2 pr-4 font-semibold">Views</th>
                                <th class="py-2 pr-4 font-semibold">Engagement</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($metrics as $metric)
                                <tr class="border-t border-gray-50">
                                    <td class="py-2.5 pr-4 text-gray-600">
                                        {{ \Illuminate\Support\Carbon::parse($metric->metric_date)->translatedFormat('d M Y') }}
                                    </td>
                                    <td class="py-2.5 pr-4 text-gray-500">{{ $metric->platform->name ?? '-' }}</td>
                                    <td class="py-2.5 pr-4 font-semibold text-[#191c1c]">{{ number_format($metric->views) }}</td>
                                    <td class="py-2.5 pr-4">
                                        <span class="text-xs px-2 py-1 rounded-full bg-[#044b46]/10 text-[#044b46]">
                                            {{ $metric->engagement_rate }}%
                                        </span>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                @endif
            </div>

        </div>

        {{-- Kolom Kanan --}}
        <div class="space-y-6">

            {{-- Content Info --}}
            <div class="bg-white rounded-xl shadow-[0_4px_20px_rgba(15,23,42,0.05)] border border-gray-100 p-5">
                <h3 class="text-sm font-bold text-gray-700 mb-4">Content Info</h3>

                <div class="space-y-3 text-sm">
                    <div class="flex items-center justify-between">
                        <span class="text-gray-400">Client</span>
                        <span class="font-medium text-gray-700">{{ $contentItem->client->name ?? '-' }}</span>
                    </div>
                    <div class="flex items-center justify-between">
                        <span class="text-gray-400">Platform</span>
                        <span class="font-medium text-gray-700">{{ $contentItem->platform->name ?? '-' }}</span>
                    </div>
                    <div class="flex items-center justify-between">
                        <span class="text-gray-400">Tipe Konten</span>
                        <span class="font-medium text-gray-700">{{ $contentItem->contentType->name ?? '-' }}</span>
                    </div>
                    <div class="flex items-center justify-between">
                        <span class="text-gray-400">Deadline</span>
                        <span class="font-medium text-gray-700">{{ $contentItem->deadline_at->translatedFormat('d M Y') }}</span>
                    </div>
                    @if ($bestDate)
                        <div class="flex items-center justify-between">
                            <span class="text-gray-400">Hari Terbaik</span>
                            <span class="font-medium text-gray-700">
                                {{ \Illuminate\Support\Carbon::parse($bestDate->metric_date)->translatedFormat('d M Y') }}
                            </span>
                        </div>
                    @endif
                </div>
            </div>

            {{-- Sync Log --}}
            <div class="bg-white rounded-xl shadow-[0_4px_20px_rgba(15,23,42,0.05)] border border-gray-100 p-5">
                <h3 class="text-sm font-bold text-gray-700 mb-4">Sync Log ({{ $syncLogs->count() }})</h3>

                @if ($syncLogs->isEmpty())
                    <p class="text-xs text-gray-400 italic">Belum ada riwayat import/sinkronisasi.</p>
                @else
                    <div class="space-y-3">
                        @foreach ($syncLogs as $log)
                            <div class="border border-gray-100 rounded-lg p-3 {{ $log->status === 'failed' ? 'bg-rose-50' : 'bg-gray-50' }}">
                                <div class="flex items-center justify-between mb-1">
                                    <p class="text-xs font-semibold text-gray-700">
                                        {{ strtoupper($log->source_type) }} &middot; {{ $log->importedBy->name ?? '-' }}
                                    </p>
                                    <span class="text-[10px] px-2 py-0.5 rounded-full
                                        {{ $log->status === 'success' ? 'bg-emerald-100 text-emerald-700' : '' }}
                                        {{ $log->status === 'failed' ? 'bg-rose-200 text-rose-800' : '' }}
                                        {{ $log->status === 'pending' ? 'bg-amber-100 text-amber-700' : '' }}">
                                        {{ $log->status }}
                                    </span>
                                </div>
                                <p class="text-xs text-gray-500">{{ $log->created_at->translatedFormat('d M Y, H:i') }}</p>
                            </div>
                        @endforeach
                    </div>
                @endif
            </div>

        </div>

    </div>
</div>

@endsection