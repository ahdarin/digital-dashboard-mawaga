@extends('layouts.app')
@section('title', $contentItem->title . ' — Performance')
@section('content')

<div class="p-8 max-w-6xl">

    <div class="flex items-center gap-2 text-xs text-[#9aa0a4] mb-3">
        <a href="{{ route('analytics') }}" class="hover:text-[#044b46] font-medium">Analytics</a>
        <span class="material-symbols-outlined text-[13px]">chevron_right</span>
        <span>{{ $contentItem->client->name ?? '-' }}</span>
        <span class="material-symbols-outlined text-[13px]">chevron_right</span>
        <span class="text-[#5c6266] font-medium">{{ $contentItem->title }}</span>
    </div>

    <div class="flex items-center justify-between mb-6">
        <div class="flex items-center gap-3">
            <a href="{{ route('analytics') }}" class="text-[#9aa0a4] hover:text-[#5c6266]">
                <span class="material-symbols-outlined">arrow_back</span>
            </a>
            <div>
                <h1 class="font-display text-[26px] font-semibold text-[#14181a]">{{ $contentItem->title }}</h1>
                <p class="text-[#5c6266] mt-0.5 text-sm">{{ $contentItem->contentType->name ?? '-' }} &middot; {{ $contentItem->platform->name ?? '-' }}</p>
            </div>
        </div>

        <span class="text-xs font-medium px-3 py-1.5 rounded-full
            {{ $contentItem->is_posted ? 'bg-[#f0f5f4] text-[#044b46]' : 'bg-[#fdf6ec] text-[#b8873a]' }}">
            {{ $contentItem->is_posted ? 'Published' : 'Belum Terpublikasi' }}
        </span>
    </div>

    @if ($hasPeerComparison && $viewsVsPeerPct !== null)
        <div class="card p-4 mb-6 flex items-center gap-3.5 {{ $viewsVsPeerPct < 0 ? 'border-[#f5d9d7]' : '' }}">
            <div class="w-9 h-9 rounded-lg flex items-center justify-center shrink-0 {{ $viewsVsPeerPct >= 0 ? 'bg-[#f0f5f4]' : 'bg-[#fdf2f1]' }}">
                <span class="material-symbols-outlined text-[18px] {{ $viewsVsPeerPct >= 0 ? 'text-[#044b46]' : 'text-[#b3423e]' }}">
                    {{ $viewsVsPeerPct >= 0 ? 'trending_up' : 'trending_down' }}
                </span>
            </div>
            <div class="flex-1">
                <p class="text-sm font-medium text-[#14181a]">
                    @if ($viewsVsPeerPct >= 0)
                        {{ $viewsVsPeerPct }}% di atas rata-rata konten {{ $contentItem->client->name ?? 'client ini' }}
                    @else
                        {{ abs($viewsVsPeerPct) }}% di bawah rata-rata konten {{ $contentItem->client->name ?? 'client ini' }}
                    @endif
                </p>
                <p class="text-xs text-[#9aa0a4] mt-0.5">
                    Views 30 hari terakhir vs rata-rata konten lain client ini ({{ number_format($peerAvgViews) }} views).
                    @if ($engagementVsPeerPct !== null)
                        Engagement {{ $engagementVsPeerPct >= 0 ? $engagementVsPeerPct.'% di atas' : abs($engagementVsPeerPct).'% di bawah' }} rata-rata juga.
                    @endif
                </p>
            </div>
        </div>
    @elseif (! $hasPeerComparison)
        <div class="card p-3.5 mb-6 flex items-center gap-2.5">
            <span class="material-symbols-outlined text-[#c3c7cb] text-[18px]">info</span>
            <p class="text-xs text-[#9aa0a4]">Belum bisa dibandingin sama konten lain - butuh data metrik konten lain milik client ini.</p>
        </div>
    @endif

    <div class="grid grid-cols-1 lg:grid-cols-3 gap-5">

        <div class="lg:col-span-2 space-y-5">

            <div class="grid grid-cols-3 gap-4">
                <div class="card p-5">
                    <p class="text-xs text-[#9aa0a4] mb-1.5">Total Views</p>
                    <p class="font-display text-xl font-semibold text-[#14181a]">{{ number_format($totalViews) }}</p>
                </div>
                <div class="card p-5">
                    <p class="text-xs text-[#9aa0a4] mb-1.5">Avg. Engagement</p>
                    <p class="font-display text-xl font-semibold text-[#14181a]">{{ $avgEngagement }}%</p>
                </div>
                <div class="card p-5">
                    <p class="text-xs text-[#9aa0a4] mb-1.5">Hari Terlacak</p>
                    <p class="font-display text-xl font-semibold text-[#14181a]">{{ $daysTracked }}</p>
                </div>
            </div>

            @if ($hasVideoMetrics)
                <div class="grid grid-cols-4 gap-4">
                    <div class="card p-5 bg-[#eef2fb] border-0">
                        <p class="text-xs text-[#3452a8]/70 mb-1.5">Avg. Watch Time</p>
                        <p class="text-lg font-semibold text-[#14181a]">{{ $avgWatchTime !== null ? $avgWatchTime.'s' : '-' }}</p>
                    </div>
                    <div class="card p-5 bg-[#eef2fb] border-0">
                        <p class="text-xs text-[#3452a8]/70 mb-1.5">Completion Rate</p>
                        <p class="text-lg font-semibold text-[#14181a]">{{ $avgCompletionRate !== null ? $avgCompletionRate.'%' : '-' }}</p>
                    </div>
                    <div class="card p-5 bg-[#eef2fb] border-0">
                        <p class="text-xs text-[#3452a8]/70 mb-1.5">Shares</p>
                        <p class="text-lg font-semibold text-[#14181a]">{{ $totalShares !== null ? number_format($totalShares) : '-' }}</p>
                    </div>
                    <div class="card p-5 bg-[#eef2fb] border-0">
                        <p class="text-xs text-[#3452a8]/70 mb-1.5">Saves</p>
                        <p class="text-lg font-semibold text-[#14181a]">{{ $totalSaves !== null ? number_format($totalSaves) : '-' }}</p>
                    </div>
                </div>
            @endif

            <div class="card p-6">
                <h3 class="text-sm font-semibold text-[#14181a] mb-5">Views Trend</h3>
                @if ($trend->isEmpty())
                    <p class="text-sm text-[#9aa0a4] text-center py-16">Belum ada metrik yang tercatat.</p>
                @else
                    <x-trend-chart :trend="$trend" />
                @endif
            </div>

            <div class="card p-6">
                <h3 class="text-sm font-semibold text-[#14181a] mb-4">Metric History ({{ $metrics->count() }})</h3>
                @if ($metrics->isEmpty())
                    <p class="text-xs text-[#9aa0a4] italic">Belum ada data metrik yang diimpor.</p>
                @else
                    <table class="w-full text-sm text-left">
                        <thead>
                            <tr class="text-[#9aa0a4] text-[11px] uppercase tracking-wide">
                                <th class="pb-2 font-medium">Tanggal</th>
                                <th class="pb-2 font-medium">Platform</th>
                                <th class="pb-2 font-medium">Views</th>
                                <th class="pb-2 font-medium">Engagement</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($metrics as $metric)
                                <tr class="border-t border-[#f2f3f6]">
                                    <td class="py-2.5 text-[#5c6266]">{{ \Illuminate\Support\Carbon::parse($metric->metric_date)->translatedFormat('d M Y') }}</td>
                                    <td class="py-2.5 text-[#5c6266]">{{ $metric->platform->name ?? '-' }}</td>
                                    <td class="py-2.5 font-medium text-[#14181a]">{{ number_format($metric->views) }}</td>
                                    <td class="py-2.5">
                                        <span class="text-xs px-2 py-1 rounded-full bg-[#f0f5f4] text-[#044b46]">{{ $metric->engagement_rate }}%</span>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                @endif
            </div>
        </div>

        <div class="space-y-5">
            <div class="card p-6">
                <h3 class="text-sm font-semibold text-[#14181a] mb-4">Content Info</h3>
                <div class="space-y-3 text-sm">
                    <div class="flex items-center justify-between">
                        <span class="text-[#9aa0a4]">Client</span>
                        <span class="font-medium text-[#14181a]">{{ $contentItem->client->name ?? '-' }}</span>
                    </div>
                    <div class="flex items-center justify-between">
                        <span class="text-[#9aa0a4]">Platform</span>
                        <span class="font-medium text-[#14181a]">{{ $contentItem->platform->name ?? '-' }}</span>
                    </div>
                    <div class="flex items-center justify-between">
                        <span class="text-[#9aa0a4]">Tipe Konten</span>
                        <span class="font-medium text-[#14181a]">{{ $contentItem->contentType->name ?? '-' }}</span>
                    </div>
                    <div class="flex items-center justify-between">
                        <span class="text-[#9aa0a4]">Deadline</span>
                        <span class="font-medium text-[#14181a]">{{ $contentItem->deadline_at?->translatedFormat('d M Y') }}</span>
                    </div>
                    @if ($bestDate)
                        <div class="flex items-center justify-between">
                            <span class="text-[#9aa0a4]">Hari Terbaik</span>
                            <span class="font-medium text-[#14181a]">{{ \Illuminate\Support\Carbon::parse($bestDate->metric_date)->translatedFormat('d M Y') }}</span>
                        </div>
                    @endif
                </div>
            </div>

            <div class="card p-6">
                <h3 class="text-sm font-semibold text-[#14181a] mb-4">Sync Log ({{ $syncLogs->count() }})</h3>
                @if ($syncLogs->isEmpty())
                    <p class="text-xs text-[#9aa0a4] italic">Belum ada riwayat import/sinkronisasi.</p>
                @else
                    <div class="space-y-2.5">
                        @foreach ($syncLogs as $log)
                            <div class="border border-[#eef0f4] rounded-lg p-3 {{ $log->status === 'failed' ? 'bg-[#fdf2f1]' : 'bg-[#f7f8fc]' }}">
                                <div class="flex items-center justify-between mb-1">
                                    <p class="text-xs font-medium text-[#14181a]">{{ strtoupper($log->source_type) }} &middot; {{ $log->importedBy->name ?? '-' }}</p>
                                    <span class="text-[10px] px-2 py-0.5 rounded-full
                                        {{ $log->status === 'success' ? 'bg-[#f0f5f4] text-[#0f7a5f]' : '' }}
                                        {{ $log->status === 'failed' ? 'bg-[#fbe2e0] text-[#b3423e]' : '' }}
                                        {{ $log->status === 'pending' ? 'bg-[#fdf6ec] text-[#b8873a]' : '' }}">
                                        {{ $log->status }}
                                    </span>
                                </div>
                                <p class="text-xs text-[#9aa0a4]">{{ $log->created_at->translatedFormat('d M Y, H:i') }}</p>
                            </div>
                        @endforeach
                    </div>
                @endif
            </div>
        </div>
    </div>
</div>

@endsection