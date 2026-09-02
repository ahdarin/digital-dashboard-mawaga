@extends('layouts.app')
@section('title', 'Performa')
@section('content')

<div class="p-4 sm:p-6 lg:p-8 max-w-[1400px] mx-auto">

    {{-- Header --}}
    <div class="flex flex-col sm:flex-row sm:items-start justify-between gap-3 mb-7">
        <div>
            <h1 class="font-display text-[26px] sm:text-[32px] font-semibold text-[var(--text-primary)]">Performa</h1>
            <p class="text-[var(--text-secondary)] text-sm mt-1">Analisis performa konten lintas client &amp; platform.</p>
        </div>

        @php
            // Phase 4.2 (Langkah 1) - UI HARUS cocok dengan authorization
            // server (POST /analytics/sync dijaga permission:settings,manage
            // sejak Phase 4.1). Ini BUKAN security boundary (403 server-side
            // tetap satu-satunya penjamin sungguhan) - ini cuma supaya user
            // yang memang tidak berwenang TIDAK disodori tombol yang bakal
            // 403 kalau diklik ("jangan mengandalkan 403 sebagai UX utama").
            $canSync = auth()->user()->hasPermissionTo('settings', 'manage');
            // Phase 4.4 (Langkah 3) - MIRROR $canSync, buat SEMUA mutation
            // control AI Strategy (Generate/Apply/Revert/Refine/chat send/
            // regenerate ide) & Audience CSV import (Langkah 3/4) - domain
            // BEDA dari Sync (Langkah 5, JANGAN dicampur), pakai permission
            // server yang SAMA PERSIS dengan route (analytics,manage,
            // Phase 4.2) - read-only bagian (Ringkasan/Ide list/riwayat
            // chat) TETAP tampil buat analytics,view-only, cuma
            // CONTROL-nya yang di-gate.
            $canManageAiStrategy = auth()->user()->hasPermissionTo('analytics', 'manage');
        @endphp
        {{-- Phase 4 - action pair "Sinkronkan Data" + "Ekspor" KONSISTEN di
             ketiga tab (Langkah 1), bukan tombol sync terpisah per tab.
             Sync mengikuti filter GLOBAL (client_id/platform_id) - period
             7/30/90 SENGAJA TIDAK dikirim ke endpoint sync (itu display
             filter, bukan sync mode - ingestion tetap pakai default
             lookback Phase 1). --}}
        <div class="flex flex-col items-end gap-1.5">
            <div class="flex items-center gap-2">
                @if ($canSync)
                    <button type="button" id="analytics-sync-button"
                            class="btn-secondary" {{ $selectedClientId ? '' : 'disabled' }}>
                        <span class="material-symbols-outlined text-[17px]" id="analytics-sync-icon">sync</span>
                        <span id="analytics-sync-button-label">Sinkronkan Data</span>
                    </button>
                @endif

                @if ($selectedClientId)
                    {{-- Ekspor TETAP tampil buat view-only (read-only action,
                         tidak butuh settings,manage - Langkah 1 "Export tetap
                         boleh jika memang read-only action"). Label eksplisit
                         "Ekspor Performa" (Phase 4.1 Langkah 6) - tombol ini
                         SELALU export data PERFORMA konten (lihat
                         AnalyticsController::export()), sekarang muncul di
                         ketiga tab termasuk Audiens, jadi label generik bisa
                         disalahartikan sebagai export data audiens (yang
                         TIDAK ada di sini). platform_id GLOBAL ikut dibawa. --}}
                    <a href="{{ route('analytics.export', array_filter(['client_id' => $selectedClientId, 'period' => $period, 'platform_id' => $selectedPlatformId ?? null])) }}"
                       class="btn-primary">
                        <span class="material-symbols-outlined text-[17px]">download</span> Ekspor Performa
                    </a>
                @endif
            </div>

            @if ($canSync)
                @if (! $selectedClientId)
                    <p class="text-[12px] text-[var(--text-muted)]">Pilih client untuk menyinkronkan data.</p>
                @else
                    <p class="text-[12px] text-[var(--text-secondary)]" id="analytics-sync-message" hidden></p>
                    {{-- Freshness (Langkah 14) - TERPISAH dari coverage
                         banner (itu soal "apakah periode ini lengkap", ini
                         soal "kapan data terakhir diperbarui") - JANGAN
                         dicampur. --}}
                    <p class="text-[11px] text-[var(--text-muted)]" id="analytics-freshness" hidden></p>
                    <div id="analytics-sync-subjobs" class="flex flex-col gap-0.5 items-end" hidden></div>
                @endif
            @endif
        </div>
    </div>

    {{-- Tab switcher - Analytics / Performance Table / Audience sekarang 1
         halaman yang sama, tab ganti konten di bawah, GLOBAL filter (client/
         period/platform) ikut kebawa pindah tab (reload halaman, bukan
         AJAX) - table-only params (search/content_type_id/sort/dir/page)
         SENGAJA TIDAK ikut, itu local ke tab Table doang (Phase 1 item 2). --}}
    @php
        $tabHref = fn (string $tab) => route('analytics', array_filter([
            'tab' => $tab,
            'client_id' => $selectedClientId,
            'period' => $period,
            'platform_id' => $selectedPlatformId ?? null,
        ]));
        $tabs = [
            ['key' => 'overview', 'label' => 'Analytics', 'icon' => 'monitoring'],
            ['key' => 'table', 'label' => 'Tabel Performa', 'icon' => 'table_rows'],
            ['key' => 'audience', 'label' => 'Audiens', 'icon' => 'groups'],
        ];
    @endphp
    <div class="flex items-center gap-1 bg-[var(--surface-muted)] rounded-lg p-1 mb-6 w-fit overflow-x-auto thin-autohide-scrollbar">
        @foreach ($tabs as $t)
            <a href="{{ $tabHref($t['key']) }}"
               class="text-sm font-medium px-4 py-2 rounded-md flex items-center gap-1.5 shrink-0 whitespace-nowrap transition-colors focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[var(--brand)]
                   {{ $activeTab === $t['key'] ? 'bg-[var(--surface-card)] text-[var(--text-primary)] shadow-sm' : 'text-[var(--text-muted)] hover:text-[var(--text-secondary)]' }}">
                <span class="material-symbols-outlined text-[17px]">{{ $t['icon'] }}</span> {{ $t['label'] }}
            </a>
        @endforeach
    </div>

    {{-- Filter bar GLOBAL - Client / Period / Platform, IDENTIK di ketiga
         tab (Phase 1 item 2/3 - dulu Period disembunyikan di tab Table &
         Platform cuma muncul di tab Audience, sekarang konsisten). --}}
    <form method="GET" class="card p-4 mb-6 flex items-center gap-3 flex-wrap">
        <input type="hidden" name="tab" value="{{ $activeTab }}">

        <select name="client_id" onchange="this.form.submit()"
                class="text-sm border border-[var(--border)] rounded-lg px-3.5 py-2 bg-[var(--surface-card)] focus:outline-none focus:ring-2 focus:ring-[#044b46]/15 focus:border-[#044b46]/40 transition-shadow">
            <option value="">Pilih Klien...</option>
            @foreach ($clientOptions as $clientOption)
                <option value="{{ $clientOption->id }}" {{ (string) $selectedClientId === (string) $clientOption->id ? 'selected' : '' }}>{{ $clientOption->name }}</option>
            @endforeach
        </select>

        <select name="period" onchange="this.form.submit()"
                class="text-sm border border-[var(--border)] rounded-lg px-3.5 py-2 bg-[var(--surface-card)] focus:outline-none focus:ring-2 focus:ring-[#044b46]/15 focus:border-[#044b46]/40 transition-shadow">
            <option value="7" {{ $period === 7 ? 'selected' : '' }}>7 Hari Terakhir</option>
            <option value="30" {{ $period === 30 ? 'selected' : '' }}>30 Hari Terakhir</option>
            <option value="90" {{ $period === 90 ? 'selected' : '' }}>90 Hari Terakhir</option>
        </select>

        {{-- Platform - SELALU tampil di ketiga tab biar layout nggak
             bergeser pindah tab, walau cuma 1/0 opsi (disabled kalau
             begitu) - lihat catatan "jangan bergeser" di audit. --}}
        <select name="platform_id" onchange="this.form.submit()" {{ $platformOptions->count() <= 1 ? 'disabled' : '' }}
                class="text-sm border border-[var(--border)] rounded-lg px-3.5 py-2 bg-[var(--surface-card)] focus:outline-none focus:ring-2 focus:ring-[#044b46]/15 focus:border-[#044b46]/40 transition-shadow disabled:opacity-50 disabled:cursor-not-allowed">
            <option value="">Semua Platform</option>
            @foreach ($platformOptions as $p)
                <option value="{{ $p->id }}" {{ (string) ($selectedPlatformId ?? '') === (string) $p->id ? 'selected' : '' }}>{{ $p->name }}</option>
            @endforeach
        </select>
    </form>

    @if (! empty($noClientSelected))
        <div class="card p-16 flex flex-col items-center justify-center text-center">
            <div class="w-14 h-14 rounded-full bg-[var(--brand-tint)] flex items-center justify-center mb-4">
                <span class="material-symbols-outlined text-[var(--brand)] text-[24px]">filter_alt</span>
            </div>
            <h2 class="font-display text-lg font-semibold text-[var(--text-primary)] mb-1.5">Pilih client dulu</h2>
            <p class="text-sm text-[var(--text-secondary)] max-w-sm">Performa konten ditampilkan per client. Pilih salah satu di dropdown atas untuk mulai.</p>
        </div>
    @else

    @if ($activeTab === 'table')
        @include('analytics._table-section')
    @elseif ($activeTab === 'audience')
        @include('analytics._audience-section')
    @else

        @php
            // 1 palet warna kategori dipakai bareng Suggested Split & Traffic
            // per Platform di bawah - biar nggak ada 2 array warna yang sama
            // persis didefinisikan ulang di 2 tempat.
            $chartColors = ['#044b46', '#3452a8', '#b8873a', '#b3427e', '#7c5cbf'];
        @endphp

        {{-- Phase 3 (Langkah 11) - coverage historis harus jelas, JANGAN
        tampilkan angka periode tanpa qualifier kalau datanya belum full. --}}
        @if (! empty($coverageMessage))
            <div class="card p-4 mb-6 flex items-start gap-3" style="background: var(--warning-tint); border-color: var(--warning-text);">
                <span class="material-symbols-outlined text-[var(--warning-text)] text-[20px]">info</span>
                <p class="text-[13px] text-[var(--warning-text)]">{{ $coverageMessage }}</p>
            </div>
        @endif

        {{-- Stat cards --}}
        <div class="grid grid-cols-2 sm:grid-cols-4 gap-4 mb-6">
            @foreach ($stats as $stat)
                <div class="card p-5 transition-shadow hover:shadow-[0_2px_10px_rgba(20,24,26,0.05)]">
                    <div class="flex items-center justify-between mb-4">
                        <span class="text-[13px] text-[var(--text-secondary)]">{{ $stat['label'] }}</span>
                        <span class="material-symbols-outlined text-[var(--text-muted)] text-[18px]">{{ $stat['icon'] }}</span>
                    </div>
                    <p class="font-display text-[26px] font-semibold text-[var(--text-primary)] mb-2 [font-variant-numeric:tabular-nums]">{{ $stat['value'] }}</p>
                    <p class="text-xs flex items-center gap-1
                        {{ $stat['trend'] === 'up' ? 'text-[var(--success-text)]' : ($stat['trend'] === 'down' ? 'text-[var(--danger-text)]' : 'text-[var(--text-muted)]') }}">
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

        <div class="space-y-5">

                {{-- AI Strategy Analysis - beneran manggil Gemini API --}}
                <div class="rounded-2xl overflow-hidden border border-[var(--border)] bg-[var(--surface-card)]" x-data="{ loading: false }">

                    {{-- Header flat (bukan gradient) - konsisten sama treatment card lain --}}
                    <div class="bg-[var(--surface-page)] border-b border-[var(--border)] px-4 sm:px-6 py-5 flex flex-col sm:flex-row sm:items-center justify-between gap-4">
                        <div class="flex items-center gap-3">
                            <div class="w-9 h-9 rounded-xl bg-[var(--brand-tint)] flex items-center justify-center shrink-0">
                                <span class="material-symbols-outlined text-[var(--brand)] text-[19px]">auto_awesome</span>
                            </div>
                            <div>
                                <h2 class="font-display text-lg font-semibold text-[var(--text-primary)] leading-tight">AI Strategy Analysis</h2>
                                <p class="text-xs text-[var(--text-secondary)] mt-0.5">Analisis performa bulan {{ $aiAnalysisMonth }}, dibangkitkan Gemini AI dari data asli client ini &mdash; independen dari filter periode di atas</p>
                            </div>
                        </div>

                        <div class="flex items-center gap-4 shrink-0">
                            @if ($latestAiInsight)
                                <a href="{{ route('analytics.ai-strategy.history', ['client_id' => $selectedClientId]) }}"
                                   class="text-xs font-medium text-[var(--text-muted)] hover:text-[var(--brand)] flex items-center gap-1 transition-colors focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[var(--brand)] rounded">
                                    <span class="material-symbols-outlined text-[15px]">history</span> Riwayat
                                </a>
                            @endif
                        @if ($canManageAiStrategy)
                        <form action="{{ route('analytics.ai-strategy') }}" method="POST" x-on:submit="loading = true" class="shrink-0">
                            @csrf
                            <input type="hidden" name="client_id" value="{{ $selectedClientId }}">
                            <button type="submit" :disabled="loading"
                                    class="btn-primary">
                                <span x-show="!loading" class="flex items-center gap-1.5">
                                    <span class="material-symbols-outlined text-[16px]">{{ $latestAiInsight ? 'refresh' : 'bolt' }}</span>
                                    {{ $latestAiInsight ? 'Generate Ulang' : 'Generate Analisis' }}
                                </span>
                                <span x-show="loading" x-cloak class="flex items-center gap-1.5">
                                    <svg class="animate-spin h-4 w-4" viewBox="0 0 24 24" fill="none"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v4a4 4 0 00-4 4H4z"/></svg>
                                    Menganalisis...
                                </span>
                            </button>
                        </form>
                        @endif
                        </div>
                    </div>

                    <div class="bg-[var(--surface-card)] p-4 sm:p-6">

                    @if (session('ai_error'))
                        <div class="bg-[var(--danger-tint)] border border-[var(--danger-border)] rounded-lg p-4 text-sm text-[var(--danger-text)] flex items-start gap-2.5">
                            <span class="material-symbols-outlined text-[17px] shrink-0">error</span>
                            {{ session('ai_error') }}
                        </div>
                    @elseif (! $latestAiInsight)
                        <div class="flex flex-col items-center justify-center text-center py-10">
                            <div class="w-12 h-12 rounded-full bg-[var(--brand-tint)] flex items-center justify-center mb-3">
                                <span class="material-symbols-outlined text-[var(--brand)] text-[22px]">insights</span>
                            </div>
                            <p class="text-sm font-medium text-[var(--text-primary)] mb-1">Belum ada analisis buat client ini</p>
                            <p class="text-xs text-[var(--text-muted)] max-w-xs">
                                @if ($canManageAiStrategy)
                                    Klik "Generate Analisis" di atas — AI bakal baca performa 30 hari terakhir dan kasih rekomendasi strategi konkret.
                                @else
                                    Belum ada yang men-generate analisis AI untuk client ini.
                                @endif
                            </p>
                        </div>
                    @elseif ($latestAiInsight->status === 'failed')
                        <div class="bg-[var(--danger-tint)] border border-[var(--danger-border)] rounded-lg p-4 text-sm text-[var(--danger-text)] flex items-start gap-2.5">
                            <span class="material-symbols-outlined text-[17px] shrink-0">error</span>
                            Analisis terakhir gagal: {{ $latestAiInsight->error_message }}
                        </div>
                    @else
                        @php
                            // Pilihan pillar buat dropdown "Ganti Kategori" di modal
                            // regenerate ide - ambil dari suggested_split, fallback
                            // ke pillar yang beneran dipakai di content_ideas kalau
                            // suggested_split-nya kosong (insight lama).
                            $pillarOptionsForRegenerate = collect($latestAiInsight->suggested_split)->pluck('label')->values();
                            if ($pillarOptionsForRegenerate->isEmpty()) {
                                $pillarOptionsForRegenerate = collect($latestAiInsight->content_ideas)->pluck('pillar')->filter()->unique()->values();
                            }
                        @endphp
                        <div x-data="aiChat({{ $latestAiInsight->id }}, {{ Js::from($latestAiInsight->messages->map(fn($m) => ['role' => $m->role, 'message' => $m->message, 'time' => $m->created_at->format('H:i')])) }}, {{ Js::from($latestAiInsight->content_ideas ?? []) }}, {{ Js::from($pillarOptionsForRegenerate) }}, {{ $latestAiInsight->applied_at ? 'true' : 'false' }})">

                        {{-- Tab nav --}}
                        <div class="flex items-center gap-1 bg-[var(--surface-muted)] rounded-lg p-1 mb-5 w-fit">
                            <button type="button" x-on:click="tab = 'ringkasan'"
                                    :class="tab === 'ringkasan' ? 'bg-[var(--surface-card)] text-[var(--text-primary)] shadow-sm' : 'text-[var(--text-muted)] hover:text-[var(--text-secondary)]'"
                                    class="text-xs font-medium px-4 py-2 rounded-md transition-colors duration-200">
                                Ringkasan
                            </button>
                            <button type="button" x-on:click="tab = 'ide'"
                                    :class="tab === 'ide' ? 'bg-[var(--surface-card)] text-[var(--text-primary)] shadow-sm' : 'text-[var(--text-muted)] hover:text-[var(--text-secondary)]'"
                                    class="text-xs font-medium px-4 py-2 rounded-md transition-colors duration-200">
                                Ide Konten ({{ count($latestAiInsight->content_ideas ?? []) }})
                            </button>
                            <button type="button" x-on:click="tab = 'diskusi'"
                                    :class="tab === 'diskusi' ? 'bg-[var(--surface-card)] text-[var(--text-primary)] shadow-sm' : 'text-[var(--text-muted)] hover:text-[var(--text-secondary)]'"
                                    class="text-xs font-medium px-4 py-2 rounded-md transition-colors duration-200 flex items-center gap-1.5">
                                Diskusi
                                <span x-show="messages.filter(m => m.role !== 'system').length > 0" x-cloak
                                      class="w-4 h-4 rounded-full bg-[var(--brand-solid)] text-white text-[9px] font-bold flex items-center justify-center"
                                      x-text="messages.filter(m => m.role !== 'system').length"></span>
                            </button>
                        </div>

                        {{-- ===== TAB: RINGKASAN ===== --}}
                        <div x-show="tab === 'ringkasan'" x-cloak>
                            <div class="bg-[var(--surface-page)] border border-[var(--border)] rounded-xl p-4 mb-5">
                                <p class="text-sm text-[var(--text-primary)] leading-relaxed">{{ $latestAiInsight->summary }}</p>
                            </div>

                            <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">

                                <div class="lg:col-span-2 space-y-5">
                                    @if (! empty($latestAiInsight->top_pillars))
                                        <div>
                                            <p class="text-xs font-semibold text-[var(--text-muted)] uppercase tracking-wide mb-3">Pilar Konten Teratas</p>
                                            <div class="space-y-2">
                                                @php $rankStyles = ['bg-[#044b46]', 'bg-[#5c6266]', 'bg-[#9aa0a4]']; @endphp
                                                @foreach ($latestAiInsight->top_pillars as $i => $pillar)
                                                    <div class="flex items-start gap-3 border border-[var(--border)] rounded-xl p-3.5 hover:border-[#044b46]/20 hover:bg-[var(--surface-subtle-2)] transition-colors">
                                                        <span class="w-6 h-6 rounded-full {{ $rankStyles[$i] ?? 'bg-[var(--text-idle)]' }} text-white text-[11px] font-semibold flex items-center justify-center shrink-0">{{ $i + 1 }}</span>
                                                        <div class="min-w-0">
                                                            <p class="text-sm font-semibold text-[var(--text-primary)]">{{ $pillar['name'] }}</p>
                                                            <p class="text-xs text-[var(--text-secondary)] mt-0.5 leading-relaxed">{{ $pillar['reasoning'] }}</p>
                                                        </div>
                                                    </div>
                                                @endforeach
                                            </div>
                                        </div>
                                    @endif

                                    @if (! empty($latestAiInsight->action_items))
                                        <div>
                                            <p class="text-xs font-semibold text-[var(--text-muted)] uppercase tracking-wide mb-3">Item Tindakan</p>
                                            <ul class="space-y-2.5">
                                                @foreach ($latestAiInsight->action_items as $item)
                                                    <li class="flex items-start gap-2.5 text-sm text-[var(--text-primary)]">
                                                        <span class="w-5 h-5 rounded-full bg-[var(--success-tint-soft)] flex items-center justify-center shrink-0 mt-0.5">
                                                            <span class="material-symbols-outlined text-[var(--success-text)] text-[13px]">check</span>
                                                        </span>
                                                        <span class="leading-relaxed">{{ $item }}</span>
                                                    </li>
                                                @endforeach
                                            </ul>
                                        </div>
                                    @endif
                                </div>

                                <div class="space-y-5">
                                    @if (! empty($latestAiInsight->suggested_split))
                                        <div class="bg-[var(--surface-page)] rounded-xl p-4">
                                            <p class="text-xs font-semibold text-[var(--text-muted)] uppercase tracking-wide mb-3">Komposisi Disarankan</p>
                                            <div class="space-y-3">
                                                @foreach ($latestAiInsight->suggested_split as $i => $row)
                                                    <div>
                                                        <div class="flex items-center justify-between text-xs mb-1">
                                                            <span class="text-[var(--text-secondary)] flex items-center gap-1.5">
                                                                <span class="w-1.5 h-1.5 rounded-full shrink-0" style="background-color: {{ $chartColors[$i % 5] }}"></span>
                                                                {{ $row['label'] }}
                                                            </span>
                                                            <span class="font-semibold text-[var(--text-primary)] [font-variant-numeric:tabular-nums]">{{ $row['value'] }}%</span>
                                                        </div>
                                                        <div class="w-full h-1.5 rounded-full bg-[var(--surface-card)] overflow-hidden">
                                                            <div class="h-full rounded-full transition-all duration-500" style="width: {{ $row['value'] }}%; background-color: {{ $chartColors[$i % 5] }}"></div>
                                                        </div>
                                                    </div>
                                                @endforeach
                                            </div>
                                        </div>
                                    @endif

                                    @if ($latestAiInsight->data_completeness_percent !== null)
                                        <div class="border border-[var(--border)] rounded-xl p-4">
                                            <p class="text-xs font-semibold text-[var(--text-muted)] uppercase tracking-wide mb-2">Kelengkapan Data</p>
                                            <div class="flex items-end gap-2 mb-2">
                                                <span class="font-display text-2xl font-semibold text-[var(--text-primary)] leading-none [font-variant-numeric:tabular-nums]">{{ $latestAiInsight->data_completeness_percent }}%</span>
                                                @if ($latestAiInsight->data_completeness_percent < 60)
                                                    <span class="text-[10px] text-[var(--warning-text)] font-medium mb-0.5">Data agak tipis</span>
                                                @endif
                                            </div>
                                            <div class="w-full h-1.5 rounded-full bg-[var(--surface-muted)] overflow-hidden">
                                                <div class="h-full rounded-full {{ $latestAiInsight->data_completeness_percent >= 60 ? 'bg-[var(--success-text)]' : 'bg-[var(--warning-strong)]' }}"
                                                     style="width: {{ $latestAiInsight->data_completeness_percent }}%"></div>
                                            </div>
                                        </div>
                                    @endif
                                </div>
                            </div>

                            <p class="text-[11px] text-[var(--text-muted)] mt-5 pt-4 border-t border-[var(--surface-muted)]">
                                Digenerate {{ $latestAiInsight->created_at->diffForHumans() }} oleh {{ $latestAiInsight->generatedBy->name ?? '-' }}
                                @if ($latestAiInsight->applied_at)
                                    &middot; Diterapkan {{ $latestAiInsight->applied_at->diffForHumans() }}
                                @endif
                            </p>
                        </div>

                        {{-- ===== TAB: IDE KONTEN ===== --}}
                        <div x-show="tab === 'ide'" x-cloak>
                            @if (! $latestAiInsight->applied_at && ! empty($latestAiInsight->suggested_split))
                                @if ($canManageAiStrategy)
                                    @unless ($latestAiInsight->client->activePackage)
                                        <p class="text-[11px] text-[var(--text-muted)] mb-2 flex items-center gap-1">
                                            <span class="material-symbols-outlined text-[13px]">info</span>
                                            Paket belum tercatat - ide diterapkan tanpa validasi kuota paket.
                                        </p>
                                    @endunless
                                    <form action="{{ route('analytics.ai-strategy.apply', $latestAiInsight) }}" method="POST" class="mb-4">
                                        @csrf
                                        <button type="submit" class="btn-primary w-full">
                                            <span class="material-symbols-outlined text-[16px]">bolt</span>
                                            Terapkan Semua Ide Ini ke Content Plan
                                        </button>
                                    </form>
                                @endif
                            @elseif ($latestAiInsight->applied_at)
                                <div class="border border-[var(--success-tint-soft)] bg-[var(--success-tint-soft-2)] rounded-xl p-3.5 mb-4 flex items-center justify-between gap-3">
                                    <div class="flex items-center gap-1.5 text-sm font-medium text-[var(--success-text)]">
                                        <span class="material-symbols-outlined text-[16px]">check_circle</span>
                                        Sudah diterapkan ke Content Plan bulan ini
                                    </div>
                                    @if ($canManageAiStrategy)
                                    <form action="{{ route('analytics.ai-strategy.revert', $latestAiInsight) }}" method="POST"
                                          onsubmit="return appConfirm(this, 'Yakin mau tarik kembali? Semua draft content item yang dibuat dari analisis ini bakal dihapus (kalau belum ada progress).', { danger: true })">
                                        @csrf
                                        <button type="submit" class="btn-danger shrink-0">
                                            <span class="material-symbols-outlined text-[14px]">undo</span>
                                            Tarik Kembali
                                        </button>
                                    </form>
                                    @endif
                                </div>
                            @endif

                            <p x-show="ideas.length === 0" class="text-sm text-[var(--text-muted)] text-center py-10">Belum ada ide konten buat analisis ini.</p>

                            <div x-show="ideas.length > 0" class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                                <template x-for="(idea, index) in ideas" :key="index">
                                    <button type="button" x-on:click="openIdea(index)"
                                            class="text-left border border-[var(--border)] rounded-lg p-3.5 hover:border-[#044b46]/20 hover:bg-[var(--surface-subtle-2)] transition-colors focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[var(--brand)]">
                                        <div class="flex items-center justify-between gap-2 mb-1.5">
                                            <span class="badge badge-success inline-block" x-text="idea.pillar ?? '-'"></span>
                                            <span x-show="idea.predicted_score !== null && idea.predicted_score !== undefined"
                                                  class="badge shrink-0"
                                                  :class="scoreBadgeClass(idea.predicted_label)"
                                                  :title="scoreLabelText(idea.predicted_label) + ' — berdasarkan performa historis pillar & platform ini'"
                                                  x-text="idea.predicted_score + '%'"></span>
                                        </div>
                                        <p class="text-sm font-semibold text-[var(--text-primary)]" x-text="idea.title ?? '-'"></p>
                                        <p class="text-xs text-[var(--text-secondary)] mt-1 leading-relaxed" x-text="idea.brief ?? '-'"></p>
                                        <p class="text-[10px] text-[var(--text-muted)] mt-2 flex items-center gap-2">
                                            <span x-show="idea.type" x-text="idea.type"></span>
                                            <span x-show="idea.type && idea.platform">&middot;</span>
                                            <span x-show="idea.platform" x-text="idea.platform"></span>
                                        </p>
                                    </button>
                                </template>
                            </div>
                        </div>

                        {{-- ===== TAB: DISKUSI ===== --}}
                        <div x-show="tab === 'diskusi'" x-cloak>
                            <p class="text-xs text-[var(--text-muted)] mb-3">Kasih masukan, koreksi, atau tanya soal analisis ini — AI bakal jawab tetap ngerujuk ke data asli. Ngobrol di sini belum ngubah apapun; kalau AI setuju sama masukan lo, klik "Perbarui Analisis dari Diskusi Ini" di bawah biar beneran diterapkan.</p>

                            <div class="space-y-3 mb-3 max-h-96 overflow-y-auto" x-ref="messageList">
                                <template x-for="msg in messages" :key="msg.id ?? msg.message + msg.time">
                                    <div>
                                        <p x-show="msg.role === 'system'" x-cloak class="text-center text-[11px] text-[var(--text-muted)] italic" x-text="msg.message"></p>
                                        <div x-show="msg.role !== 'system'" x-cloak class="flex" :class="msg.role === 'user' ? 'justify-end' : 'justify-start'">
                                            <div class="max-w-[85%] rounded-xl px-3.5 py-2.5"
                                                 :class="msg.role === 'user' ? 'bg-[var(--brand-solid)] text-white' : 'bg-[var(--surface-page)] text-[var(--text-primary)]'">
                                                <p class="text-sm leading-relaxed whitespace-pre-wrap" x-text="msg.message"></p>
                                                <p class="text-[10px] mt-1" :class="msg.role === 'user' ? 'text-white/60' : 'text-[var(--text-muted)]'" x-text="msg.time"></p>
                                            </div>
                                        </div>
                                    </div>
                                </template>

                                <div x-show="messages.length === 0" x-cloak class="text-center py-8">
                                    <span class="material-symbols-outlined text-[var(--icon-disabled)] text-[22px] mb-2 block">forum</span>
                                    <p class="text-xs text-[var(--text-muted)]">Belum ada diskusi. Tulis pertanyaan/masukan di bawah.</p>
                                </div>

                                <div x-show="sending" x-cloak class="flex justify-start">
                                    <div class="bg-[var(--surface-page)] rounded-xl px-3.5 py-2.5">
                                        <div class="flex gap-1">
                                            <span class="w-1.5 h-1.5 rounded-full bg-[var(--text-placeholder)] animate-bounce" style="animation-delay:0ms"></span>
                                            <span class="w-1.5 h-1.5 rounded-full bg-[var(--text-placeholder)] animate-bounce" style="animation-delay:150ms"></span>
                                            <span class="w-1.5 h-1.5 rounded-full bg-[var(--text-placeholder)] animate-bounce" style="animation-delay:300ms"></span>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <p x-show="errorMsg" x-cloak class="text-xs text-[var(--danger-text)] mb-2" x-text="errorMsg"></p>

                            {{-- Phase 4.4 (Langkah 4) - history/riwayat diskusi
                                 di atas TETAP dibaca semua orang (read-only,
                                 aman) - cuma INPUT/SEND yang men-generate
                                 balasan AI baru yang di-gate. --}}
                            @if ($canManageAiStrategy)
                            <form x-on:submit.prevent="send()" class="flex items-center gap-2 mb-3">
                                <input type="text" x-model="draft" placeholder="Tulis masukan atau pertanyaan..."
                                       :disabled="sending"
                                       class="bg-[var(--surface-card)] flex-1 border border-[var(--border)] rounded-lg px-3.5 py-2.5 text-sm focus:outline-none focus:ring-2 focus:ring-[#044b46]/15 focus:border-[#044b46]/40 disabled:opacity-60 transition-shadow">
                                <button type="submit" :disabled="sending || !draft.trim()"
                                        class="w-10 h-10 shrink-0 rounded-lg bg-[var(--brand-solid)] text-white flex items-center justify-center hover:bg-[var(--brand-dark)] active:scale-[0.95] disabled:opacity-40 transition-all focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[var(--brand)]">
                                    <span class="material-symbols-outlined text-[18px]">send</span>
                                </button>
                            </form>
                            @endif

                            @if ($canManageAiStrategy)
                                @if ($latestAiInsight->applied_at)
                                    <p class="text-xs text-[var(--text-muted)] text-center bg-[var(--surface-page)] rounded-lg px-3.5 py-2.5 leading-relaxed">
                                        Analisis ini sudah diterapkan ke Content Plan, jadi nggak bisa diperbarui dari diskusi lagi (draft yang udah dibuat bisa nggak nyambung lagi).
                                        <button type="button" x-on:click="tab = 'ide'" class="text-[var(--brand)] font-medium hover:underline">Tarik kembali dulu</button>
                                        kalau mau update berdasarkan diskusi ini.
                                    </p>
                                @elseif ($latestAiInsight->messages->where('role', '!=', 'system')->isNotEmpty())
                                    <form action="{{ route('analytics.ai-strategy.refine', $latestAiInsight) }}" method="POST"
                                          onsubmit="return appConfirm(this, 'Analisis (summary, action items, suggested split) bakal diperbarui berdasarkan seluruh diskusi di atas. Lanjut?')">
                                        @csrf
                                        <button type="submit" class="w-full text-xs font-medium bg-[var(--info-tint)] text-[var(--info-text)] px-3.5 py-2.5 rounded-lg hover:bg-[var(--info-tint-soft)] active:scale-[0.98] transition-all flex items-center justify-center gap-1.5 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[var(--info-text)]">
                                            <span class="material-symbols-outlined text-[15px]">sync</span>
                                            Perbarui Analisis dari Diskusi Ini
                                        </button>
                                    </form>
                                @endif
                            @endif
                        </div>

                        {{-- ===== MODAL: DETAIL & REGENERATE IDE ===== --}}
                        <div x-show="selectedIndex !== null" x-cloak
                             class="fixed inset-0 z-50 flex items-center justify-center p-4"
                             x-on:keydown.escape.window="closeIdea()">
                            <div class="absolute inset-0 bg-[#14181a]/40" x-on:click="closeIdea()"></div>

                            <div x-show="selectedIndex !== null" x-cloak
                                 x-transition:enter="transition ease-out duration-200"
                                 x-transition:enter-start="opacity-0 scale-95"
                                 x-transition:enter-end="opacity-100 scale-100"
                                 role="dialog" aria-modal="true" aria-labelledby="idea-detail-modal-title" x-trap="selectedIndex !== null"
                                 class="relative bg-[var(--surface-card)] rounded-2xl border border-[var(--border)] shadow-xl w-full max-w-lg max-h-[85vh] overflow-y-auto">

                                <template x-if="selectedIdea">
                                    <div class="p-6">
                                        <div class="flex items-start justify-between gap-3 mb-4">
                                            <div class="flex items-center gap-2 flex-wrap">
                                                <span class="badge badge-success" x-text="selectedIdea.pillar ?? '-'"></span>
                                                <span x-show="selectedIdea.predicted_score !== null && selectedIdea.predicted_score !== undefined"
                                                      class="badge"
                                                      :class="scoreBadgeClass(selectedIdea.predicted_label)"
                                                      x-text="scoreLabelText(selectedIdea.predicted_label) + ' (' + selectedIdea.predicted_score + '%)'"></span>
                                            </div>
                                            <button type="button" x-on:click="closeIdea()"
                                                    class="text-[var(--text-muted)] hover:text-[var(--text-primary)] transition-colors focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[var(--brand)] rounded">
                                                <span class="material-symbols-outlined text-[20px]">close</span>
                                            </button>
                                        </div>

                                        <p id="idea-detail-modal-title" class="font-display text-lg font-semibold text-[var(--text-primary)] leading-snug mb-2" x-text="selectedIdea.title ?? '-'"></p>
                                        <p class="text-sm text-[var(--text-secondary)] leading-relaxed mb-4" x-text="selectedIdea.brief ?? '-'"></p>

                                        <div class="flex items-center gap-2 mb-5">
                                            <span x-show="selectedIdea.type" class="badge badge-neutral">
                                                <span class="material-symbols-outlined text-[13px]">design_services</span>
                                                <span x-text="selectedIdea.type"></span>
                                            </span>
                                            <span x-show="selectedIdea.platform" class="badge badge-neutral">
                                                <span class="material-symbols-outlined text-[13px]">hub</span>
                                                <span x-text="selectedIdea.platform"></span>
                                            </span>
                                        </div>

                                        @if (! $canManageAiStrategy)
                                            {{-- Phase 4.4 (Langkah 3) - regenerate MUTATING, control-nya di-gate. --}}
                                        @elseif ($latestAiInsight->applied_at)
                                            <p class="text-xs text-[var(--text-muted)] bg-[var(--surface-page)] rounded-lg px-3.5 py-2.5 leading-relaxed">
                                                Analisis ini udah diterapkan ke Content Plan, jadi ide di sini nggak bisa di-regenerate lagi (draft yang udah dibuat bisa nggak nyambung lagi). Tarik kembali dulu kalau mau ubah ide.
                                            </p>
                                        @else
                                            <div class="border-t border-[var(--surface-muted)] pt-4">
                                                <label for="regenerate_idea_pillar" class="block text-[11px] font-semibold text-[var(--text-muted)] uppercase tracking-wide mb-2">Ganti Kategori (opsional)</label>
                                                <select id="regenerate_idea_pillar" x-model="editPillar" :disabled="regenerating"
                                                        class="w-full text-sm border border-[var(--border)] rounded-lg px-3.5 py-2 bg-[var(--surface-card)] mb-3 focus:outline-none focus:ring-2 focus:ring-[#044b46]/15 focus:border-[#044b46]/40 disabled:opacity-60 transition-shadow">
                                                    <template x-for="pillar in pillarOptions" :key="pillar">
                                                        <option :value="pillar" x-text="pillar"></option>
                                                    </template>
                                                </select>

                                                <p x-show="regenError" x-cloak class="text-xs text-[var(--danger-text)] mb-2" x-text="regenError"></p>

                                                <button type="button" x-on:click="regenerateIdea()" :disabled="regenerating"
                                                        class="btn-primary w-full">
                                                    <span x-show="!regenerating" class="flex items-center gap-1.5">
                                                        <span class="material-symbols-outlined text-[16px]">refresh</span>
                                                        <span x-text="editPillar === selectedIdea.pillar ? 'Cari Alternatif Lain' : 'Regenerate dengan Kategori Baru'"></span>
                                                    </span>
                                                    <span x-show="regenerating" x-cloak class="flex items-center gap-1.5">
                                                        <svg class="animate-spin h-4 w-4" viewBox="0 0 24 24" fill="none"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v4a4 4 0 00-4 4H4z"/></svg>
                                                        Nyari alternatif...
                                                    </span>
                                                </button>
                                                <p class="text-[11px] text-[var(--text-muted)] mt-2 leading-relaxed">AI bakal bikin ide baru buat kategori ini, mempertimbangkan semua ide lain yang udah ada biar nggak duplikat &amp; beban kerja tim tetap seimbang.</p>
                                            </div>
                                        @endif
                                    </div>
                                </template>
                            </div>
                        </div>

                        </div>
                    @endif

                    </div>
                </div>

                {{-- Divider zona: dari "AI insight" pindah ke "raw metrics" --}}
                <div class="flex items-center gap-3 pt-2">
                    <span class="text-[11px] font-semibold text-[var(--text-muted)] uppercase tracking-wider whitespace-nowrap">Detail Performa</span>
                    <div class="flex-1 h-px bg-[var(--border)]"></div>
                </div>

                {{-- Trend chart --}}
                <div class="card p-6">
                    <div class="flex items-center gap-2 mb-1">
                        <span class="material-symbols-outlined text-[var(--text-muted)] text-[18px]">show_chart</span>
                        <h2 class="font-display text-lg font-semibold text-[var(--text-primary)]">Views dari Waktu ke Waktu</h2>
                    </div>
                    <p class="text-xs text-[var(--text-muted)] mb-5 ml-[26px]">Total views seluruh konten pada periode terpilih.</p>
                    <x-trend-chart :trend="$trend" />
                </div>

                {{-- Traffic per Platform - sekarang full width, bukan sidebar sempit --}}
                <div class="card p-6">
                    <div class="flex items-center gap-2 mb-5">
                        <span class="material-symbols-outlined text-[var(--text-muted)] text-[18px]">hub</span>
                        <h2 class="font-display text-lg font-semibold text-[var(--text-primary)]">Traffic per Platform</h2>
                    </div>

                    @if ($platformBreakdown->isEmpty())
                        <p class="text-sm text-[var(--text-muted)] text-center py-6">Belum ada data.</p>
                    @else
                        @php
                            $maxPlatform = max($platformBreakdown->max('value'), 1);
                        @endphp
                        <div class="grid grid-cols-2 sm:grid-cols-4 gap-4">
                            @foreach ($platformBreakdown as $i => $row)
                                <div class="border border-[var(--border)] rounded-xl p-4 hover:border-[#044b46]/20 transition-colors">
                                    <div class="flex items-center gap-2 mb-3">
                                        <span class="w-2 h-2 rounded-full shrink-0" style="background-color: {{ $chartColors[$i % 5] }}"></span>
                                        <span class="text-sm text-[var(--text-secondary)]">{{ $row['label'] }}</span>
                                    </div>
                                    <p class="font-display text-xl font-semibold text-[var(--text-primary)] mb-2 [font-variant-numeric:tabular-nums]">{{ number_format($row['value']) }}</p>
                                    <div class="w-full h-1.5 rounded-full bg-[var(--surface-muted)] overflow-hidden">
                                        <div class="h-full rounded-full transition-all duration-500" style="width: {{ max(($row['value'] / $maxPlatform) * 100, 3) }}%; background-color: {{ $chartColors[$i % 5] }}"></div>
                                    </div>
                                </div>
                            @endforeach
                        </div>
                    @endif
                </div>

                {{-- Top performing content --}}
                <div class="card p-6">
                    <div class="flex items-center gap-2 mb-4">
                        <span class="material-symbols-outlined text-[var(--text-muted)] text-[18px]">military_tech</span>
                        <h2 class="font-display text-lg font-semibold text-[var(--text-primary)]">Konten Berperforma Terbaik</h2>
                    </div>

                    @if ($topContent->isEmpty())
                        <p class="text-sm text-[var(--text-muted)] py-6 text-center">Belum ada konten dengan data performa.</p>
                    @else
                        <div class="overflow-x-auto hidden sm:block">
                            <table class="w-full table-fixed text-sm text-left">
                                <thead class="bg-[var(--surface-page)]">
                                    <tr class="text-[var(--text-muted)] text-[11px] uppercase tracking-wide">
                                        <th class="w-[28%] px-6 py-3 font-medium whitespace-nowrap">Konten</th>
                                        <th class="w-[16%] px-4 py-3 font-medium whitespace-nowrap">Klien</th>
                                        <th class="w-[12%] px-4 py-3 font-medium whitespace-nowrap">Platform</th>
                                        <th class="w-[12%] px-4 py-3 font-medium text-right whitespace-nowrap">Views</th>
                                        <th class="w-[14%] px-4 py-3 font-medium text-right whitespace-nowrap">Engagement</th>
                                        <th class="w-[18%] px-6 py-3"></th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach ($topContent as $content)
                                        <tr class="border-t border-[var(--surface-muted)] hover:bg-[var(--surface-page)] transition-colors">
                                            <td class="px-6 py-3.5 font-medium text-[var(--text-primary)]">
                                                <p class="truncate" title="{{ $content['title'] }}">{{ $content['title'] }}</p>
                                                @if ($content['linked'] ?? true)
                                                    <p class="text-xs text-[var(--text-muted)] font-normal mt-0.5 truncate">{{ $content['type'] }}</p>
                                                @else
                                                    <div class="flex items-center gap-1.5 flex-wrap mt-1">
                                                        <span class="badge badge-neutral">Belum terhubung</span>
                                                        @if (($content['type'] ?? '-') !== '-')
                                                            <span class="text-xs text-[var(--text-muted)]">{{ $content['type'] }}</span>
                                                        @endif
                                                    </div>
                                                @endif
                                            </td>
                                            <td class="px-4 py-3.5 text-[var(--text-secondary)] truncate">{{ $content['client'] }}</td>
                                            <td class="px-4 py-3.5 text-[var(--text-secondary)] truncate">{{ $content['platform'] }}</td>
                                            <td class="px-4 py-3.5 text-right font-medium text-[var(--text-primary)] [font-variant-numeric:tabular-nums]">{{ number_format($content['views']) }}</td>
                                            <td class="px-4 py-3.5 text-right">
                                                <span class="badge badge-success [font-variant-numeric:tabular-nums]">{{ $content['engagement_rate'] }}%</span>
                                            </td>
                                            <td class="px-6 py-3.5 text-right">
                                                <div class="flex items-center justify-end gap-2.5 flex-wrap">
                                                    @if ($content['linked'] ?? true)
                                                        <a href="{{ route('analytics.show', $content['id']) }}" class="text-xs font-medium text-[var(--brand)] hover:underline whitespace-nowrap focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[var(--brand)] rounded">Detail</a>
                                                        @if ($content['permalink'] ?? null)
                                                            <span x-data="tooltipHover('Lihat di Instagram')" class="contents">
                                                                <a href="{{ $content['permalink'] }}" target="_blank" rel="noopener noreferrer"
                                                                   @mouseenter="onEnter($event)" @mouseleave="onLeave()"
                                                                   aria-label="Lihat di Instagram" class="text-[var(--text-muted)] hover:text-[var(--brand)] transition-colors">
                                                                    <span class="material-symbols-outlined text-[16px]">open_in_new</span>
                                                                </a>
                                                                @include('components.action-tooltip')
                                                            </span>
                                                        @endif
                                                    @else
                                                        @if ($content['api_integration_id'] ?? null)
                                                            <a href="{{ route('publishing-tracker.instagram.unmatched', $content['api_integration_id']) }}?return_to={{ urlencode(url()->full()) }}#post-{{ $content['external_post_id'] }}"
                                                               class="text-xs font-medium text-[var(--brand)] hover:underline whitespace-nowrap">Hubungkan</a>
                                                        @endif
                                                        @if ($content['permalink'] ?? null)
                                                            <span x-data="tooltipHover('Lihat Post')" class="contents">
                                                                <a href="{{ $content['permalink'] }}" target="_blank" rel="noopener noreferrer"
                                                                   @mouseenter="onEnter($event)" @mouseleave="onLeave()"
                                                                   aria-label="Lihat Post" class="text-[var(--text-muted)] hover:text-[var(--brand)] transition-colors">
                                                                    <span class="material-symbols-outlined text-[16px]">open_in_new</span>
                                                                </a>
                                                                @include('components.action-tooltip')
                                                            </span>
                                                        @endif
                                                    @endif
                                                </div>
                                            </td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>

                        {{-- Mobile accordion - sama koleksi data, hanya tampil di bawah sm --}}
                        <div class="sm:hidden space-y-3">
                            @foreach ($topContent as $content)
                                <div class="card p-3.5" x-data="{ open: false }">
                                    <button type="button" class="w-full text-left flex items-start gap-2 cursor-pointer" @click="open = !open" :aria-expanded="open">
                                        <div class="flex-1 min-w-0">
                                            <p class="font-medium text-[var(--text-primary)] text-sm truncate">{{ $content['title'] }}</p>
                                            <div class="flex items-center gap-1.5 flex-wrap mt-1.5">
                                                <span class="badge badge-success [font-variant-numeric:tabular-nums]">{{ $content['engagement_rate'] }}% Engagement</span>
                                                <span class="text-xs text-[var(--text-secondary)] whitespace-nowrap">{{ $content['client'] }} &middot; {{ $content['platform'] }}</span>
                                            </div>
                                        </div>
                                        <div class="w-7 h-7 shrink-0 flex items-center justify-center rounded-lg text-[var(--text-muted)]">
                                            <span class="material-symbols-outlined text-[19px] transition-transform" :class="open && 'rotate-180'">expand_more</span>
                                        </div>
                                    </button>

                                    <div x-show="open" x-cloak x-transition class="mt-3 pt-3 border-t border-[var(--surface-muted)] space-y-2">
                                        <div class="flex items-center justify-between text-xs">
                                            <span class="text-[var(--text-muted)]">Tipe / Format</span>
                                            <span class="text-[var(--text-primary)] font-medium">{{ $content['type'] ?? '-' }}</span>
                                        </div>
                                        <div class="flex items-center justify-between text-xs">
                                            <span class="text-[var(--text-muted)]">Views</span>
                                            <span class="text-[var(--text-primary)] font-medium [font-variant-numeric:tabular-nums]">{{ number_format($content['views']) }}</span>
                                        </div>
                                        @if ($content['linked'] ?? true)
                                            <a href="{{ route('analytics.show', $content['id']) }}"
                                                class="mt-2 flex items-center justify-center gap-1.5 text-xs font-semibold text-[var(--brand)] bg-[var(--brand-tint)] hover:bg-[var(--brand-tint-hover)] rounded-lg py-2 transition-colors focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[var(--brand)]">
                                                Lihat Detail <span class="material-symbols-outlined text-[15px]">arrow_forward</span>
                                            </a>
                                            @if ($content['permalink'] ?? null)
                                                <a href="{{ $content['permalink'] }}" target="_blank" rel="noopener noreferrer"
                                                    class="mt-1.5 flex items-center justify-center gap-1.5 text-xs font-medium text-[var(--text-muted)] hover:text-[var(--brand)] transition-colors">
                                                    Lihat di Instagram <span class="material-symbols-outlined text-[13px]">open_in_new</span>
                                                </a>
                                            @endif
                                        @else
                                            <span class="badge badge-neutral block text-center">Belum terhubung ke konten internal</span>
                                            @if ($content['api_integration_id'] ?? null)
                                                <a href="{{ route('publishing-tracker.instagram.unmatched', $content['api_integration_id']) }}?return_to={{ urlencode(url()->full()) }}#post-{{ $content['external_post_id'] }}"
                                                    class="mt-2 flex items-center justify-center gap-1.5 text-xs font-semibold text-[var(--brand)] bg-[var(--brand-tint)] hover:bg-[var(--brand-tint-hover)] rounded-lg py-2 transition-colors">
                                                    Hubungkan Konten <span class="material-symbols-outlined text-[15px]">link</span>
                                                </a>
                                            @endif
                                            @if ($content['permalink'] ?? null)
                                                <a href="{{ $content['permalink'] }}" target="_blank" rel="noopener noreferrer"
                                                    class="mt-1.5 flex items-center justify-center gap-1.5 text-xs font-medium text-[var(--text-muted)] hover:text-[var(--brand)] transition-colors">
                                                    Lihat Post <span class="material-symbols-outlined text-[13px]">open_in_new</span>
                                                </a>
                                            @endif
                                        @endif
                                    </div>
                                </div>
                            @endforeach
                        </div>
                    @endif
                </div>
        </div>
    @endif
    @endif
</div>

@if (! empty($selectedClientId))
<script>
function aiChat(insightId, initialMessages, initialIdeas, pillarOptions, isApplied) {
    return {
        tab: 'ringkasan',
        open: initialMessages.filter(m => m.role !== 'system').length > 0,
        messages: initialMessages,
        draft: '',
        sending: false,
        errorMsg: '',

        // ===== Modal detail & regenerate ide =====
        ideas: initialIdeas,
        pillarOptions: pillarOptions,
        selectedIndex: null,
        editPillar: '',
        regenerating: false,
        regenError: '',
        get selectedIdea() {
            return this.selectedIndex !== null ? this.ideas[this.selectedIndex] : null;
        },
        scoreBadgeClass(label) {
            return {
                high: 'badge-success',
                medium: 'badge-warning',
                low: 'badge-neutral',
            }[label] ?? 'badge-neutral';
        },
        scoreLabelText(label) {
            return { high: 'Potensi Tinggi', medium: 'Potensi Sedang', low: 'Potensi Rendah' }[label] ?? '';
        },
        openIdea(index) {
            this.selectedIndex = index;
            this.editPillar = this.ideas[index].pillar ?? (this.pillarOptions[0] ?? '');
            this.regenError = '';
        },
        closeIdea() {
            if (this.regenerating) return;
            this.selectedIndex = null;
        },
        regenerateIdea() {
            if (this.regenerating || this.selectedIndex === null || isApplied) return;

            const index = this.selectedIndex;
            this.regenerating = true;
            this.regenError = '';

            fetch(`/analytics/ai-strategy/${insightId}/ideas/${index}/regenerate`, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                    'Accept': 'application/json',
                },
                body: JSON.stringify({ pillar: this.editPillar }),
            })
            .then(async (res) => {
                const data = await res.json();
                if (!res.ok) throw new Error(data.error || 'Gagal regenerate ide');
                return data;
            })
            .then((data) => {
                this.ideas[index] = data.idea;
            })
            .catch((err) => {
                this.regenError = err.message;
            })
            .finally(() => this.regenerating = false);
        },

        send() {
            if (!this.draft.trim() || this.sending) return;

            const userMsg = this.draft.trim();
            this.messages.push({ role: 'user', message: userMsg, time: new Date().toLocaleTimeString('id-ID', { hour: '2-digit', minute: '2-digit' }) });
            this.draft = '';
            this.sending = true;
            this.errorMsg = '';
            this.$nextTick(() => this.$refs.messageList.scrollTop = this.$refs.messageList.scrollHeight);

            fetch(`/analytics/ai-strategy/${insightId}/chat`, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                    'Accept': 'application/json',
                },
                body: JSON.stringify({ message: userMsg }),
            })
            .then(async (res) => {
                const data = await res.json();
                if (!res.ok) throw new Error(data.error || 'Gagal kirim pesan');
                return data;
            })
            .then((data) => {
                this.messages.push({ role: 'assistant', message: data.message, time: data.created_at });
                this.$nextTick(() => this.$refs.messageList.scrollTop = this.$refs.messageList.scrollHeight);
            })
            .catch((err) => {
                this.errorMsg = err.message;
                this.messages.pop(); // balikin state kalau gagal, biar user bisa coba lagi
            })
            .finally(() => this.sending = false);
        },
    }
}
</script>
@endif

