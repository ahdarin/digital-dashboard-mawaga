@extends('layouts.app')
@section('title', $contentItem->title . ' — Performance')
@section('content')

<div class="p-4 sm:p-6 lg:p-8 max-w-6xl mx-auto">

    <div class="flex flex-wrap items-center gap-2 text-xs text-[var(--text-muted)] mb-3">
        <a href="{{ route('analytics') }}" class="hover:text-[var(--brand)] font-medium focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[var(--brand)] rounded">Performa</a>
        <span class="material-symbols-outlined text-[13px]">chevron_right</span>
        <span>{{ $contentItem->client->name ?? '-' }}</span>
        <span class="material-symbols-outlined text-[13px]">chevron_right</span>
        <span class="text-[var(--text-secondary)] font-medium">{{ $contentItem->title }}</span>
    </div>

    <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-3 mb-6">
        <div class="flex items-center gap-3">
            <a href="{{ route('analytics') }}" class="text-[var(--text-muted)] hover:text-[var(--text-secondary)] transition-colors focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[var(--brand)] rounded">
                <span class="material-symbols-outlined">arrow_back</span>
            </a>
            <div>
                <h1 class="font-display text-[26px] font-semibold text-[var(--text-primary)] tracking-tight">{{ $contentItem->title }}</h1>
                <p class="text-[var(--text-secondary)] mt-0.5 text-sm">{{ $contentItem->contentType->name ?? '-' }} &middot; {{ $contentItem->platform->name ?? '-' }}</p>
            </div>
        </div>

        <span class="badge {{ $contentItem->is_posted ? 'badge-success' : 'badge-warning' }}">
            {{ $contentItem->is_posted ? 'Published' : 'Belum Terpublikasi' }}
        </span>
    </div>

    @if ($hasPeerComparison && $viewsVsPeerPct !== null)
        <div class="card p-4 mb-6 flex items-center gap-3.5 {{ $viewsVsPeerPct < 0 ? 'border-[var(--danger-border)]' : '' }}">
            <div class="w-9 h-9 rounded-lg flex items-center justify-center shrink-0 {{ $viewsVsPeerPct >= 0 ? 'bg-[var(--brand-tint)]' : 'bg-[var(--danger-tint)]' }}">
                <span class="material-symbols-outlined text-[18px] {{ $viewsVsPeerPct >= 0 ? 'text-[var(--brand)]' : 'text-[var(--danger-text)]' }}">
                    {{ $viewsVsPeerPct >= 0 ? 'trending_up' : 'trending_down' }}
                </span>
            </div>
            <div class="flex-1">
                <p class="text-sm font-medium text-[var(--text-primary)]">
                    @if ($viewsVsPeerPct >= 0)
                        {{ $viewsVsPeerPct }}% di atas rata-rata konten {{ $contentItem->client->name ?? 'client ini' }}
                    @else
                        {{ abs($viewsVsPeerPct) }}% di bawah rata-rata konten {{ $contentItem->client->name ?? 'client ini' }}
                    @endif
                </p>
                <p class="text-xs text-[var(--text-muted)] mt-0.5">
                    Views 30 hari terakhir vs rata-rata konten lain client ini ({{ number_format($peerAvgViews) }} views).
                    @if ($engagementVsPeerPct !== null)
                        Engagement {{ $engagementVsPeerPct >= 0 ? $engagementVsPeerPct.'% di atas' : abs($engagementVsPeerPct).'% di bawah' }} rata-rata juga.
                    @endif
                </p>
            </div>
        </div>
    @elseif (! $hasPeerComparison)
        <div class="card p-3.5 mb-6 flex items-center gap-2.5">
            <span class="material-symbols-outlined text-[var(--text-muted)] text-[18px]">info</span>
            <p class="text-xs text-[var(--text-muted)]">Belum bisa dibandingin sama konten lain - butuh data metrik konten lain milik client ini.</p>
        </div>
    @endif

    <div class="grid grid-cols-1 lg:grid-cols-3 gap-5">

        <div class="lg:col-span-2 space-y-5">

            {{-- SYSTEM CONSISTENCY PASS (Part AD) - "PERFORMA 30 Hari
                 Terakhir" (gain periode, delta) SEKARANG eksplisit
                 terpisah dari "Total Saat Ini" di bawah (total provider
                 kumulatif). Dulu delta ini SUDAH dihitung ($thisResult)
                 tapi cuma dipakai internal buat badge persentase peer
                 comparison di atas - angkanya sendiri tidak pernah
                 ditampilkan. Metric yang genuinely null (bukan 0) TETAP
                 tampil "-", tidak pernah difabrikasi jadi 0. --}}
            @if ($periodDeltaAvailable)
                <div class="card p-5">
                    <p class="text-sm font-semibold text-[var(--text-primary)] mb-4">Performa 30 Hari Terakhir</p>
                    <div class="grid grid-cols-2 sm:grid-cols-3 gap-4">
                        <div>
                            <p class="text-xs text-[var(--text-muted)] mb-1">Views bertambah</p>
                            <p class="text-base font-semibold text-[var(--text-primary)] [font-variant-numeric:tabular-nums]">
                                {{ $periodDelta['views'] !== null ? ($periodDelta['views'] >= 0 ? '+' : '').number_format($periodDelta['views']) : '-' }}
                            </p>
                        </div>
                        <div>
                            <p class="text-xs text-[var(--text-muted)] mb-1">Likes bertambah</p>
                            <p class="text-base font-semibold text-[var(--text-primary)] [font-variant-numeric:tabular-nums]">
                                {{ $periodDelta['likes'] !== null ? ($periodDelta['likes'] >= 0 ? '+' : '').number_format($periodDelta['likes']) : '-' }}
                            </p>
                        </div>
                        <div>
                            <p class="text-xs text-[var(--text-muted)] mb-1">Comments bertambah</p>
                            <p class="text-base font-semibold text-[var(--text-primary)] [font-variant-numeric:tabular-nums]">
                                {{ $periodDelta['comments'] !== null ? ($periodDelta['comments'] >= 0 ? '+' : '').number_format($periodDelta['comments']) : '-' }}
                            </p>
                        </div>
                        @if ($hasVideoMetrics)
                            <div>
                                <p class="text-xs text-[var(--text-muted)] mb-1">Shares bertambah</p>
                                <p class="text-base font-semibold text-[var(--text-primary)] [font-variant-numeric:tabular-nums]">
                                    {{ $periodDelta['shares'] !== null ? ($periodDelta['shares'] >= 0 ? '+' : '').number_format($periodDelta['shares']) : '-' }}
                                </p>
                            </div>
                            <div>
                                <p class="text-xs text-[var(--text-muted)] mb-1">Saves bertambah</p>
                                <p class="text-base font-semibold text-[var(--text-primary)] [font-variant-numeric:tabular-nums]">
                                    {{ $periodDelta['saves'] !== null ? ($periodDelta['saves'] >= 0 ? '+' : '').number_format($periodDelta['saves']) : '-' }}
                                </p>
                            </div>
                        @endif
                    </div>
                </div>
            @endif

            <div>
                <div class="flex items-center justify-between mb-3">
                    <p class="text-sm font-semibold text-[var(--text-primary)]">Total Saat Ini</p>
                    @if (\App\Services\FreshnessPresenter::label($currentObservedAt))
                        <p class="text-[11px] text-[var(--text-muted)]">{{ \App\Services\FreshnessPresenter::label($currentObservedAt) }}</p>
                    @endif
                </div>
                <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
                    <div class="card p-5 transition-shadow hover:shadow-[0_2px_10px_rgba(20,24,26,0.05)]">
                        <p class="text-xs text-[var(--text-muted)] mb-1.5">Total Views</p>
                        <p class="font-display text-xl font-semibold text-[var(--text-primary)] [font-variant-numeric:tabular-nums]">{{ number_format($totalViews) }}</p>
                    </div>
                    <div class="card p-5 transition-shadow hover:shadow-[0_2px_10px_rgba(20,24,26,0.05)]">
                        <p class="text-xs text-[var(--text-muted)] mb-1.5">Rata-rata Engagement</p>
                        <p class="font-display text-xl font-semibold text-[var(--text-primary)] [font-variant-numeric:tabular-nums]">{{ $avgEngagement }}%</p>
                    </div>
                    <div class="card p-5 transition-shadow hover:shadow-[0_2px_10px_rgba(20,24,26,0.05)]">
                        <p class="text-xs text-[var(--text-muted)] mb-1.5">Hari Terlacak</p>
                        <p class="font-display text-xl font-semibold text-[var(--text-primary)] [font-variant-numeric:tabular-nums]">{{ $daysTracked }}</p>
                    </div>
                </div>
            </div>

            @if ($hasVideoMetrics)
                <div class="grid grid-cols-2 sm:grid-cols-4 gap-4">
                    <div class="card p-5 bg-[var(--info-tint)] border-0">
                        <p class="text-xs text-[var(--text-muted)] mb-1.5">Rata-rata Watch Time</p>
                        <p class="text-lg font-semibold text-[var(--text-primary)] [font-variant-numeric:tabular-nums]">{{ $avgWatchTime !== null ? $avgWatchTime.'s' : '-' }}</p>
                    </div>
                    <div class="card p-5 bg-[var(--info-tint)] border-0">
                        <p class="text-xs text-[var(--text-muted)] mb-1.5">Tingkat Penyelesaian</p>
                        <p class="text-lg font-semibold text-[var(--text-primary)] [font-variant-numeric:tabular-nums]">{{ $avgCompletionRate !== null ? $avgCompletionRate.'%' : '-' }}</p>
                    </div>
                    <div class="card p-5 bg-[var(--info-tint)] border-0">
                        <p class="text-xs text-[var(--text-muted)] mb-1.5">Shares</p>
                        <p class="text-lg font-semibold text-[var(--text-primary)] [font-variant-numeric:tabular-nums]">{{ $totalShares !== null ? number_format($totalShares) : '-' }}</p>
                    </div>
                    <div class="card p-5 bg-[var(--info-tint)] border-0">
                        <p class="text-xs text-[var(--text-muted)] mb-1.5">Saves</p>
                        <p class="text-lg font-semibold text-[var(--text-primary)] [font-variant-numeric:tabular-nums]">{{ $totalSaves !== null ? number_format($totalSaves) : '-' }}</p>
                    </div>
                </div>
            @endif

            <div class="card p-6">
                <h2 class="text-sm font-semibold text-[var(--text-primary)] mb-5">Views Trend</h2>
                @if ($trend->isEmpty())
                    <p class="text-sm text-[var(--text-muted)] text-center py-16">Belum ada metrik yang tercatat.</p>
                @else
                    <x-trend-chart :trend="$trend" />
                @endif
            </div>

            <div class="card p-6">
                <h2 class="text-sm font-semibold text-[var(--text-primary)] mb-4">Metric History ({{ $metrics->count() }})</h2>
                @if ($metrics->isEmpty())
                    <p class="text-xs text-[var(--text-muted)] italic">Belum ada data metrik yang diimpor.</p>
                @else
                    <div class="overflow-x-auto">
                        <table class="w-full text-sm text-left">
                            <thead>
                                <tr class="text-[var(--text-muted)] text-[11px] uppercase tracking-wide">
                                    <th class="pb-2 font-medium">Tanggal</th>
                                    <th class="pb-2 font-medium">Platform</th>
                                    <th class="pb-2 font-medium">Views</th>
                                    <th class="pb-2 font-medium">Engagement</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($metrics as $metric)
                                    <tr class="border-t border-[var(--surface-muted)] hover:bg-[var(--surface-page)] transition-colors">
                                        <td class="py-2.5 text-[var(--text-secondary)] whitespace-nowrap [font-variant-numeric:tabular-nums]">{{ \Illuminate\Support\Carbon::parse($metric->metric_date)->translatedFormat('d M Y') }}</td>
                                        <td class="py-2.5 text-[var(--text-secondary)] whitespace-nowrap">{{ $metric->platform->name ?? '-' }}</td>
                                        <td class="py-2.5 font-medium text-[var(--text-primary)] whitespace-nowrap [font-variant-numeric:tabular-nums]">{{ number_format($metric->views) }}</td>
                                        <td class="py-2.5">
                                            <span class="badge badge-success [font-variant-numeric:tabular-nums]">{{ $metric->engagement_rate }}%</span>
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </div>
        </div>

        <div class="space-y-5">
            <div class="card p-6">
                <h2 class="text-sm font-semibold text-[var(--text-primary)] mb-4">Content Info</h2>
                <div class="space-y-3 text-sm">
                    <div class="flex items-center justify-between">
                        <span class="text-[var(--text-muted)]">Klien</span>
                        <span class="font-medium text-[var(--text-primary)]">{{ $contentItem->client->name ?? '-' }}</span>
                    </div>
                    <div class="flex items-center justify-between">
                        <span class="text-[var(--text-muted)]">Platform</span>
                        <span class="font-medium text-[var(--text-primary)]">{{ $contentItem->platform->name ?? '-' }}</span>
                    </div>
                    <div class="flex items-center justify-between">
                        <span class="text-[var(--text-muted)]">Jenis Produksi</span>
                        <span class="font-medium text-[var(--text-primary)]">{{ $contentItem->contentType->name ?? '-' }}</span>
                    </div>
                    @if ($contentItem->contentFormat || $contentItem->content_format)
                        <div class="flex items-center justify-between">
                            <span class="text-[var(--text-muted)]">Format Konten</span>
                            <span class="font-medium text-[var(--text-primary)]">{{ $contentItem->contentFormat->name ?? $contentItem->content_format }}</span>
                        </div>
                    @endif
                    <div class="flex items-center justify-between">
                        <span class="text-[var(--text-muted)]">Deadline</span>
                        <span class="font-medium text-[var(--text-primary)] [font-variant-numeric:tabular-nums]">{{ $contentItem->deadline_at?->translatedFormat('d M Y') }}</span>
                    </div>
                    @if ($bestDate)
                        <div class="flex items-center justify-between">
                            <span class="text-[var(--text-muted)]">Hari Terbaik</span>
                            <span class="font-medium text-[var(--text-primary)] [font-variant-numeric:tabular-nums]">{{ \Illuminate\Support\Carbon::parse($bestDate->metric_date)->translatedFormat('d M Y') }}</span>
                        </div>
                    @endif
                </div>
            </div>

            <div class="card p-6">
                <h2 class="text-sm font-semibold text-[var(--text-primary)] mb-4">Sync Log ({{ $syncLogs->count() }})</h2>
                @if ($syncLogs->isEmpty())
                    <p class="text-xs text-[var(--text-muted)] italic">Belum ada riwayat import/sinkronisasi.</p>
                @else
                    <div class="space-y-2.5">
                        @foreach ($syncLogs as $log)
                            <div class="border border-[var(--border)] rounded-lg p-3 {{ $log->status === 'failed' ? 'bg-[var(--danger-tint)]' : 'bg-[var(--surface-page)]' }}">
                                <div class="flex items-center justify-between mb-1">
                                    <p class="text-xs font-medium text-[var(--text-primary)]">{{ \Illuminate\Support\Str::headline($log->source_type) }} &middot; {{ $log->importedBy->name ?? '-' }}</p>
                                    <span class="badge
                                        {{ $log->status === 'success' ? 'badge-success' : '' }}
                                        {{ $log->status === 'failed' ? 'badge-danger' : '' }}
                                        {{ $log->status === 'pending' ? 'badge-warning' : '' }}">
                                        {{ $log->status }}
                                    </span>
                                </div>
                                <p class="text-xs text-[var(--text-muted)] [font-variant-numeric:tabular-nums]">{{ $log->created_at->translatedFormat('d M Y, H:i') }}</p>
                            </div>
                        @endforeach
                    </div>
                @endif
            </div>
        </div>
    </div>
</div>

@endsection
