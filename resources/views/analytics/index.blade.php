@extends('layouts.app')
@section('title', 'Performa')
@section('content')

<div class="p-4 sm:p-6 lg:p-8 max-w-[1400px] mx-auto">

    {{-- Header --}}
    <div class="mb-6">
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

    {{-- PASS 3 (Langkah B, "SIMPLIFY TAB LANGUAGE") - nama tab user-facing
         disederhanakan (Ringkasan/Konten/Audiens), TAPI $t['key'] (overview/
         table/audience) TETAP SAMA PERSIS - itu tab= query value & route
         contract yang sudah dites banyak tempat, mengubahnya cuma
         menambah risiko tanpa manfaat user-facing apapun. --}}
    @php
        $tabHref = fn (string $tab) => route('analytics', array_filter(array_merge([
            'tab' => $tab,
            'client_id' => $selectedClientId,
            'platform_id' => $selectedPlatformId ?? null,
        ], $period->toQueryParams())));
        $tabs = [
            ['key' => 'overview', 'label' => 'Ringkasan', 'icon' => 'monitoring'],
            ['key' => 'table', 'label' => 'Konten', 'icon' => 'table_rows'],
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
    <form method="GET" class="card p-4 mb-6 flex items-center gap-3 flex-wrap"
          x-data="{ mode: '{{ $period->mode === \App\Services\AnalyticsPeriod::MODE_MONTH ? 'month' : 'custom' }}' }">
        <input type="hidden" name="tab" value="{{ $activeTab }}">

        <select name="client_id" onchange="this.form.submit()"
                class="text-sm border border-[var(--border)] rounded-lg px-3.5 py-2 bg-[var(--surface-card)] focus:outline-none focus:ring-2 focus:ring-[#044b46]/15 focus:border-[#044b46]/40 transition-shadow">
            <option value="">Pilih Klien...</option>
            @foreach ($clientOptions as $clientOption)
                <option value="{{ $clientOption->id }}" {{ (string) $selectedClientId === (string) $clientOption->id ? 'selected' : '' }}>{{ $clientOption->name }}</option>
            @endforeach
        </select>

        {{-- PASS 2 - period 7/30/90 diganti Bulan Kalender / Rentang Kustom
             (Langkah "PRIMARY PRODUCT CHANGE") - keduanya submit lewat
             AnalyticsPeriodResolver::resolveWithError() query-string
             contract (period_mode=month&month=YYYY-MM ATAU
             period_mode=custom&date_from=..&date_to=..), SATU-SATUNYA
             sumber resmi (Langkah "URL/QUERY-STRING CONTRACT"). --}}
        <select name="period_mode" x-model="mode" onchange="this.form.submit()"
                class="text-sm border border-[var(--border)] rounded-lg px-3.5 py-2 bg-[var(--surface-card)] focus:outline-none focus:ring-2 focus:ring-[#044b46]/15 focus:border-[#044b46]/40 transition-shadow">
            <option value="month">Bulan Kalender</option>
            <option value="custom">Rentang Tanggal</option>
        </select>

        <input type="month" name="month" x-show="mode === 'month'"
               value="{{ $period->mode === \App\Services\AnalyticsPeriod::MODE_MONTH ? $period->month : now()->format('Y-m') }}"
               max="{{ now()->format('Y-m') }}" onchange="this.form.submit()"
               class="text-sm border border-[var(--border)] rounded-lg px-3.5 py-2 bg-[var(--surface-card)] focus:outline-none focus:ring-2 focus:ring-[#044b46]/15 focus:border-[#044b46]/40 transition-shadow">

        <div class="flex items-center gap-1.5" x-show="mode === 'custom'">
            <input type="date" name="date_from"
                   value="{{ $period->mode !== \App\Services\AnalyticsPeriod::MODE_MONTH ? $period->dateFrom->toDateString() : '' }}"
                   max="{{ now()->toDateString() }}" onchange="this.form.submit()"
                   class="text-sm border border-[var(--border)] rounded-lg px-3 py-2 bg-[var(--surface-card)] focus:outline-none focus:ring-2 focus:ring-[#044b46]/15 focus:border-[#044b46]/40 transition-shadow">
            <span class="text-xs text-[var(--text-muted)]">–</span>
            <input type="date" name="date_to"
                   value="{{ $period->mode !== \App\Services\AnalyticsPeriod::MODE_MONTH ? $period->dateTo->toDateString() : '' }}"
                   max="{{ now()->toDateString() }}" onchange="this.form.submit()"
                   class="text-sm border border-[var(--border)] rounded-lg px-3 py-2 bg-[var(--surface-card)] focus:outline-none focus:ring-2 focus:ring-[#044b46]/15 focus:border-[#044b46]/40 transition-shadow">
        </div>

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

    {{-- PASS 3 (Langkah A, "current period state visually clear without
         clutter") - sinyal kecil "Data melalui 2 Sep 2026" HANYA muncul
         kalau periode yang dipilih genuinely partial (bulan berjalan/
         rentang custom yang belum selesai) - AnalyticsPeriod::
         effectiveThroughLabel() SUDAH null buat periode yang sudah
         genuinely lengkap, tidak perlu logic tambahan di sini. --}}
    @if (! empty($period) && $period->effectiveThroughLabel())
        <p class="text-[12px] text-[var(--text-muted)] -mt-3 mb-3">{{ $period->effectiveThroughLabel() }}</p>
    @endif

    {{-- PASS 3 (Langkah A/C/O) - SATU baris ringkas: status data di kiri
         (freshness/"Data melalui..." - JS mengisi begitu status sync
         diketahui), SATU tombol aksi utama "Perbarui Data" + Ekspor di
         kanan. Detail progress per-platform (Langkah D) muncul di panel
         TERPISAH di bawah baris ini, HANYA saat relevan - bukan
         menggantung permanen di header. --}}
    <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-3 mb-3">
        <div class="min-w-0">
            @if ($selectedClientId)
                <p class="text-[13px] text-[var(--text-secondary)]" id="analytics-freshness">Memuat status data...</p>
            @else
                <p class="text-[13px] text-[var(--text-muted)]">Pilih client untuk melihat status data.</p>
            @endif
        </div>

        <div class="flex items-center gap-2 shrink-0">
            @if ($canSync)
                <button type="button" id="analytics-sync-button"
                        title="Memperbarui data mengambil hasil terbaru dari Instagram/TikTok. Filter periode di atas hanya mengatur tampilan, bukan rentang yang diperbarui."
                        class="btn-secondary" {{ $selectedClientId ? '' : 'disabled' }}>
                    <span class="material-symbols-outlined text-[17px]" id="analytics-sync-icon">sync</span>
                    <span id="analytics-sync-button-label">Perbarui Data</span>
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
                <a href="{{ route('analytics.export', array_filter(array_merge(['client_id' => $selectedClientId, 'platform_id' => $selectedPlatformId ?? null], $period->toQueryParams()))) }}"
                   class="btn-primary">
                    <span class="material-symbols-outlined text-[17px]">download</span> Ekspor Performa
                </a>
            @endif
        </div>
    </div>

    @if ($canSync && ! $selectedClientId)
        <p class="text-[12px] text-[var(--text-muted)] mb-6">Pilih client untuk menyinkronkan data.</p>
    @endif

    {{-- PASS 3 (Langkah D/F/G/H/I) - panel progress/hasil sync, dirender
         SEPENUHNYA oleh JS (renderSyncPanel()) - kosong & hidden di awal,
         cuma muncul saat memang ada sesuatu buat ditampilkan (sedang
         berjalan, baru selesai, atau butuh retry/reconnect). "Lihat
         detail" di dalamnya progressive disclosure, BUKAN blok teknis
         permanen. --}}
    @if ($canSync && $selectedClientId)
        <div id="analytics-sync-panel" class="mb-6" hidden></div>
    @endif

    {{-- PASS 3 (Langkah N, "SYNC HISTORY") - SECONDARY, kolaps default,
         bukan bagian dari hierarki visual utama - server-rendered (ini
         histori, bukan operasi aktif yang perlu di-poll). --}}
    @if ($canSync && $selectedClientId && ! empty($syncHistory) && count($syncHistory))
        <details class="mb-6">
            <summary class="text-xs font-medium text-[var(--text-muted)] cursor-pointer select-none">Riwayat pembaruan</summary>
            <div class="card p-4 mt-2 divide-y divide-[var(--surface-muted)]">
                @foreach ($syncHistory as $entry)
                    <div class="py-2.5 first:pt-0 last:pb-0 flex items-center justify-between gap-3 flex-wrap">
                        <div>
                            <p class="text-xs font-medium text-[var(--text-primary)]">{{ $entry['started_at']?->translatedFormat('j M H:i') }} · {{ $entry['platforms_label'] }}</p>
                            <p class="text-[11px] text-[var(--text-muted)]">{{ $entry['status_label'] }}{{ $entry['counts_label'] ? ' · '.$entry['counts_label'] : '' }}</p>
                        </div>
                        @if ($entry['duration_label'])
                            <span class="text-[11px] text-[var(--text-muted)] shrink-0">{{ $entry['duration_label'] }}</span>
                        @endif
                    </div>
                @endforeach
            </div>
        </details>
    @endif

    @if (! empty($periodError))
        <div class="card p-4 mb-6 flex items-start gap-3" style="background: var(--danger-tint); border-color: var(--danger-text);">
            <span class="material-symbols-outlined text-[var(--danger-text)] text-[20px]">error</span>
            <p class="text-[13px] text-[var(--danger-text)]">{{ $periodError }} Menampilkan bulan berjalan.</p>
        </div>
    @endif

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
                                <p class="text-xs text-[var(--text-secondary)] mt-0.5">Analisis performa {{ $aiAnalysisPeriodLabel }}, dibangkitkan Gemini AI dari data asli client ini</p>
                            </div>
                        </div>

                        <div class="flex items-center gap-3 shrink-0 flex-wrap">
                            {{-- Phase 4.1 (v2) - Bulan Analisis KHUSUS AI Strategy,
                                 TERPISAH dari filter period 7/30/90 Overview/Table/
                                 Audience - ganti bulan langsung reload buat lihat
                                 histori bulan itu (kalau ada), tanpa perlu generate
                                 ulang. max=bulan berjalan - retrospective analysis,
                                 bukan proyeksi ke masa depan. --}}
                            <form method="GET" class="shrink-0">
                                <input type="hidden" name="client_id" value="{{ $selectedClientId }}">
                                <input type="hidden" name="tab" value="overview">
                                @foreach ($period->toQueryParams() as $key => $value)
                                    <input type="hidden" name="{{ $key }}" value="{{ $value }}">
                                @endforeach
                                @if ($selectedPlatformId)
                                    <input type="hidden" name="platform_id" value="{{ $selectedPlatformId }}">
                                @endif
                                <label for="ai-analysis-month" class="sr-only">Bulan Analisis</label>
                                <input type="month" id="ai-analysis-month" name="analysis_month" value="{{ $analysisMonth }}"
                                       max="{{ now()->format('Y-m') }}" onchange="this.form.submit()"
                                       class="text-xs border border-[var(--border)] rounded-lg px-2.5 py-2 bg-[var(--surface-card)] focus:outline-none focus:border-[#044b46]/40">
                            </form>
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
                            {{-- Bulan Analisis + platform yang lagi dipilih di atas -
                                 lihat AnalyticsController::generateAiStrategy(). --}}
                            <input type="hidden" name="analysis_month" value="{{ $analysisMonth }}">
                            <input type="hidden" name="platform_id" value="{{ $selectedPlatformId }}">
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
                                    Klik "Generate Analisis" di atas — AI bakal baca performa {{ $aiAnalysisPeriodLabel }} dan kasih rekomendasi strategi konkret.
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
                        <div x-data="aiChat({{ $latestAiInsight->id }}, {{ Js::from($latestAiInsight->messages->map(fn($m) => ['role' => $m->role, 'message' => $m->message, 'time' => $m->created_at->format('H:i')])) }}, {{ Js::from($latestAiInsight->content_ideas ?? []) }}, {{ Js::from($pillarOptionsForRegenerate) }}, {{ $latestAiInsight->applied_at ? 'true' : 'false' }}, {{ Js::from($latestAiInsight->applied_idea_indexes ?? []) }}, {{ Js::from($emptySlots->map(fn ($s) => ['id' => $s->id, 'label' => ($s->provisional_code ?: '#'.$s->id) . ($s->title && $s->title !== $s->provisional_code ? ' · '.$s->title : '')])) }})">

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
                            @php
                                // Phase 4.1 (Langkah 12) - coverage_status dibawa
                                // di performance_data (disimpan APA ADANYA dari
                                // PeriodPerformanceService saat insight ini
                                // digenerate) - insight lama (pre-Phase-4.1) tidak
                                // punya field ini sama sekali, @php ?? null aman.
                                $aiCoverageStatus = $latestAiInsight->performance_data['coverage_status'] ?? null;
                                $aiCoverageFrom = $latestAiInsight->performance_data['coverage_from'] ?? null;
                            @endphp
                            @if ($aiCoverageStatus && $aiCoverageStatus !== 'full')
                                <div class="bg-[var(--warning-tint)] border border-[var(--warning-text)] rounded-lg p-3.5 mb-4 flex items-start gap-2.5">
                                    <span class="material-symbols-outlined text-[var(--warning-text)] text-[17px] shrink-0">info</span>
                                    <p class="text-xs text-[var(--warning-text)] leading-relaxed">
                                        @if ($aiCoverageStatus === 'unavailable')
                                            Belum ada data performa yang teramati untuk periode ini - analisis di bawah bersifat umum, belum berdasarkan angka performa spesifik.
                                        @else
                                            Data historis untuk periode ini belum lengkap{{ $aiCoverageFrom ? ' - baru teramati sejak '.\Illuminate\Support\Carbon::parse($aiCoverageFrom)->translatedFormat('d M Y') : '' }}. Analisis di bawah didasarkan pada data yang benar-benar teramati, bukan periode penuh.
                                        @endif
                                    </p>
                                </div>
                            @endif
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
                                <p class="text-xs text-[var(--text-muted)] bg-[var(--surface-page)] rounded-lg px-3.5 py-2.5 mb-4 flex items-start gap-2">
                                    <span class="material-symbols-outlined text-[15px] shrink-0 mt-0.5">info</span>
                                    <span>Slot content plan sudah digenerate otomatis dari kuota paket - klik satu ide di bawah untuk pilih slot mana yang mau diisi ide itu satu per satu, atau terapkan semuanya sekaligus di bawah ini.</span>
                                </p>
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

                                        <template x-if="isIdeaApplied">
                                            <p class="text-xs text-[var(--success-text)] bg-[var(--success-tint)] rounded-lg px-3.5 py-2.5 leading-relaxed mb-4">
                                                <span class="material-symbols-outlined text-[14px] align-middle">check_circle</span>
                                                Ide ini sudah diterapkan ke salah satu slot content plan.
                                            </p>
                                        </template>
                                        @if ($canManageAiStrategy)
                                            <template x-if="!isIdeaApplied">
                                                <div class="border-t border-[var(--surface-muted)] pt-4 mb-4">
                                                    <label class="block text-[11px] font-semibold text-[var(--text-muted)] uppercase tracking-wide mb-2">Terapkan ke Slot Ini</label>
                                                    <template x-if="emptySlots.length === 0">
                                                        <p class="text-xs text-[var(--text-muted)] mb-1">Tidak ada slot kosong tersedia - semua slot Content Plan bulan ini sudah terisi, atau client ini belum punya Content Plan aktif.</p>
                                                    </template>
                                                    <template x-if="emptySlots.length > 0">
                                                        <div class="flex items-center gap-2">
                                                            <select x-model="selectedSlotId" :disabled="applying"
                                                                    class="flex-1 text-sm border border-[var(--border)] rounded-lg px-3.5 py-2 bg-[var(--surface-card)] focus:outline-none focus:ring-2 focus:ring-[#044b46]/15 focus:border-[#044b46]/40 disabled:opacity-60">
                                                                <option value="">Pilih slot...</option>
                                                                <template x-for="slot in emptySlots" :key="slot.id">
                                                                    <option :value="slot.id" x-text="slot.label"></option>
                                                                </template>
                                                            </select>
                                                            <button type="button" x-on:click="applyIdea()" :disabled="applying || !selectedSlotId"
                                                                    class="btn-primary whitespace-nowrap disabled:opacity-60">
                                                                <span x-show="!applying">Terapkan</span>
                                                                <span x-show="applying" x-cloak>...</span>
                                                            </button>
                                                        </div>
                                                    </template>
                                                    <p x-show="applyError" x-cloak class="text-xs text-[var(--danger-text)] mt-2" x-text="applyError"></p>
                                                </div>
                                            </template>
                                        @endif

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
                @php
                    // PASS 3 (Langkah L) - "Data melalui X"/"N hari data
                    // tersedia" HANYA ditampilkan kalau memang relevan
                    // (bulan berjalan/rentang masih partial ATAU ada gap
                    // genuine di tengah) - jangan tempel di setiap chart
                    // tanpa pandang bulu (Langkah Q, "no alert overload").
                    $trendObservedDays = collect($trend)->filter(fn ($p) => $p['value'] !== null)->count();
                    $trendTotalDays = collect($trend)->count();
                @endphp
                <div class="card p-6">
                    <div class="flex items-center gap-2 mb-1">
                        <span class="material-symbols-outlined text-[var(--text-muted)] text-[18px]">show_chart</span>
                        <h2 class="font-display text-lg font-semibold text-[var(--text-primary)]">Views dari Waktu ke Waktu</h2>
                    </div>
                    <p class="text-xs text-[var(--text-muted)] mb-1 ml-[26px]">Total views seluruh konten pada periode terpilih.</p>
                    @if ($period->effectiveThroughLabel() || ($trendTotalDays > 0 && $trendObservedDays < $trendTotalDays))
                        <p class="text-[11px] text-[var(--text-muted)] mb-4 ml-[26px]">
                            {{ $period->effectiveThroughLabel() ?? ($trendObservedDays.' dari '.$trendTotalDays.' hari data tersedia') }}
                        </p>
                    @else
                        <div class="mb-4"></div>
                    @endif
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
function aiChat(insightId, initialMessages, initialIdeas, pillarOptions, isApplied, appliedIdeaIndexes, emptySlots) {
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
        // ===== Terapkan ide ini ke satu slot kosong =====
        appliedIdeaIndexes: appliedIdeaIndexes,
        emptySlots: emptySlots,
        selectedSlotId: '',
        applying: false,
        applyError: '',
        get selectedIdea() {
            return this.selectedIndex !== null ? this.ideas[this.selectedIndex] : null;
        },
        get isIdeaApplied() {
            return this.selectedIndex !== null && this.appliedIdeaIndexes.includes(this.selectedIndex);
        },
        applyIdea() {
            if (this.applying || this.selectedIndex === null || !this.selectedSlotId) return;

            const index = this.selectedIndex;
            this.applying = true;
            this.applyError = '';

            fetch(`/analytics/ai-strategy/${insightId}/ideas/${index}/apply`, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                    'Accept': 'application/json',
                },
                body: JSON.stringify({ content_item_id: this.selectedSlotId }),
            })
            .then(async (res) => {
                if (!res.ok) {
                    const data = await res.json().catch(() => ({}));
                    throw new Error(data.message || 'Gagal menerapkan ide ke slot ini.');
                }
                return true;
            })
            .then(() => {
                this.appliedIdeaIndexes.push(index);
                this.emptySlots = this.emptySlots.filter(s => String(s.id) !== String(this.selectedSlotId));
                this.selectedSlotId = '';
                window.location.reload();
            })
            .catch((err) => {
                this.applyError = err.message;
            })
            .finally(() => this.applying = false);
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

{{-- Phase 4.2 (Langkah 1) / PASS 3 - script cuma di-render buat user yang
     MEMANG bisa dispatch sync (analytics-sync-button juga cuma di-render
     kalau $canSync - lihat blok header di atas). Bukan cuma defense-in-
     depth kosmetik: browser view-only user jadi tidak diam-diam nge-poll
     endpoint yang tidak pernah relevan buat dia. --}}
@if ($selectedClientId && $canSync)
<script>
    // Phase 4 - "Perbarui Data" global di halaman Performa. Arsitektur
    // polling (fetch+setInterval, reload sekali di status akhir, rediscovery
    // di page-load, server-side duplicate-protection) TIDAK berubah dari
    // Phase 4/4.1 - itu yang bikin refresh browser/pindah tab TIDAK PERNAH
    // membatalkan atau menduplikasi sync (PASS 3 Langkah E). Yang berubah
    // PASS 3: rendering-nya SEKARANG pakai data.progress (Pass 1 structured
    // progress - discovered/processed/stage/timestamps genuine per subjob,
    // Langkah D) sebagai sumber utama tampilan, data.subjobs (legacy) tetap
    // dipakai buat state machine (not_connected/needs_reconnect/manual_data/
    // stale-detection yang sudah teruji), BUKAN diganti - dua-duanya
    // dikombinasikan, bukan salah satu dibuang.
    (function () {
        var clientId = {{ (int) $selectedClientId }};
        var platformId = {{ $selectedPlatformId ? (int) $selectedPlatformId : 'null' }};
        var dispatchUrl = @json(route('analytics.sync'));
        var statusUrl = @json(route('analytics.sync-status'));
        var retryTaskUrl = @json(route('analytics.sync.retry-task'));
        var retryFailedItemsUrl = @json(route('analytics.sync.retry-failed-items'));
        var reconnectUrl = @json(route('client-management.show', $selectedClientId));
        var csrfToken = document.querySelector('meta[name="csrf-token"]').content;

        var button = document.getElementById('analytics-sync-button');
        var icon = document.getElementById('analytics-sync-icon');
        var label = document.getElementById('analytics-sync-button-label');
        var freshness = document.getElementById('analytics-freshness');
        var panel = document.getElementById('analytics-sync-panel');
        if (! button) return;

        var pollTimer = null;
        // Phase 4.1 (Langkah 3) - PERBAIKAN bug serius: dulu SETIAP poll
        // (termasuk yang pasif saat halaman baru dibuka) yang menemukan
        // status terminal (success/partial/failed) langsung reload halaman
        // - kalau client itu PERNAH punya sync sukses/gagal kapan saja
        // sebelumnya (kasus normal di production), buka halaman ini akan
        // reload TERUS MENERUS selamanya. isTracking = true HANYA kalau
        // kita BENAR-BENAR sedang melacak satu siklus operasi yang nyata
        // (baru kita dispatch sendiri, ATAU ketemu SEDANG berjalan pas
        // halaman dibuka/dari tab/scheduled sync lain - PASS 3 Langkah E,
        // "jika ada scheduled sync aktif, tampilkan progress-nya, jangan
        // bikin manual run baru yang bersaing") - status terminal yang
        // ditemukan TANPA sedang tracking berarti itu cuma last_result
        // HISTORIS, ditampilkan sebagai info APA ADANYA, TIDAK PERNAH
        // memicu reload.
        var isTracking = false;
        var consecutivePollFailures = 0;
        var MAX_POLL_FAILURES = 3;

        function isBusy(status) {
            return status === 'queued' || status === 'running';
        }

        // ===== PASS 3 (Langkah D) - kosakata tampilan, TIDAK ADA angka
        // dikarang di sini - semua label murni MEMETAKAN stage/status
        // BACKEND YANG SUDAH ADA (lihat InstagramAnalyticsSyncService::
        // markRunning()/recordDiscovered() dkk) ke bahasa Indonesia, bukan
        // logic baru. =====
        var PLATFORM_GROUPS = [
            { key: 'instagram', label: 'Instagram', primary: 'instagram_content', unit: 'konten', secondary: ['instagram_audience'] },
            { key: 'tiktok', label: 'TikTok', primary: 'tiktok_content', unit: 'video', secondary: [] },
        ];
        var stageLabels = {
            discovering_media: 'Mengambil daftar konten...',
            fetching_insights: 'Memproses insight konten',
            refreshing_known_media: 'Memperbarui konten yang sudah tercatat',
            discovering_videos: 'Mengambil daftar video...',
            processing_videos: 'Memproses insight video',
            refreshing_known_videos: 'Memperbarui video yang sudah tercatat',
            fetching_audience_metrics: 'Mengambil data audiens',
        };
        var secondaryLabels = { instagram_audience: 'Audiens' };
        var lastResultMessages = {
            success: 'Data berhasil diperbarui.',
            partial: 'Pembaruan selesai sebagian.',
            failed: 'Pembaruan gagal.',
            needs_reconnect: 'Ada koneksi yang butuh dihubungkan ulang.',
            not_connected: 'Belum ada platform yang terhubung untuk client ini.',
            idle: '',
        };

        // PASS 3 (Langkah F) - ambang "terasa lebih lama dari biasanya"
        // SENGAJA jauh lebih pendek dari ambang backend "job dianggap mati"
        // (staleThresholdSecondsFor(), 360-660 detik) - ini cuma SOFT
        // warning ("masih hidup, cuma lambat"), backend tetap satu-satunya
        // yang berwenang bilang job benar-benar berhenti/gagal.
        var SLOW_PROGRESS_SECONDS = 45;

        function query(params) {
            return Object.keys(params)
                .filter(function (k) { return params[k] !== null && params[k] !== undefined; })
                .map(function (k) { return encodeURIComponent(k) + '=' + encodeURIComponent(params[k]); })
                .join('&');
        }

        function esc(text) {
            var div = document.createElement('div');
            div.textContent = text == null ? '' : String(text);
            return div.innerHTML;
        }

        function formatElapsed(startIso) {
            if (! startIso) return '';
            var totalSeconds = Math.max(0, Math.floor((Date.now() - new Date(startIso).getTime()) / 1000));
            var m = Math.floor(totalSeconds / 60);
            var s = totalSeconds % 60;
            return m > 0 ? (m + 'm ' + s + 'd') : (s + 'd');
        }

        function secondsSince(iso) {
            if (! iso) return null;
            return Math.max(0, Math.floor((Date.now() - new Date(iso).getTime()) / 1000));
        }

        // Langkah 4 - single platform + needs_reconnect: JANGAN tampilkan
        // tombol sync normal yang lalu tidak melakukan apa-apa. Ganti jadi
        // tombol "Hubungkan Ulang" yang mengarahkan ke Client Detail
        // (tempat flow connect/reconnect yang SUDAH ADA), bukan dispatch.
        function applyNeedsReconnectButtonState() {
            button.onclick = function () { window.location.href = reconnectUrl; };
            if (icon) { icon.classList.remove('animate-spin'); icon.textContent = 'link_off'; }
            if (label) label.textContent = 'Hubungkan Ulang';
            button.disabled = false;
        }

        // PASS 3 (Langkah C) - label tombol platform-aware SAAT busy:
        // "Memperbarui Instagram" kalau cuma 1 platform relevan, generik
        // "Memperbarui..." kalau All Platforms - user berpikir dalam
        // konteks platform, bukan istilah job/subjob internal.
        function busyButtonLabel(subjobs) {
            var keys = Object.keys(subjobs || {});
            if (keys.length === 1 && keys[0] === 'tiktok_content') return 'Memperbarui TikTok...';
            if (keys.length >= 1 && keys.every(function (k) { return k.indexOf('instagram') === 0; })) return 'Memperbarui Instagram...';
            return 'Memperbarui...';
        }

        function applyNormalButtonState(busy, subjobs) {
            button.onclick = dispatchSync;
            if (icon) { icon.classList.toggle('animate-spin', busy); icon.textContent = 'sync'; }
            if (label) label.textContent = busy ? busyButtonLabel(subjobs) : 'Perbarui Data';
            button.disabled = busy;
        }

        // ===== PASS 3 (Langkah G) - ringkasan hasil dari reconciliation
        // counts genuine (Pass 1), BUKAN sekadar "sukses"/"gagal" biner. =====
        function reconciliationLines(task, unit) {
            if (! task || ! task.discovered_count) return [];
            var lines = [];
            lines.push({ tone: 'success', text: task.success_count + ' dari ' + task.discovered_count + ' ' + unit + ' diperbarui' });
            if (task.unavailable_count > 0) {
                lines.push({ tone: 'muted', text: task.unavailable_count + ' ' + unit + ' tidak tersedia dari provider' });
            }
            if (task.skipped_count > 0) {
                lines.push({ tone: 'muted', text: task.skipped_count + ' ' + unit + ' dilewati' });
            }
            if (task.failed_count > 0) {
                lines.push({ tone: 'danger', text: task.failed_count + ' ' + unit + ' belum berhasil diperbarui' });
            }
            if (task.discovered_count > 0 && task.reconciled === false) {
                // Langkah G, "do not report clean success if reconciliation
                // is not clean" - selisih genuinely tidak diketahui alasannya.
                lines.push({ tone: 'muted', text: 'Sebagian hasil belum bisa dipastikan statusnya.' });
            }
            return lines;
        }

        function toneClass(tone) {
            if (tone === 'danger') return 'text-[var(--danger-text)]';
            if (tone === 'success') return 'text-[var(--success-text)]';
            return 'text-[var(--text-muted)]';
        }

        // ===== PASS 3 (Langkah I) - "Lihat detail" progressive disclosure,
        // checklist DIDERIVASI dari counts yang genuinely ada (bukan
        // mengarang langkah seperti "Profil diperbarui" yang tidak ada
        // sinyalnya di data) - kalau backend tidak punya sinyalnya, baris
        // itu TIDAK ditampilkan sama sekali (jujur lebih penting dari
        // lengkap). TIDAK PERNAH expose stack trace/token/header. =====
        function detailChecklist(task, unit) {
            if (! task) return [];
            var items = [];
            if (task.discovered_count > 0) {
                items.push({ ok: true, text: task.discovered_count + ' ' + unit + ' ditemukan' });
                items.push({ ok: task.processed_count >= task.discovered_count, text: task.processed_count + ' dari ' + task.discovered_count + ' ' + unit + ' diproses' });
            }
            if (task.finished_at && task.reconciled) {
                items.push({ ok: true, text: 'Data ' + unit + ' tersimpan' });
            }
            if (task.unavailable_count > 0) {
                items.push({ ok: null, text: task.unavailable_count + ' ' + unit + ' tidak tersedia dari provider (bukan kegagalan teknis)' });
            }
            if (task.failed_count > 0) {
                items.push({ ok: false, text: task.failed_count + ' ' + unit + ' gagal diperbarui' });
            }
            return items;
        }

        function checklistIcon(ok) {
            if (ok === true) return '<span class="material-symbols-outlined text-[15px] text-[var(--success-text)]">check_circle</span>';
            if (ok === false) return '<span class="material-symbols-outlined text-[15px] text-[var(--danger-text)]">warning</span>';
            return '<span class="material-symbols-outlined text-[15px] text-[var(--text-muted)]">info</span>';
        }

        // ===== PASS 3 (Langkah H) - targeted retry, HANYA menyasar scope
        // yang backend TAHU gagal (item-level buat subjob yang punya
        // AnalyticsSyncFailure retryable, task-level kalau seluruh subjob
        // gagal) - TIDAK PERNAH dispatch sync lengkap kalau backend sudah
        // tahu persis apa yang perlu dicoba ulang. =====
        function retryButtonHtml(subjobKey, task, groupLabel, unit) {
            if (! task || ! task.id || task.status !== 'failed' || ! task.failed_count) return '';

            var canItemRetry = subjobKey === 'instagram_content' || subjobKey === 'tiktok_content';
            var text = canItemRetry
                ? 'Coba lagi ' + task.failed_count + ' ' + unit
                : (subjobKey === 'instagram_audience' ? 'Coba lagi data Audiens' : 'Coba lagi ' + groupLabel);
            var action = canItemRetry ? 'retry-items' : 'retry-task';

            return '<button type="button" class="text-xs font-medium text-[var(--brand)] hover:underline analytics-retry-btn" '
                + 'data-task-id="' + task.id + '" data-action="' + action + '">' + esc(text) + '</button>';
        }

        // ===== Bangun 1 kartu platform (Langkah D/F/G/H/I) =====
        function renderGroup(group, subjobs, progressTasks) {
            var relevantKeys = [group.primary].concat(group.secondary).filter(function (k) { return subjobs[k]; });
            if (! relevantKeys.length) return '';

            var primaryState = subjobs[group.primary];
            // Semua subjob grup ini not_connected - tidak ada apapun buat
            // ditampilkan, JANGAN render kartu kosong (Langkah Q, "no alert
            // overload").
            if (relevantKeys.every(function (k) { return subjobs[k].status === 'not_connected'; })) return '';

            var task = progressTasks ? progressTasks[group.primary] : null;
            var body = '';

            if (primaryState && primaryState.status === 'needs_reconnect') {
                body = '<p class="text-xs text-[var(--warning-text)] flex items-center gap-1.5">'
                    + '<span class="material-symbols-outlined text-[15px]">link_off</span> Koneksi ' + group.label + ' butuh dihubungkan ulang'
                    + '</p><a href="' + reconnectUrl + '" class="text-xs font-medium text-[var(--brand)] hover:underline mt-1 inline-block">Hubungkan kembali ' + esc(group.label) + '</a>';
            } else if (primaryState && isBusy(primaryState.status)) {
                if (task && task.discovered_count > 0) {
                    var pct = Math.min(100, Math.round((task.processed_count / task.discovered_count) * 100));
                    body = '<div class="flex items-center justify-between text-xs mb-1.5">'
                        + '<span class="text-[var(--text-secondary)]">' + task.processed_count + ' dari ' + task.discovered_count + ' ' + group.unit + '</span>'
                        + '<span class="text-[var(--text-muted)]">' + formatElapsed(task.started_at) + '</span>'
                        + '</div>'
                        + '<div class="w-full h-1.5 rounded-full bg-[var(--surface-muted)] overflow-hidden mb-1"><div class="h-full rounded-full bg-[var(--brand)] transition-[width]" style="width:' + pct + '%"></div></div>'
                        + '<p class="text-[11px] text-[var(--text-muted)]">' + esc(stageLabels[task.stage] || 'Memproses...') + '</p>';
                } else {
                    // Langkah D - SEBELUM discovered_count diketahui: stage
                    // indeterminate, TIDAK PERNAH mengarang persentase.
                    body = '<div class="flex items-center gap-2 text-xs text-[var(--text-secondary)]">'
                        + '<svg class="animate-spin h-3.5 w-3.5 shrink-0" viewBox="0 0 24 24" fill="none"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v4a4 4 0 00-4 4H4z"/></svg>'
                        + '<span>' + esc((task && stageLabels[task.stage]) || ('Menyiapkan ' + group.label + '...')) + '</span>'
                        + '</div>';
                }

                // Langkah F - "taking longer than usual", SOFT warning saja,
                // backend (bukan JS) yang berwenang bilang benar-benar mati.
                var idleSeconds = task ? secondsSince(task.last_progress_at) : null;
                if (idleSeconds !== null && idleSeconds > SLOW_PROGRESS_SECONDS) {
                    body += '<p class="text-[11px] text-[var(--warning-text)] mt-1.5">Pembaruan membutuhkan waktu lebih lama dari biasanya.</p>';
                }
            } else if (task && task.finished_at) {
                var lines = reconciliationLines(task, group.unit);
                if (lines.length) {
                    body = lines.map(function (l) {
                        return '<p class="text-xs ' + toneClass(l.tone) + '">' + esc(l.text) + '</p>';
                    }).join('');
                } else if (primaryState) {
                    body = '<p class="text-xs text-[var(--text-muted)]">' + esc(lastResultMessages[primaryState.status] || '') + '</p>';
                }
                var retryHtml = retryButtonHtml(group.primary, task, group.label, group.unit);
                if (retryHtml) body += '<div class="mt-1.5">' + retryHtml + '</div>';
            } else if (primaryState) {
                body = '<p class="text-xs text-[var(--text-muted)]">' + esc(lastResultMessages[primaryState.status] || (primaryState.message || '')) + '</p>';
            }

            // Secondary subjob (mis. Instagram Audiens) - GAGAL/needs_reconnect
            // saja yang ditonjolkan (Langkah Q, "no alert overload" - sukses
            // diam-diam saja, cukup ada di "Lihat detail").
            group.secondary.forEach(function (secKey) {
                var secState = subjobs[secKey];
                if (! secState) return;
                if (secState.status === 'needs_reconnect') {
                    body += '<p class="text-[11px] text-[var(--warning-text)] mt-1">' + esc(secondaryLabels[secKey] || secKey) + ': butuh dihubungkan ulang</p>';
                } else if (secState.status === 'failed') {
                    var secTask = progressTasks ? progressTasks[secKey] : null;
                    body += '<p class="text-[11px] text-[var(--danger-text)] mt-1">' + esc(secondaryLabels[secKey] || secKey) + ': belum berhasil diperbarui</p>';
                    if (secTask && secTask.id) {
                        body += '<button type="button" class="text-[11px] font-medium text-[var(--brand)] hover:underline analytics-retry-btn" data-task-id="' + secTask.id + '" data-action="retry-task">Coba lagi data Audiens</button>';
                    }
                }
            });

            var checklist = detailChecklist(task, group.unit);
            var detailHtml = checklist.length
                ? '<details class="mt-2"><summary class="text-[11px] font-medium text-[var(--brand)] cursor-pointer select-none">Lihat detail</summary>'
                    + '<div class="mt-2 space-y-1">' + checklist.map(function (c) {
                        return '<p class="text-[11px] text-[var(--text-secondary)] flex items-center gap-1.5">' + checklistIcon(c.ok) + ' ' + esc(c.text) + '</p>';
                    }).join('') + '</div></details>'
                : '';

            return '<div class="py-3 first:pt-0 last:pb-0 border-b last:border-0 border-[var(--surface-muted)]">'
                + '<p class="text-xs font-semibold text-[var(--text-primary)] mb-1.5">' + esc(group.label) + '</p>'
                + body + detailHtml
                + '</div>';
        }

        function renderSyncPanel(data) {
            if (! panel) return;

            var subjobs = data.subjobs || {};
            var progressTasks = (data.progress && data.progress.tasks) || null;
            var html = PLATFORM_GROUPS.map(function (g) { return renderGroup(g, subjobs, progressTasks); }).join('');

            if (! html) { panel.hidden = true; panel.innerHTML = ''; return; }

            panel.innerHTML = '<div class="card p-4">' + html + '</div>';
            panel.hidden = false;

            panel.querySelectorAll('.analytics-retry-btn').forEach(function (btn) {
                btn.addEventListener('click', function () { handleRetryClick(btn); });
            });
        }

        function handleRetryClick(btn) {
            var taskId = btn.getAttribute('data-task-id');
            var action = btn.getAttribute('data-action');
            var url = action === 'retry-items' ? retryFailedItemsUrl : retryTaskUrl;
            btn.disabled = true;
            btn.textContent = 'Mencoba lagi...';

            fetch(url, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrfToken, 'Accept': 'application/json' },
                body: JSON.stringify({ task_id: taskId }),
            })
                .then(function (res) { return res.json(); })
                .then(function () {
                    isTracking = true;
                    startPolling();
                })
                .catch(function () {
                    btn.disabled = false;
                });
        }

        // PASS 3 (Langkah O, "AUTO-SYNC UX") - "Data diperbarui hari ini,
        // 20:42" style, bukan lagi timestamp teknis mentah - user tidak
        // perlu paham jadwal auto-sync, cukup tahu KAPAN terakhir segar.
        function formatFreshness(iso) {
            var date = new Date(iso);
            var now = new Date();
            var isToday = date.toDateString() === now.toDateString();
            var yesterday = new Date(now); yesterday.setDate(now.getDate() - 1);
            var isYesterday = date.toDateString() === yesterday.toDateString();
            var time = date.toLocaleTimeString('id-ID', { hour: '2-digit', minute: '2-digit' });

            if (isToday) return 'Data diperbarui hari ini, ' + time;
            if (isYesterday) return 'Data diperbarui kemarin, ' + time;
            return 'Data diperbarui ' + date.toLocaleDateString('id-ID', { day: 'numeric', month: 'short', year: 'numeric' }) + ', ' + time;
        }

        function applyStatus(data) {
            var busy = isBusy(data.overall_status);

            if (busy) {
                // Operasi SEDANG berjalan - entah baru kita trigger, atau
                // ketemu sudah jalan (tab/sesi lain/scheduled sync - Langkah
                // E) pas halaman dibuka - WAJIB dilacak sampai selesai.
                isTracking = true;
            }

            if (freshness) {
                if (data.last_observation_at) {
                    freshness.textContent = formatFreshness(data.last_observation_at);
                } else if (! busy && ! isTracking) {
                    freshness.textContent = lastResultMessages[data.overall_status] || 'Belum ada data yang tersinkronkan.';
                }
                freshness.hidden = false;
            }

            renderSyncPanel(data);

            if (! busy && data.overall_status === 'needs_reconnect') {
                applyNeedsReconnectButtonState();
            } else {
                applyNormalButtonState(busy, data.subjobs);
            }

            if (! busy) {
                stopPolling();

                // Langkah 13/E - reload SEKALI, TAPI CUMA kalau kita memang
                // sedang melacak satu siklus operasi nyata (isTracking) -
                // last_result historis yang ditemukan TANPA tracking aktif
                // TIDAK PERNAH memicu reload. window.location.reload()
                // otomatis preserve SEMUA query string yang lagi aktif.
                if (isTracking && (data.overall_status === 'success' || data.overall_status === 'partial' || data.overall_status === 'failed')) {
                    setTimeout(function () { window.location.reload(); }, 900);
                }
                isTracking = false;
            }
        }

        function showSafeError(text) {
            if (freshness) { freshness.textContent = text; freshness.hidden = false; }
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
                        applyNormalButtonState(false, null);
                        return;
                    }

                    consecutivePollFailures++;
                    if (consecutivePollFailures >= MAX_POLL_FAILURES) {
                        stopPolling();
                        isTracking = false;
                        showSafeError('Gagal memuat status sinkronisasi. Coba muat ulang halaman.');
                        applyNormalButtonState(false, null);
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
            applyNormalButtonState(true, null);

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
                        showSafeError(result.body.message || 'Pembaruan data gagal dimulai.');
                        applyNormalButtonState(false, null);
                        return;
                    }
                    startPolling();
                })
                .catch(function () {
                    isTracking = false;
                    showSafeError('Pembaruan data gagal dimulai.');
                    applyNormalButtonState(false, null);
                });
        }

        applyNormalButtonState(false, null);
        button.onclick = dispatchSync;

        // Ambil status begitu halaman dibuka (bukan cuma setelah klik) -
        // biar freshness indicator & (kalau kebetulan ada sync yang masih
        // berjalan dari klik sebelumnya/tab lain/scheduled - Langkah E)
        // panel langsung akurat tanpa perlu klik dulu. isTracking TETAP
        // false di titik ini - kalau hasilnya status busy, applyStatus()
        // sendiri yang akan menyalakan tracking; kalau hasilnya terminal,
        // itu last_result historis APA ADANYA, TIDAK memicu reload.
        poll();
    })();
</script>
@endif

@endsection
