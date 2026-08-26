@extends('layouts.app')
@section('title', 'Dashboard')
@section('content')

    <div class="p-4 sm:p-6 lg:p-8 max-w-[1400px] mx-auto">

        <div class="mb-7">
            <h1 class="font-display text-[26px] sm:text-[32px] font-semibold text-[var(--text-primary)]">Dashboard</h1>
            <p class="text-[var(--text-secondary)] text-sm mt-1">Ringkasan eksekutif aktivitas tim dan klien.</p>
        </div>

        <div class="flex flex-col lg:flex-row gap-5 items-stretch lg:items-start">

            <div class="flex-1 min-w-0 space-y-5">

                {{-- Stat cards --}}
                @php
                    $statStyles = [
                        ['chip' => 'bg-[var(--brand-tint)]', 'icon' => 'text-[var(--brand)]'],
                        ['chip' => 'bg-[var(--info-tint)]', 'icon' => 'text-[var(--info-text)]'],
                        ['chip' => 'bg-[var(--info-tint-alt)]', 'icon' => 'text-[var(--info-strong)]'],
                        ['chip' => 'bg-[var(--danger-tint)]', 'icon' => 'text-[var(--danger-text)]'],
                    ];
                @endphp
                <div class="grid grid-cols-2 sm:grid-cols-3 gap-3 sm:gap-4">
                    @foreach ($stats as $stat)
                        @php $c = $statStyles[$loop->index % 4]; @endphp
                        <a href="{{ $stat['link'] }}" class="card p-3.5 sm:p-5 hover:shadow-md transition-shadow">
                            <div class="flex items-center gap-3 mb-4">
                                <div class="w-9 h-9 rounded-lg {{ $c['chip'] }} flex items-center justify-center">
                                    <span
                                        class="material-symbols-outlined {{ $c['icon'] }} text-[18px]">{{ $stat['icon'] }}</span>
                                </div>
                                <span class="text-sm text-[var(--text-secondary)]">{{ $stat['label'] }}</span>
                            </div>

                            <p class="font-display text-[28px] font-semibold text-[var(--text-primary)] mb-2">{{ $stat['value'] }}</p>

                            <p class="text-xs font-medium flex items-center gap-1
                                    {{ $stat['trend'] === 'up' ? 'text-[var(--success-text)]' : '' }}
                                    {{ $stat['trend'] === 'down' ? 'text-[var(--danger-text)]' : '' }}
                                    {{ $stat['trend'] === 'flat' ? 'text-[var(--text-muted)]' : '' }}">
                                @if ($stat['trend'] === 'up')
                                    <span class="material-symbols-outlined text-[13px]">trending_up</span>
                                @elseif ($stat['trend'] === 'down')
                                    <span class="material-symbols-outlined text-[13px]">trending_down</span>
                                @else
                                    <span>&mdash;</span>
                                @endif
                                {{ $stat['change'] }}
                            </p>
                        </a>
                    @endforeach
                </div>

                {{-- Performance chart --}}
                <div class="card p-6">
                    <h2 class="font-display text-lg font-semibold text-[var(--text-primary)] mb-1">Performa Konten</h2>
                    <p class="text-xs text-[var(--text-muted)] mb-6">Jumlah konten berdasarkan deadline, 7 bulan terakhir</p>

                    @php
                        $max = max(collect($performance)->max('value'), 1);
                        $peak = collect($performance)->sortByDesc('value')->keys()->first();
                    @endphp

                    <div class="flex items-end justify-between gap-4 h-48">
                        @foreach ($performance as $i => $bar)
                            <div class="flex-1 flex flex-col items-center gap-2.5">
                                <span class="text-xs font-medium text-[var(--text-muted)]">{{ $bar['value'] }}</span>
                                <div class="w-full max-w-12 rounded-t-[3px] transition-all duration-300 {{ $i === $peak && $bar['value'] > 0 ? 'bg-[var(--brand)]' : 'bg-[var(--brand-tint-border)]' }}"
                                    style="height: {{ max(($bar['value'] / $max) * 100, 3) }}%"></div>
                                <span class="text-xs text-[var(--text-muted)]">{{ $bar['label'] }}</span>
                            </div>
                        @endforeach
                    </div>
                </div>

                {{-- Views trend (domain PIC 3) --}}
                <div id="tren-views" class="card p-6 scroll-mt-6">
                    <div class="flex items-center justify-between mb-1">
                        <h2 class="font-display text-lg font-semibold text-[var(--text-primary)]">Tren Views</h2>
                        <form method="GET">
                            <select name="period" onchange="this.form.submit()"
                                class="text-sm border border-[var(--border)] rounded-lg px-3 py-1.5 bg-[var(--surface-card)] focus:outline-none focus:ring-2 focus:ring-[#044b46]/15 focus:border-[#044b46]/40 transition-shadow">
                                <option value="7" {{ $period === 7 ? 'selected' : '' }}>7 Hari</option>
                                <option value="30" {{ $period === 30 ? 'selected' : '' }}>30 Hari</option>
                                <option value="90" {{ $period === 90 ? 'selected' : '' }}>90 Hari</option>
                            </select>
                        </form>
                    </div>
                    <p class="text-xs text-[var(--text-muted)] mb-5">Total views seluruh konten, {{ $period }} hari terakhir.</p>
                    <x-trend-chart :trend="$viewsTrend" />
                </div>

                {{-- Recent projects --}}
                <div class="card p-6">
                    <div class="flex items-center justify-between mb-4">
                        <h2 class="font-display text-lg font-semibold text-[var(--text-primary)]">Proyek Terbaru</h2>
                        <a href="{{ Route::has('production-workflow.index') ? route('production-workflow.index') : '#' }}"
                            class="text-sm font-medium text-[var(--brand)] hover:underline">Lihat semua</a>
                    </div>

                    @if ($recentItems->isEmpty())
                        <p class="text-sm text-[var(--text-muted)] py-6 text-center">Belum ada konten yang tercatat.</p>
                    @else
                        <div class="overflow-x-auto hidden sm:block">
                            <table class="w-full table-fixed text-sm text-left">
                                <thead class="bg-[var(--surface-page)]">
                                    <tr class="text-[var(--text-muted)] text-[11px] uppercase tracking-wide">
                                        <th class="w-[34%] px-6 py-3 font-medium whitespace-nowrap">Judul</th>
                                        <th class="w-[20%] px-4 py-3 font-medium whitespace-nowrap">Klien</th>
                                        <th class="w-[14%] px-4 py-3 font-medium whitespace-nowrap">Tipe</th>
                                        <th class="w-[16%] px-4 py-3 font-medium whitespace-nowrap">Deadline</th>
                                        <th class="w-[16%] px-4 py-3 font-medium whitespace-nowrap">Status</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach ($recentItems as $item)
                                        <tr class="border-t border-[var(--surface-muted)]">
                                            <td class="px-6 py-3.5 font-medium text-[var(--text-primary)] truncate" title="{{ $item['title'] }}">{{ $item['title'] }}</td>
                                            <td class="px-4 py-3.5 text-[var(--text-secondary)] truncate">{{ $item['client'] }}</td>
                                            <td class="px-4 py-3.5 text-[var(--text-secondary)] truncate">{{ $item['type'] }}</td>
                                            <td class="px-4 py-3.5 text-[var(--text-secondary)] whitespace-nowrap">
                                                {{ $item['deadline'] ? $item['deadline']->translatedFormat('d M Y') : '-' }}</td>
                                            <td class="px-4 py-3.5">
                                                <span
                                                    class="badge {{ $item['is_overdue'] ? 'badge-danger' : 'badge-success' }}">{{ $item['status'] }}</span>
                                            </td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>

                        {{-- Mobile accordion list --}}
                        <div class="sm:hidden divide-y divide-[var(--surface-muted)]">
                            @foreach ($recentItems as $item)
                                <div x-data="{ open: false }">
                                    <button type="button" class="w-full text-left py-3 flex items-center justify-between gap-3 cursor-pointer" @click="open = !open" :aria-expanded="open">
                                        <p class="text-sm font-medium text-[var(--text-primary)] truncate">{{ $item['title'] }}</p>
                                        <div class="flex items-center gap-2 shrink-0">
                                            <span class="badge {{ $item['is_overdue'] ? 'badge-danger' : 'badge-success' }}">{{ $item['status'] }}</span>
                                            <span class="material-symbols-outlined text-[var(--text-muted)] text-[18px] transition-transform" :class="open ? 'rotate-180' : ''">expand_more</span>
                                        </div>
                                    </button>
                                    <div x-show="open" x-cloak x-transition class="pb-3 -mt-1 space-y-2 text-sm">
                                        <div class="flex justify-between gap-3">
                                            <span class="text-[var(--text-muted)]">Klien</span>
                                            <span class="text-[var(--text-primary)] text-right">{{ $item['client'] }}</span>
                                        </div>
                                        <div class="flex justify-between gap-3">
                                            <span class="text-[var(--text-muted)]">Tipe</span>
                                            <span class="text-[var(--text-primary)] text-right">{{ $item['type'] }}</span>
                                        </div>
                                        <div class="flex justify-between gap-3">
                                            <span class="text-[var(--text-muted)]">Deadline</span>
                                            <span class="text-[var(--text-primary)] text-right">{{ $item['deadline'] ? $item['deadline']->translatedFormat('d M Y') : '-' }}</span>
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
                        <h2 class="font-display text-lg font-semibold text-[var(--text-primary)]">Konten Berperforma Terbaik</h2>
                        <a href="{{ Route::has('analytics') ? route('analytics') : '#' }}"
                            class="text-sm font-medium text-[var(--brand)] hover:underline">Lihat Performa</a>
                    </div>
                    <p class="text-xs text-[var(--text-muted)] mb-4">Konten dengan views tertinggi bulan ini, lintas semua client.</p>

                    @if ($topContent->isEmpty())
                        <p class="text-sm text-[var(--text-muted)] py-6 text-center">Belum ada data performa konten bulan ini.</p>
                    @else
                        <div class="overflow-x-auto hidden sm:block">
                            <table class="w-full table-fixed text-sm text-left">
                                <thead class="bg-[var(--surface-page)]">
                                    <tr class="text-[var(--text-muted)] text-[11px] uppercase tracking-wide">
                                        <th class="w-[34%] px-6 py-3 font-medium whitespace-nowrap">Judul</th>
                                        <th class="w-[20%] px-4 py-3 font-medium whitespace-nowrap">Klien</th>
                                        <th class="w-[16%] px-4 py-3 font-medium whitespace-nowrap">Platform</th>
                                        <th class="w-[15%] px-4 py-3 font-medium text-right whitespace-nowrap">Views</th>
                                        <th class="w-[15%] px-4 py-3 font-medium text-right whitespace-nowrap">Engagement</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach ($topContent as $item)
                                        <tr class="border-t border-[var(--surface-muted)]">
                                            <td class="px-6 py-3.5 font-medium text-[var(--text-primary)] truncate" title="{{ $item['title'] }}">{{ $item['title'] }}</td>
                                            <td class="px-4 py-3.5 text-[var(--text-secondary)] truncate">{{ $item['client'] }}</td>
                                            <td class="px-4 py-3.5 text-[var(--text-secondary)] truncate">{{ $item['platform'] }}</td>
                                            <td class="px-4 py-3.5 text-right text-[var(--text-secondary)] [font-variant-numeric:tabular-nums]">{{ number_format($item['views']) }}</td>
                                            <td class="px-4 py-3.5 text-right text-[var(--text-secondary)] [font-variant-numeric:tabular-nums]">{{ $item['engagement_rate'] }}%</td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>

                        {{-- Mobile accordion list --}}
                        <div class="sm:hidden divide-y divide-[var(--surface-muted)]">
                            @foreach ($topContent as $item)
                                <div x-data="{ open: false }">
                                    <button type="button" class="w-full text-left py-3 flex items-center justify-between gap-3 cursor-pointer" @click="open = !open" :aria-expanded="open">
                                        <p class="text-sm font-medium text-[var(--text-primary)] truncate">{{ $item['title'] }}</p>
                                        <div class="flex items-center gap-2 shrink-0">
                                            <span class="flex items-center gap-1 text-xs font-medium text-[var(--text-secondary)] whitespace-nowrap">
                                                <span class="material-symbols-outlined text-[14px] text-[var(--text-muted)]">visibility</span>
                                                {{ number_format($item['views']) }}
                                            </span>
                                            <span class="material-symbols-outlined text-[var(--text-muted)] text-[18px] transition-transform" :class="open ? 'rotate-180' : ''">expand_more</span>
                                        </div>
                                    </button>
                                    <div x-show="open" x-cloak x-transition class="pb-3 -mt-1 space-y-2 text-sm">
                                        <div class="flex justify-between gap-3">
                                            <span class="text-[var(--text-muted)]">Klien</span>
                                            <span class="text-[var(--text-primary)] text-right">{{ $item['client'] }}</span>
                                        </div>
                                        <div class="flex justify-between gap-3">
                                            <span class="text-[var(--text-muted)]">Platform</span>
                                            <span class="text-[var(--text-primary)] text-right">{{ $item['platform'] }}</span>
                                        </div>
                                        <div class="flex justify-between gap-3">
                                            <span class="text-[var(--text-muted)]">Engagement</span>
                                            <span class="text-[var(--text-primary)] text-right">{{ $item['engagement_rate'] }}%</span>
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
                        <h2 class="font-display text-lg font-semibold text-[var(--text-primary)]">Klien Teratas</h2>
                        <a href="{{ Route::has('client-management.index') ? route('client-management.index') : '#' }}"
                            class="text-sm font-medium text-[var(--brand)] hover:underline">Lihat semua klien</a>
                    </div>
                    <p class="text-xs text-[var(--text-muted)] mb-4">Klien dengan performa views tertinggi bulan ini.</p>

                    @if ($topClients->isEmpty())
                        <p class="text-sm text-[var(--text-muted)] py-6 text-center">Belum ada data performa client bulan ini.</p>
                    @else
                        <div class="overflow-x-auto hidden sm:block">
                            <table class="w-full table-fixed text-sm text-left">
                                <thead class="bg-[var(--surface-page)]">
                                    <tr class="text-[var(--text-muted)] text-[11px] uppercase tracking-wide">
                                        <th class="w-[40%] px-6 py-3 font-medium whitespace-nowrap">Klien</th>
                                        <th class="w-[20%] px-4 py-3 font-medium text-right whitespace-nowrap">Views</th>
                                        <th class="w-[20%] px-4 py-3 font-medium text-right whitespace-nowrap">Engagement</th>
                                        <th class="w-[20%] px-4 py-3 font-medium text-right whitespace-nowrap">Konten</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach ($topClients as $client)
                                        <tr class="border-t border-[var(--surface-muted)]">
                                            <td class="px-6 py-3.5 font-medium text-[var(--text-primary)] truncate" title="{{ $client['name'] }}">{{ $client['name'] }}</td>
                                            <td class="px-4 py-3.5 text-right text-[var(--text-secondary)] [font-variant-numeric:tabular-nums]">{{ number_format($client['views']) }}</td>
                                            <td class="px-4 py-3.5 text-right text-[var(--text-secondary)] [font-variant-numeric:tabular-nums]">{{ $client['engagement_rate'] }}%</td>
                                            <td class="px-4 py-3.5 text-right text-[var(--text-secondary)] [font-variant-numeric:tabular-nums]">{{ $client['content_count'] }}</td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>

                        {{-- Mobile accordion list --}}
                        <div class="sm:hidden divide-y divide-[var(--surface-muted)]">
                            @foreach ($topClients as $client)
                                <div x-data="{ open: false }">
                                    <button type="button" class="w-full text-left py-3 flex items-center justify-between gap-3 cursor-pointer" @click="open = !open" :aria-expanded="open">
                                        <p class="text-sm font-medium text-[var(--text-primary)] truncate">{{ $client['name'] }}</p>
                                        <div class="flex items-center gap-2 shrink-0">
                                            <span class="flex items-center gap-1 text-xs font-medium text-[var(--text-secondary)] whitespace-nowrap">
                                                <span class="material-symbols-outlined text-[14px] text-[var(--text-muted)]">visibility</span>
                                                {{ number_format($client['views']) }}
                                            </span>
                                            <span class="material-symbols-outlined text-[var(--text-muted)] text-[18px] transition-transform" :class="open ? 'rotate-180' : ''">expand_more</span>
                                        </div>
                                    </button>
                                    <div x-show="open" x-cloak x-transition class="pb-3 -mt-1 space-y-2 text-sm">
                                        <div class="flex justify-between gap-3">
                                            <span class="text-[var(--text-muted)]">Engagement</span>
                                            <span class="text-[var(--text-primary)] text-right">{{ $client['engagement_rate'] }}%</span>
                                        </div>
                                        <div class="flex justify-between gap-3">
                                            <span class="text-[var(--text-muted)]">Jumlah Konten</span>
                                            <span class="text-[var(--text-primary)] text-right">{{ $client['content_count'] }}</span>
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
                        <h2 class="font-display text-base font-semibold text-[var(--text-primary)]">Insight AI</h2>
                        <span class="material-symbols-outlined text-[var(--brand)] text-[18px]">auto_awesome</span>
                    </div>
                    <div class="space-y-3">
                        @forelse ($insights as $insight)
                            <div class="bg-[var(--surface-page)] rounded-lg p-3.5">
                                <p class="text-sm font-medium text-[var(--text-primary)] flex gap-2">
                                    <span class="w-1.5 h-1.5 rounded-full bg-[var(--brand)] mt-1.5 shrink-0"></span>
                                    {{ $insight['title'] }}
                                </p>
                                <p class="text-xs text-[var(--text-muted)] mt-1 pl-3.5">{{ $insight['description'] }}</p>
                            </div>
                        @empty
                            <p class="text-sm text-[var(--text-muted)] text-center py-4">Belum cukup data untuk insight.</p>
                        @endforelse
                    </div>
                </div>

                <div class="card p-6 flex flex-col">
                    <div class="flex items-center justify-between mb-4">
                        <h2 class="font-display text-base font-semibold text-[var(--text-primary)]">Perlu Perhatian</h2>
                        <span class="material-symbols-outlined text-[var(--danger-text)] text-[18px]">priority_high</span>
                    </div>

                    <div class="space-y-3 flex-1">
                        @forelse ($attentionItems as $item)
                            <div class="bg-[var(--surface-page)] rounded-lg p-3.5">
                                <p class="text-sm font-medium text-[var(--text-primary)] flex gap-2">
                                    <span class="w-1.5 h-1.5 rounded-full bg-[var(--danger-text)] mt-1.5 shrink-0"></span>
                                    {{ $item['title'] }}
                                </p>
                                <p class="text-xs text-[var(--text-muted)] mt-1 pl-3.5">{{ $item['client'] }} &middot; Penanggung Jawab:
                                    {{ $item['pic'] }} &middot; {{ $item['status'] }}</p>
                            </div>
                        @empty
                            <div class="text-center py-8">
                                <span class="material-symbols-outlined text-[var(--success-text)] text-[28px]">check_circle</span>
                                <p class="text-sm text-[var(--text-muted)] mt-2">Tidak ada item overdue. Semua on track.</p>
                            </div>
                        @endforelse
                    </div>

                    <a href="{{ Route::has('production-workflow.index') ? route('production-workflow.index') : '#' }}"
                        class="mt-4 w-full bg-[var(--surface-page)] text-[var(--brand)] text-sm font-medium py-2.5 rounded-lg hover:bg-[var(--brand-tint)] transition-colors flex items-center justify-center gap-1.5">
                        Buka Production Workflow
                        <span class="material-symbols-outlined text-[15px]">arrow_forward</span>
                    </a>
                </div>

                <div class="card p-6 flex flex-col">
                    <div class="flex items-center justify-between mb-1">
                        <h2 class="font-display text-base font-semibold text-[var(--text-primary)]">Risiko Tinggi (Prediksi AI)</h2>
                        <span class="material-symbols-outlined text-[var(--danger-text)] text-[18px]">report</span>
                    </div>
                    <p class="text-xs text-[var(--text-muted)] mb-4">Belum overdue, tapi diprediksi berisiko terlambat — cegah sebelum
                        kejadian.</p>

                    <div class="space-y-3 flex-1">
                        @forelse ($highRiskItems as $item)
                            <a href="{{ route('content-items.show', $item['id']) }}"
                                class="block bg-[var(--surface-page)] rounded-lg p-3.5 hover:bg-[var(--danger-tint)] transition-colors">
                                <div class="flex items-center justify-between gap-2">
                                    <p class="text-sm font-medium text-[var(--text-primary)] flex gap-2 min-w-0">
                                        <span class="w-1.5 h-1.5 rounded-full bg-[var(--danger-text)] mt-1.5 shrink-0"></span>
                                        <span class="truncate">{{ $item['title'] }}</span>
                                    </p>
                                    <span
                                        class="text-xs font-semibold text-[var(--danger-text)] shrink-0">{{ $item['risk_score'] }}%</span>
                                </div>
                                <p class="text-xs text-[var(--text-muted)] mt-1 pl-3.5">{{ $item['client'] }} &middot; Penanggung Jawab:
                                    {{ $item['pic'] }}</p>
                                <p class="text-xs text-[var(--text-muted)] pl-3.5">{{ $item['top_factor'] }}</p>
                            </a>
                        @empty
                            <div class="text-center py-8">
                                <span class="material-symbols-outlined text-[var(--success-text)] text-[28px]">verified</span>
                                <p class="text-sm text-[var(--text-muted)] mt-2">Tidak ada item risiko tinggi saat ini.</p>
                            </div>
                        @endforelse
                    </div>
                </div>

                {{-- Akurasi prediksi AI Delay Risk (teaser Team Performance) --}}
                <div class="card p-6 flex flex-col">
                    <div class="flex items-center gap-3 mb-1">
                        <div class="w-9 h-9 rounded-lg bg-[var(--info-tint)] flex items-center justify-center shrink-0">
                            <span class="material-symbols-outlined text-[var(--info-text)] text-[18px]">verified</span>
                        </div>
                        <h2 class="font-display text-base font-semibold text-[var(--text-primary)]">Akurasi Prediksi AI</h2>
                    </div>

                    @if ($riskAccuracy['total_evaluated'] === 0)
                        <p class="text-sm text-[var(--text-muted)] mt-3">Belum ada cukup data (butuh konten yang sudah upload dan pernah
                            dapat skor risiko).</p>
                    @else
                        @if ($riskAccuracy['high_risk_precision'] !== null)
                            <div class="flex items-baseline gap-2 mt-3">
                                <p class="font-display text-2xl font-semibold text-[var(--text-primary)]">
                                    {{ $riskAccuracy['high_risk_precision'] }}%</p>
                                <p class="text-xs text-[var(--text-secondary)]">prediksi <strong>Risiko Tinggi</strong> benar-benar terlambat</p>
                            </div>
                        @else
                            <p class="text-sm text-[var(--text-muted)] mt-3">Belum ada konten dengan prediksi Risiko Tinggi yang sudah selesai
                                upload.</p>
                        @endif
                    @endif

                    <a href="{{ route('team-performance.index') }}"
                        class="mt-4 w-full bg-[var(--surface-page)] text-[var(--brand)] text-sm font-medium py-2.5 rounded-lg hover:bg-[var(--brand-tint)] transition-colors flex items-center justify-center gap-1.5">
                        Lihat detail lengkap
                        <span class="material-symbols-outlined text-[15px]">arrow_forward</span>
                    </a>
                </div>

            </div>

        </div>
    </div>

@endsection