{{-- Phase 4.2 (Langkah 1) - script cuma di-render buat user yang MEMANG
     bisa dispatch sync (analytics-sync-button juga cuma di-render kalau
     $canSync - lihat blok header di atas). Bukan cuma defense-in-depth
     kosmetik: browser view-only user jadi tidak diam-diam nge-poll
     endpoint yang tidak pernah relevan buat dia. --}}
@if ($selectedClientId && $canSync)
<script>
    // Phase 4 - "Sinkronkan Data" global di halaman Performa. MIRROR pola
    // polling TikTok yang sudah dites di client-management.show (lihat
    // ClientManagementController::tiktokSyncStatus()) - vanilla JS,
    // getElementById, fetch+setInterval, reload sekali di status akhir -
    // BUKAN Alpine, biar konsisten dengan precedent yang sudah ada. Beda
    // dari form TikTok: dispatch di sini murni fetch() POST (bukan form
    // submit+redirect), biar user lihat progress TANPA navigasi apapun
    // sampai status akhir (Langkah 12/13).
    (function () {
        var clientId = {{ (int) $selectedClientId }};
        var platformId = {{ $selectedPlatformId ? (int) $selectedPlatformId : 'null' }};
        var dispatchUrl = @json(route('analytics.sync'));
        var statusUrl = @json(route('analytics.sync-status'));
        var reconnectUrl = @json(route('client-management.show', $selectedClientId));
        var csrfToken = document.querySelector('meta[name="csrf-token"]').content;

        var button = document.getElementById('analytics-sync-button');
        var icon = document.getElementById('analytics-sync-icon');
        var label = document.getElementById('analytics-sync-button-label');
        var message = document.getElementById('analytics-sync-message');
        var freshness = document.getElementById('analytics-freshness');
        var subjobsBox = document.getElementById('analytics-sync-subjobs');
        if (! button) return;

        var pollTimer = null;
        // Phase 4.1 (Langkah 3) - PERBAIKAN bug serius: dulu SETIAP poll
        // (termasuk yang pasif saat halaman baru dibuka) yang menemukan
        // status terminal (success/partial/failed) langsung reload halaman
        // - kalau client itu PERNAH punya sync sukses/gagal kapan saja
        // sebelumnya (kasus normal di production), buka halaman ini akan
        // reload TERUS MENERUS selamanya (poll -> lihat status lama ->
        // reload -> poll lagi -> lihat status lama lagi -> reload lagi...).
        // isTracking = true HANYA kalau kita BENAR-BENAR sedang melacak
        // satu siklus operasi yang nyata (baru kita dispatch sendiri, ATAU
        // ketemu SEDANG berjalan pas halaman dibuka/dari tab lain) - status
        // terminal yang ditemukan TANPA sedang tracking berarti itu cuma
        // last_result HISTORIS, ditampilkan sebagai info APA ADANYA, TIDAK
        // PERNAH memicu reload.
        var isTracking = false;
        var consecutivePollFailures = 0;
        var MAX_POLL_FAILURES = 3;

        // current_state (queued/running) dipakai SAAT sedang tracking -
        // "baru saja selesai". last_result (dari histori, BUKAN operasi
        // aktif) dipakai saat TIDAK tracking - dilabeli eksplisit
        // "Sinkronisasi terakhir: ..." biar tidak disalahartikan sebagai
        // operasi yang baru saja terjadi (Langkah 3).
        var currentStateMessages = {
            queued: 'Sinkronisasi sedang antre...',
            running: 'Sedang mengambil data...',
            partial: 'Sinkronisasi selesai sebagian.',
            success: 'Data berhasil disinkronkan.',
            failed: 'Sinkronisasi gagal.',
        };
        var lastResultMessages = {
            success: 'Sinkronisasi terakhir: berhasil.',
            partial: 'Sinkronisasi terakhir: selesai sebagian.',
            failed: 'Sinkronisasi terakhir: gagal.',
            needs_reconnect: 'Ada koneksi yang butuh dihubungkan ulang.',
            not_connected: 'Belum ada platform yang terhubung untuk client ini.',
            idle: '',
        };

        var subjobLabels = {
            instagram_content: 'Instagram Content',
            instagram_audience: 'Instagram Audience',
            tiktok_content: 'TikTok Content',
        };
        var subjobIcon = {
            queued: '•', running: '⟳', partial: '◐', success: '✓', failed: '✗',
            needs_reconnect: '⚠', not_connected: '—', idle: '—', manual_data: '✎',
        };

        function query(params) {
            return Object.keys(params)
                .filter(function (k) { return params[k] !== null && params[k] !== undefined; })
                .map(function (k) { return encodeURIComponent(k) + '=' + encodeURIComponent(params[k]); })
                .join('&');
        }

        function isBusy(status) {
            return status === 'queued' || status === 'running';
        }

        function renderSubjobs(subjobs) {
            if (! subjobsBox || ! subjobs) return;

            var keys = Object.keys(subjobs);
            // Cuma tampilkan detail per-subjob kalau memang lebih dari 1
            // subjob relevan (All Platforms) ATAU subjob-nya bukan status
            // sepenuhnya "diam" (Langkah 12, "tidak perlu modal besar kalau
            // inline feedback cukup").
            if (keys.length <= 1) { subjobsBox.hidden = true; subjobsBox.innerHTML = ''; return; }

            subjobsBox.innerHTML = keys.map(function (key) {
                var s = subjobs[key];
                var name = subjobLabels[key] || key;
                var icon = subjobIcon[s.status] || '•';
                return '<span class="text-[11px] text-[var(--text-muted)]">' + icon + ' ' + name + ' - ' + (s.message || s.status) + '</span>';
            }).join('');
            subjobsBox.hidden = false;
        }

        // Langkah 4 - single platform + needs_reconnect: JANGAN tampilkan
        // tombol sync normal yang lalu tidak melakukan apa-apa. Ganti jadi
        // tombol "Hubungkan Ulang" yang mengarahkan ke Client Detail
        // (tempat flow connect/reconnect yang SUDAH ADA), bukan dispatch.
        // "All Platforms" TIDAK masuk sini (overall_status TIDAK PERNAH
        // needs_reconnect kalau masih ada subjob lain yang aktif/berhasil
        // - lihat AnalyticsSyncOrchestrator::computeOverallStatus(), yang
        // itu jadi 'partial' - tombol tetap dispatch normal, subjob detail
        // di bawah yang menunjukkan platform mana yang butuh reconnect).
        function applyNeedsReconnectButtonState() {
            button.onclick = function () { window.location.href = reconnectUrl; };
            if (icon) { icon.classList.remove('animate-spin'); icon.textContent = 'link_off'; }
            if (label) label.textContent = 'Hubungkan Ulang';
            button.disabled = false;
        }

        function applyNormalButtonState(busy) {
            button.onclick = dispatchSync;
            if (icon) { icon.classList.toggle('animate-spin', busy); icon.textContent = 'sync'; }
            if (label) label.textContent = busy ? 'Mengantre...' : 'Sinkronkan Data';
            button.disabled = busy;
        }

        function applyStatus(data) {
            var busy = isBusy(data.overall_status);

            if (busy) {
                // Operasi SEDANG berjalan - entah baru kita trigger, atau
                // ketemu sudah jalan (tab/sesi lain) pas halaman dibuka -
                // WAJIB dilacak sampai selesai.
                isTracking = true;
            }

            if (message) {
                var text = busy || isTracking
                    ? (currentStateMessages[data.overall_status] || '')
                    : (lastResultMessages[data.overall_status] || '');
                message.textContent = text;
                message.hidden = ! text;
            }

            if (freshness) {
                if (data.last_observation_at) {
                    // Langkah 8 - "Data performa terakhir diamati" (BUKAN
                    // "semua data terakhir disinkronkan") - Instagram
                    // Audience punya pipeline TERPISAH (AudienceInsight,
                    // bukan content_metric_snapshots), jadi timestamp ini
                    // TIDAK mencerminkan freshness audience. Coverage
                    // (banner terpisah) tetap yang menjawab "apakah
                    // periode ini lengkap" - dua hal beda, jangan dicampur.
                    freshness.textContent = 'Data performa terakhir diamati: ' + new Date(data.last_observation_at).toLocaleString('id-ID', { dateStyle: 'medium', timeStyle: 'short' });
                    freshness.hidden = false;
                } else {
                    freshness.hidden = true;
                }
            }

            renderSubjobs(data.subjobs);

            if (! busy && data.overall_status === 'needs_reconnect') {
                applyNeedsReconnectButtonState();
            } else {
                applyNormalButtonState(busy);
            }

            if (! busy) {
                stopPolling();

                // Langkah 13 - reload SEKALI, TAPI CUMA kalau kita memang
                // sedang melacak satu siklus operasi nyata (isTracking) -
                // last_result historis yang ditemukan TANPA tracking aktif
                // TIDAK PERNAH memicu reload (Langkah 3 - stale log tidak
                // boleh menang atas current dispatch lifecycle).
                // window.location.reload() otomatis preserve SEMUA query
                // string yang lagi aktif.
                if (isTracking && (data.overall_status === 'success' || data.overall_status === 'partial' || data.overall_status === 'failed')) {
                    setTimeout(function () { window.location.reload(); }, 900);
                }
                isTracking = false;
            }
        }

        function showSafeError(text) {
            if (message) { message.textContent = text; message.hidden = false; }
        }

        // Langkah 6 - status endpoint error (401/403/419/500/network
        // timeout/malformed response) TIDAK BOLEH bikin polling jalan
        // selamanya tanpa feedback DAN tombol tidak boleh nyangkut
        // disabled permanen. Session expiry (401/419) berhenti SEKETIKA -
        // itu bukan masalah transient. Error lain (500/network/malformed
        // JSON) dikasih toleransi beberapa kali gagal berturut-turut dulu
        // (bisa cuma blip jaringan sesaat) sebelum berhenti - TIDAK PERNAH
        // menampilkan raw stack trace/pesan API/token, cuma pesan aman.
        function poll() {
            fetch(statusUrl + '?' + query({ client_id: clientId, platform_id: platformId }), { headers: { Accept: 'application/json' } })
                .then(function (res) {
                    if (res.status === 401 || res.status === 419) {
                        throw { safeStop: true, message: 'Sesi Anda berakhir. Muat ulang halaman dan login kembali untuk melanjutkan.' };
                    }
                    if (! res.ok) {
                        throw { safeStop: false };
                    }
                    return res.json();
                })
                .then(function (data) {
                    consecutivePollFailures = 0;
                    applyStatus(data);
                })
                .catch(function (err) {
                    if (err && err.safeStop) {
                        stopPolling();
                        isTracking = false;
                        showSafeError(err.message || 'Terjadi kesalahan. Muat ulang halaman.');
                        applyNormalButtonState(false);
                        return;
                    }

                    consecutivePollFailures++;
                    if (consecutivePollFailures >= MAX_POLL_FAILURES) {
                        stopPolling();
                        isTracking = false;
                        showSafeError('Gagal memuat status sinkronisasi. Coba muat ulang halaman.');
                        applyNormalButtonState(false);
                        return;
                    }
                    // < MAX_POLL_FAILURES - diamkan, kemungkinan cuma blip jaringan sesaat, coba lagi di poll berikutnya.
                });
        }

        function startPolling() {
            if (pollTimer) return;
            poll();
            pollTimer = setInterval(poll, 2500);
        }

        function stopPolling() {
            if (pollTimer) { clearInterval(pollTimer); pollTimer = null; }
        }

        function dispatchSync() {
            isTracking = true;
            applyNormalButtonState(true);

            fetch(dispatchUrl, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': csrfToken,
                    'Accept': 'application/json',
                },
                body: JSON.stringify({ client_id: clientId, platform_id: platformId }),
            })
                .then(function (res) { return res.json().then(function (body) { return { ok: res.ok, body: body }; }); })
                .then(function (result) {
                    if (! result.ok) {
                        isTracking = false;
                        showSafeError(result.body.message || 'Sinkronisasi gagal dimulai.');
                        applyNormalButtonState(false);
                        return;
                    }
                    startPolling();
                })
                .catch(function () {
                    isTracking = false;
                    showSafeError('Sinkronisasi gagal dimulai.');
                    applyNormalButtonState(false);
                });
        }

        applyNormalButtonState(false);
        button.onclick = dispatchSync;

        // Ambil status begitu halaman dibuka (bukan cuma setelah klik) -
        // biar freshness indicator & (kalau kebetulan ada sync yang masih
        // berjalan dari klik sebelumnya/tab lain) badge langsung akurat
        // tanpa perlu klik dulu. isTracking TETAP false di titik ini -
        // kalau hasilnya status busy, applyStatus() sendiri yang akan
        // menyalakan tracking; kalau hasilnya terminal, itu last_result
        // historis APA ADANYA, TIDAK memicu reload (Langkah 3).
        poll();
    })();
</script>
@endif

@endsection
