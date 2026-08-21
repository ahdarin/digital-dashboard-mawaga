@extends('layouts.client')
@section('title', 'Analytics')
@section('content')

    <div class="p-4 sm:p-5 space-y-4">

        <div class="flex items-center gap-1.5">
            @foreach ([7 => '7 Hari', 30 => '30 Hari', 90 => '90 Hari'] as $value => $label)
                <a href="{{ route('client.portal.analytics', ['token' => $portalToken, 'period' => $value]) }}"
                   class="text-xs font-medium px-3 py-1.5 rounded-full transition-colors
                       {{ $period === $value ? 'bg-[var(--brand-solid)] text-white' : 'bg-[var(--surface-card)] border border-[var(--border)] text-[var(--text-secondary)] hover:bg-[var(--surface-page)]' }}">
                    {{ $label }}
                </a>
            @endforeach
        </div>

        <div class="grid grid-cols-3 gap-2 sm:gap-3">
            @foreach ($stats as $stat)
                @continue($stat['label'] === 'Platforms Tracked')
                <div class="bg-[var(--surface-card)] rounded-2xl border border-[var(--border)] p-2.5 sm:p-4 shadow-[0_1px_2px_rgba(20,24,26,0.03)]">
                    <div class="w-7 h-7 sm:w-10 sm:h-10 rounded-lg bg-[var(--brand-tint)] flex items-center justify-center shrink-0 mb-2">
                        <span class="material-symbols-outlined text-[var(--brand)] text-[15px] sm:text-[19px]">{{ $stat['icon'] }}</span>
                    </div>
                    <p class="text-[10px] sm:text-xs text-[var(--text-muted)] mb-1 leading-tight">{{ $stat['label'] }}</p>
                    <p class="font-display text-base sm:text-2xl font-semibold text-[var(--text-primary)] leading-tight">{{ $stat['value'] }}</p>
                    <p class="text-[10px] sm:text-[11px] mt-1
                        {{ $stat['trend'] === 'up' ? 'text-[var(--success-text)]' : ($stat['trend'] === 'down' ? 'text-[var(--danger-text)]' : 'text-[var(--text-muted)]') }}">
                        {{ $stat['change'] }}
                    </p>
                </div>
            @endforeach
        </div>

        <div class="bg-[var(--surface-card)] rounded-2xl border border-[var(--border)] p-4 shadow-[0_1px_2px_rgba(20,24,26,0.03)]">
            <p class="text-sm font-semibold text-[var(--text-primary)] mb-3">Views Trend</p>
            <x-trend-chart :trend="$trend" />
        </div>

        <div class="bg-[var(--surface-card)] rounded-2xl border border-[var(--border)] p-4 shadow-[0_1px_2px_rgba(20,24,26,0.03)]">
            <p class="text-sm font-semibold text-[var(--text-primary)] mb-3">Traffic per Platform</p>
            @if ($platformBreakdown->isEmpty())
                <p class="text-sm text-[var(--text-muted)] text-center py-6">Belum ada data pada periode ini.</p>
            @else
                <div class="space-y-2.5">
                    @php $maxPlatformValue = max($platformBreakdown->max('value'), 1); @endphp
                    @foreach ($platformBreakdown as $row)
                        <div>
                            <div class="flex items-center justify-between text-xs mb-1">
                                <span class="font-medium text-[var(--text-primary)]">{{ $row['label'] }}</span>
                                <span class="text-[var(--text-muted)]">{{ number_format($row['value']) }} views</span>
                            </div>
                            <div class="h-1.5 rounded-full bg-[var(--surface-muted-2)] overflow-hidden">
                                <div class="h-full bg-[var(--brand)] rounded-full" style="width: {{ max(($row['value'] / $maxPlatformValue) * 100, 3) }}%"></div>
                            </div>
                        </div>
                    @endforeach
                </div>
            @endif
        </div>

        <div class="bg-[var(--surface-card)] rounded-2xl border border-[var(--border)] p-4 shadow-[0_1px_2px_rgba(20,24,26,0.03)]">
            <p class="text-sm font-semibold text-[var(--text-primary)] mb-3">Top Performing Content</p>
            @if ($topContent->isEmpty())
                <p class="text-sm text-[var(--text-muted)] text-center py-6">Belum ada data pada periode ini.</p>
            @else
                <div class="space-y-3">
                    @foreach ($topContent as $content)
                        <div class="flex items-center justify-between gap-3 {{ !$loop->last ? 'pb-3 border-b border-[var(--border)]' : '' }}">
                            <div class="min-w-0">
                                <p class="text-sm font-medium text-[var(--text-primary)] truncate">{{ $content['title'] }}</p>
                                <p class="text-xs text-[var(--text-muted)] mt-0.5">{{ $content['type'] }} &middot; {{ $content['platform'] }}</p>
                            </div>
                            <div class="text-right shrink-0">
                                <p class="text-sm font-semibold text-[var(--text-primary)]">{{ number_format($content['views']) }}</p>
                                <p class="text-xs text-[var(--text-muted)]">{{ $content['engagement_rate'] }}% eng.</p>
                            </div>
                        </div>
                    @endforeach
                </div>
            @endif
        </div>

    </div>
@endsection
