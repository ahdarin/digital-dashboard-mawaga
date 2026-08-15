@extends('layouts.app')
@section('title', 'Content Analytics')
@section('content')

<div class="p-4 sm:p-6 lg:p-8 max-w-[1400px]">

    {{-- Header --}}
    <div class="flex flex-col sm:flex-row sm:items-start justify-between gap-3 mb-7">
        <div>
            <h1 class="font-display text-[26px] sm:text-[32px] font-semibold text-[#14181a] tracking-tight">Content Analytics</h1>
            <p class="text-[#5c6266] text-sm mt-1">Analisis performa konten lintas client &amp; platform.</p>
        </div>

        <div class="flex items-center gap-1">
            @if ($selectedClientId && $activeTab === 'overview')
                <a href="{{ route('analytics.export', ['client_id' => $selectedClientId, 'period' => $period]) }}"
                   class="text-sm font-medium text-white bg-[#044b46] hover:bg-[#033b37] active:scale-[0.98] flex items-center gap-1.5 px-4 py-2 rounded-lg transition-all ml-1 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[#044b46]">
                    <span class="material-symbols-outlined text-[17px]">download</span> Export
                </a>
            @endif
        </div>
    </div>

    {{-- Tab switcher - Analytics / Performance Table / Audience sekarang 1
         halaman yang sama, tab ganti konten di bawah, client & filter yang
         lagi dipilih ikut kebawa (reload halaman, bukan AJAX). --}}
    @php
        $tabHref = fn (string $tab) => route('analytics', array_filter(['tab' => $tab, 'client_id' => $selectedClientId]));
        $tabs = [
            ['key' => 'overview', 'label' => 'Analytics', 'icon' => 'monitoring'],
            ['key' => 'table', 'label' => 'Performance Table', 'icon' => 'table_rows'],
            ['key' => 'audience', 'label' => 'Audience', 'icon' => 'groups'],
        ];
    @endphp
    <div class="flex items-center gap-1 mb-6 border-b border-[#eef0f4] overflow-x-auto thin-autohide-scrollbar">
        @foreach ($tabs as $t)
            <a href="{{ $tabHref($t['key']) }}"
               class="text-sm font-medium px-4 py-2.5 border-b-2 -mb-px flex items-center gap-1.5 shrink-0 whitespace-nowrap transition-colors focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[#044b46]
                   {{ $activeTab === $t['key'] ? 'border-[#044b46] text-[#044b46]' : 'border-transparent text-[#5c6266] hover:text-[#14181a]' }}">
                <span class="material-symbols-outlined text-[17px]">{{ $t['icon'] }}</span> {{ $t['label'] }}
            </a>
        @endforeach
    </div>

    {{-- Filter bar - dipakai bareng ketiga tab --}}
    <form method="GET" class="card p-4 mb-6 flex items-center gap-3 flex-wrap">
        <input type="hidden" name="tab" value="{{ $activeTab }}">

        <select name="client_id" onchange="this.form.submit()"
                class="text-sm border border-[#eef0f4] rounded-lg px-3.5 py-2 bg-white focus:outline-none focus:ring-2 focus:ring-[#044b46]/15 focus:border-[#044b46]/40 transition-shadow">
            <option value="">Pilih Client...</option>
            @foreach ($clientOptions as $client)
                <option value="{{ $client->id }}" {{ (string) $selectedClientId === (string) $client->id ? 'selected' : '' }}>{{ $client->name }}</option>
            @endforeach
        </select>

        @if ($activeTab !== 'table')
            <select name="period" onchange="this.form.submit()"
                    class="text-sm border border-[#eef0f4] rounded-lg px-3.5 py-2 bg-white focus:outline-none focus:ring-2 focus:ring-[#044b46]/15 focus:border-[#044b46]/40 transition-shadow">
                <option value="7" {{ $period === 7 ? 'selected' : '' }}>Last 7 Days</option>
                <option value="30" {{ $period === 30 ? 'selected' : '' }}>Last 30 Days</option>
                <option value="90" {{ $period === 90 ? 'selected' : '' }}>Last 90 Days</option>
            </select>
        @endif

        @if ($activeTab === 'audience' && ! empty($platforms) && $platforms->count() > 1)
            <select name="platform_id" onchange="this.form.submit()"
                    class="text-sm border border-[#eef0f4] rounded-lg px-3.5 py-2 bg-white focus:outline-none focus:ring-2 focus:ring-[#044b46]/15 focus:border-[#044b46]/40 transition-shadow">
                @foreach ($platforms as $p)
                    <option value="{{ $p->id }}" {{ (string) $selectedPlatformId === (string) $p->id ? 'selected' : '' }}>{{ $p->name }}</option>
                @endforeach
            </select>
        @endif
    </form>

    @if (! empty($noClientSelected))
        <div class="card p-16 flex flex-col items-center justify-center text-center">
            <div class="w-14 h-14 rounded-full bg-[#f0f5f4] flex items-center justify-center mb-4">
                <span class="material-symbols-outlined text-[#044b46] text-[24px]">filter_alt</span>
            </div>
            <h2 class="font-display text-lg font-semibold text-[#14181a] mb-1.5">Pilih client dulu</h2>
            <p class="text-sm text-[#5c6266] max-w-sm">Performa konten ditampilkan per client. Pilih salah satu di dropdown atas untuk mulai.</p>
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

        {{-- Stat cards --}}
        <div class="grid grid-cols-2 sm:grid-cols-4 gap-4 mb-6">
            @foreach ($stats as $stat)
                <div class="card p-5 transition-shadow hover:shadow-[0_2px_10px_rgba(20,24,26,0.05)]">
                    <div class="flex items-center justify-between mb-4">
                        <span class="text-[13px] text-[#5c6266]">{{ $stat['label'] }}</span>
                        <span class="material-symbols-outlined text-[#c3c7cb] text-[18px]">{{ $stat['icon'] }}</span>
                    </div>
                    <p class="font-display text-[26px] font-semibold text-[#14181a] mb-2 [font-variant-numeric:tabular-nums]">{{ $stat['value'] }}</p>
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

        <div class="space-y-5">

                {{-- AI Strategy Analysis - beneran manggil Gemini API --}}
                <div class="rounded-2xl overflow-hidden border border-[#eef0f4] bg-white" x-data="{ loading: false }">

                    {{-- Header flat (bukan gradient) - konsisten sama treatment card lain --}}
                    <div class="bg-[#f7f8fc] border-b border-[#eef0f4] px-4 sm:px-6 py-5 flex flex-col sm:flex-row sm:items-center justify-between gap-4">
                        <div class="flex items-center gap-3">
                            <div class="w-9 h-9 rounded-xl bg-[#f0f5f4] flex items-center justify-center shrink-0">
                                <span class="material-symbols-outlined text-[#044b46] text-[19px]">auto_awesome</span>
                            </div>
                            <div>
                                <h2 class="font-display text-lg font-semibold text-[#14181a] leading-tight">AI Strategy Analysis</h2>
                                <p class="text-xs text-[#5c6266] mt-0.5">Analisis performa bulan {{ $aiAnalysisMonth }}, dibangkitkan Gemini AI dari data asli client ini &mdash; independen dari filter periode di atas</p>
                            </div>
                        </div>

                        <div class="flex items-center gap-4 shrink-0">
                            @if ($latestAiInsight)
                                <a href="{{ route('analytics.ai-strategy.history', ['client_id' => $selectedClientId]) }}"
                                   class="text-xs font-medium text-[#9aa0a4] hover:text-[#044b46] flex items-center gap-1 transition-colors focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[#044b46] rounded">
                                    <span class="material-symbols-outlined text-[15px]">history</span> Riwayat
                                </a>
                            @endif
                        <form action="{{ route('analytics.ai-strategy') }}" method="POST" x-on:submit="loading = true" class="shrink-0">
                            @csrf
                            <input type="hidden" name="client_id" value="{{ $selectedClientId }}">
                            <button type="submit" :disabled="loading"
                                    class="text-sm font-medium bg-[#044b46] text-white px-4 py-2.5 rounded-lg hover:bg-[#033b37] active:scale-[0.98] transition-all disabled:opacity-60 flex items-center gap-2 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[#044b46]">
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
                        </div>
                    </div>

                    <div class="bg-white p-4 sm:p-6">

                    @if (session('ai_error'))
                        <div class="bg-[#fdf2f1] border border-[#f5d9d7] rounded-lg p-4 text-sm text-[#b3423e] flex items-start gap-2.5">
                            <span class="material-symbols-outlined text-[17px] shrink-0">error</span>
                            {{ session('ai_error') }}
                        </div>
                    @elseif (! $latestAiInsight)
                        <div class="flex flex-col items-center justify-center text-center py-10">
                            <div class="w-12 h-12 rounded-full bg-[#f0f5f4] flex items-center justify-center mb-3">
                                <span class="material-symbols-outlined text-[#044b46] text-[22px]">insights</span>
                            </div>
                            <p class="text-sm font-medium text-[#14181a] mb-1">Belum ada analisis buat client ini</p>
                            <p class="text-xs text-[#9aa0a4] max-w-xs">Klik "Generate Analisis" di atas — AI bakal baca performa 30 hari terakhir dan kasih rekomendasi strategi konkret.</p>
                        </div>
                    @elseif ($latestAiInsight->status === 'failed')
                        <div class="bg-[#fdf2f1] border border-[#f5d9d7] rounded-lg p-4 text-sm text-[#b3423e] flex items-start gap-2.5">
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
                        <div class="flex items-center gap-1 bg-[#f2f3f6] rounded-lg p-1 mb-5 w-fit">
                            <button type="button" x-on:click="tab = 'ringkasan'"
                                    :class="tab === 'ringkasan' ? 'bg-white text-[#14181a] shadow-sm' : 'text-[#9aa0a4] hover:text-[#5c6266]'"
                                    class="text-xs font-medium px-4 py-2 rounded-md transition-colors duration-200">
                                Ringkasan
                            </button>
                            <button type="button" x-on:click="tab = 'ide'"
                                    :class="tab === 'ide' ? 'bg-white text-[#14181a] shadow-sm' : 'text-[#9aa0a4] hover:text-[#5c6266]'"
                                    class="text-xs font-medium px-4 py-2 rounded-md transition-colors duration-200">
                                Ide Konten ({{ count($latestAiInsight->content_ideas ?? []) }})
                            </button>
                            <button type="button" x-on:click="tab = 'diskusi'"
                                    :class="tab === 'diskusi' ? 'bg-white text-[#14181a] shadow-sm' : 'text-[#9aa0a4] hover:text-[#5c6266]'"
                                    class="text-xs font-medium px-4 py-2 rounded-md transition-colors duration-200 flex items-center gap-1.5">
                                Diskusi
                                <span x-show="messages.filter(m => m.role !== 'system').length > 0" x-cloak
                                      class="w-4 h-4 rounded-full bg-[#044b46] text-white text-[9px] font-bold flex items-center justify-center"
                                      x-text="messages.filter(m => m.role !== 'system').length"></span>
                            </button>
                        </div>

                        {{-- ===== TAB: RINGKASAN ===== --}}
                        <div x-show="tab === 'ringkasan'" x-cloak>
                            <div class="bg-[#f7f8fc] border border-[#eef0f4] rounded-xl p-4 mb-5">
                                <p class="text-sm text-[#14181a] leading-relaxed">{{ $latestAiInsight->summary }}</p>
                            </div>

                            <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">

                                <div class="lg:col-span-2 space-y-5">
                                    @if (! empty($latestAiInsight->top_pillars))
                                        <div>
                                            <p class="text-xs font-semibold text-[#9aa0a4] uppercase tracking-wide mb-3">Top Content Pillars</p>
                                            <div class="space-y-2">
                                                @php $rankStyles = ['bg-[#044b46]', 'bg-[#5c6266]', 'bg-[#9aa0a4]']; @endphp
                                                @foreach ($latestAiInsight->top_pillars as $i => $pillar)
                                                    <div class="flex items-start gap-3 border border-[#eef0f4] rounded-xl p-3.5 hover:border-[#044b46]/20 hover:bg-[#fafcfb] transition-colors">
                                                        <span class="w-6 h-6 rounded-full {{ $rankStyles[$i] ?? 'bg-[#c3c7cb]' }} text-white text-[11px] font-semibold flex items-center justify-center shrink-0">{{ $i + 1 }}</span>
                                                        <div class="min-w-0">
                                                            <p class="text-sm font-semibold text-[#14181a]">{{ $pillar['name'] }}</p>
                                                            <p class="text-xs text-[#5c6266] mt-0.5 leading-relaxed">{{ $pillar['reasoning'] }}</p>
                                                        </div>
                                                    </div>
                                                @endforeach
                                            </div>
                                        </div>
                                    @endif

                                    @if (! empty($latestAiInsight->action_items))
                                        <div>
                                            <p class="text-xs font-semibold text-[#9aa0a4] uppercase tracking-wide mb-3">Action Items</p>
                                            <ul class="space-y-2.5">
                                                @foreach ($latestAiInsight->action_items as $item)
                                                    <li class="flex items-start gap-2.5 text-sm text-[#14181a]">
                                                        <span class="w-5 h-5 rounded-full bg-[#eaf5f0] flex items-center justify-center shrink-0 mt-0.5">
                                                            <span class="material-symbols-outlined text-[#0f7a5f] text-[13px]">check</span>
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
                                        <div class="bg-[#f7f8fc] rounded-xl p-4">
                                            <p class="text-xs font-semibold text-[#9aa0a4] uppercase tracking-wide mb-3">Suggested Split</p>
                                            <div class="space-y-3">
                                                @foreach ($latestAiInsight->suggested_split as $i => $row)
                                                    <div>
                                                        <div class="flex items-center justify-between text-xs mb-1">
                                                            <span class="text-[#5c6266] flex items-center gap-1.5">
                                                                <span class="w-1.5 h-1.5 rounded-full shrink-0" style="background-color: {{ $chartColors[$i % 5] }}"></span>
                                                                {{ $row['label'] }}
                                                            </span>
                                                            <span class="font-semibold text-[#14181a] [font-variant-numeric:tabular-nums]">{{ $row['value'] }}%</span>
                                                        </div>
                                                        <div class="w-full h-1.5 rounded-full bg-white overflow-hidden">
                                                            <div class="h-full rounded-full transition-all duration-500" style="width: {{ $row['value'] }}%; background-color: {{ $chartColors[$i % 5] }}"></div>
                                                        </div>
                                                    </div>
                                                @endforeach
                                            </div>
                                        </div>
                                    @endif

                                    @if ($latestAiInsight->data_completeness_percent !== null)
                                        <div class="border border-[#eef0f4] rounded-xl p-4">
                                            <p class="text-xs font-semibold text-[#9aa0a4] uppercase tracking-wide mb-2">Kelengkapan Data</p>
                                            <div class="flex items-end gap-2 mb-2">
                                                <span class="font-display text-2xl font-semibold text-[#14181a] leading-none [font-variant-numeric:tabular-nums]">{{ $latestAiInsight->data_completeness_percent }}%</span>
                                                @if ($latestAiInsight->data_completeness_percent < 60)
                                                    <span class="text-[10px] text-[#b8873a] font-medium mb-0.5">Data agak tipis</span>
                                                @endif
                                            </div>
                                            <div class="w-full h-1.5 rounded-full bg-[#f2f3f6] overflow-hidden">
                                                <div class="h-full rounded-full {{ $latestAiInsight->data_completeness_percent >= 60 ? 'bg-[#0f7a5f]' : 'bg-[#b8873a]' }}"
                                                     style="width: {{ $latestAiInsight->data_completeness_percent }}%"></div>
                                            </div>
                                        </div>
                                    @endif
                                </div>
                            </div>

                            <p class="text-[11px] text-[#c3c7cb] mt-5 pt-4 border-t border-[#f2f3f6]">
                                Digenerate {{ $latestAiInsight->created_at->diffForHumans() }} oleh {{ $latestAiInsight->generatedBy->name ?? '-' }}
                                @if ($latestAiInsight->applied_at)
                                    &middot; Diterapkan {{ $latestAiInsight->applied_at->diffForHumans() }}
                                @endif
                            </p>
                        </div>

                        {{-- ===== TAB: IDE KONTEN ===== --}}
                        <div x-show="tab === 'ide'" x-cloak>
                            @if (! $latestAiInsight->applied_at && ! empty($latestAiInsight->suggested_split))
                                <form action="{{ route('analytics.ai-strategy.apply', $latestAiInsight) }}" method="POST" class="mb-4">
                                    @csrf
                                    <button type="submit" class="w-full text-sm font-medium bg-[#044b46] text-white px-4 py-3 rounded-xl hover:bg-[#033b37] active:scale-[0.99] transition-all flex items-center justify-center gap-2 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[#044b46]">
                                        <span class="material-symbols-outlined text-[16px]">bolt</span>
                                        Terapkan Semua Ide Ini ke Content Plan
                                    </button>
                                </form>
                            @elseif ($latestAiInsight->applied_at)
                                <div class="border border-[#eaf5f0] bg-[#f7fbf9] rounded-xl p-3.5 mb-4 flex items-center justify-between gap-3">
                                    <div class="flex items-center gap-1.5 text-sm font-medium text-[#0f7a5f]">
                                        <span class="material-symbols-outlined text-[16px]">check_circle</span>
                                        Sudah diterapkan ke Content Plan bulan ini
                                    </div>
                                    <form action="{{ route('analytics.ai-strategy.revert', $latestAiInsight) }}" method="POST"
                                          onsubmit="return appConfirm(this, 'Yakin mau tarik kembali? Semua draft content item yang dibuat dari analisis ini bakal dihapus (kalau belum ada progress).', { danger: true })">
                                        @csrf
                                        <button type="submit" class="text-xs font-medium text-[#b3423e] border border-[#f5d9d7] px-3.5 py-2 rounded-lg hover:bg-[#fdf2f1] active:scale-[0.98] transition-all flex items-center gap-1.5 shrink-0 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[#b3423e]">
                                            <span class="material-symbols-outlined text-[14px]">undo</span>
                                            Tarik Kembali
                                        </button>
                                    </form>
                                </div>
                            @endif

                            <p x-show="ideas.length === 0" class="text-sm text-[#9aa0a4] text-center py-10">Belum ada ide konten buat analisis ini.</p>

                            <div x-show="ideas.length > 0" class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                                <template x-for="(idea, index) in ideas" :key="index">
                                    <button type="button" x-on:click="openIdea(index)"
                                            class="text-left border border-[#eef0f4] rounded-lg p-3.5 hover:border-[#044b46]/20 hover:bg-[#fafcfb] transition-colors focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[#044b46]">
                                        <div class="flex items-center justify-between gap-2 mb-1.5">
                                            <span class="text-[10px] font-medium px-2 py-0.5 rounded-full bg-[#f0f5f4] text-[#044b46] inline-block" x-text="idea.pillar ?? '-'"></span>
                                            <span x-show="idea.predicted_score !== null && idea.predicted_score !== undefined"
                                                  class="text-[10px] font-semibold px-2 py-0.5 rounded-full shrink-0"
                                                  :class="scoreBadgeClass(idea.predicted_label)"
                                                  :title="scoreLabelText(idea.predicted_label) + ' — berdasarkan performa historis pillar & platform ini'"
                                                  x-text="idea.predicted_score + '%'"></span>
                                        </div>
                                        <p class="text-sm font-semibold text-[#14181a]" x-text="idea.title ?? '-'"></p>
                                        <p class="text-xs text-[#5c6266] mt-1 leading-relaxed" x-text="idea.brief ?? '-'"></p>
                                        <p class="text-[10px] text-[#9aa0a4] mt-2 flex items-center gap-2">
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
                            <p class="text-xs text-[#9aa0a4] mb-3">Kasih masukan, koreksi, atau tanya soal analisis ini — AI bakal jawab tetap ngerujuk ke data asli. Ngobrol di sini belum ngubah apapun; kalau AI setuju sama masukan lo, klik "Perbarui Analisis dari Diskusi Ini" di bawah biar beneran diterapkan.</p>

                            <div class="space-y-3 mb-3 max-h-96 overflow-y-auto" x-ref="messageList">
                                <template x-for="msg in messages" :key="msg.id ?? msg.message + msg.time">
                                    <div>
                                        <p x-show="msg.role === 'system'" x-cloak class="text-center text-[11px] text-[#9aa0a4] italic" x-text="msg.message"></p>
                                        <div x-show="msg.role !== 'system'" x-cloak class="flex" :class="msg.role === 'user' ? 'justify-end' : 'justify-start'">
                                            <div class="max-w-[85%] rounded-xl px-3.5 py-2.5"
                                                 :class="msg.role === 'user' ? 'bg-[#044b46] text-white' : 'bg-[#f7f8fc] text-[#14181a]'">
                                                <p class="text-sm leading-relaxed whitespace-pre-wrap" x-text="msg.message"></p>
                                                <p class="text-[10px] mt-1" :class="msg.role === 'user' ? 'text-white/60' : 'text-[#9aa0a4]'" x-text="msg.time"></p>
                                            </div>
                                        </div>
                                    </div>
                                </template>

                                <div x-show="messages.length === 0" x-cloak class="text-center py-8">
                                    <span class="material-symbols-outlined text-[#d4d7db] text-[22px] mb-2 block">forum</span>
                                    <p class="text-xs text-[#9aa0a4]">Belum ada diskusi. Tulis pertanyaan/masukan di bawah.</p>
                                </div>

                                <div x-show="sending" x-cloak class="flex justify-start">
                                    <div class="bg-[#f7f8fc] rounded-xl px-3.5 py-2.5">
                                        <div class="flex gap-1">
                                            <span class="w-1.5 h-1.5 rounded-full bg-[#9aa0a4] animate-bounce" style="animation-delay:0ms"></span>
                                            <span class="w-1.5 h-1.5 rounded-full bg-[#9aa0a4] animate-bounce" style="animation-delay:150ms"></span>
                                            <span class="w-1.5 h-1.5 rounded-full bg-[#9aa0a4] animate-bounce" style="animation-delay:300ms"></span>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <p x-show="errorMsg" x-cloak class="text-xs text-[#b3423e] mb-2" x-text="errorMsg"></p>

                            <form x-on:submit.prevent="send()" class="flex items-center gap-2 mb-3">
                                <input type="text" x-model="draft" placeholder="Tulis masukan atau pertanyaan..."
                                       :disabled="sending"
                                       class="flex-1 border border-[#eef0f4] rounded-lg px-3.5 py-2.5 text-sm focus:outline-none focus:ring-2 focus:ring-[#044b46]/15 focus:border-[#044b46]/40 disabled:opacity-60 transition-shadow">
                                <button type="submit" :disabled="sending || !draft.trim()"
                                        class="w-10 h-10 shrink-0 rounded-lg bg-[#044b46] text-white flex items-center justify-center hover:bg-[#033b37] active:scale-[0.95] disabled:opacity-40 transition-all focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[#044b46]">
                                    <span class="material-symbols-outlined text-[18px]">send</span>
                                </button>
                            </form>

                            @if ($latestAiInsight->applied_at)
                                <p class="text-xs text-[#9aa0a4] text-center bg-[#f7f8fc] rounded-lg px-3.5 py-2.5 leading-relaxed">
                                    Analisis ini sudah diterapkan ke Content Plan, jadi nggak bisa diperbarui dari diskusi lagi (draft yang udah dibuat bisa nggak nyambung lagi).
                                    <button type="button" x-on:click="tab = 'ide'" class="text-[#044b46] font-medium hover:underline">Tarik kembali dulu</button>
                                    kalau mau update berdasarkan diskusi ini.
                                </p>
                            @elseif ($latestAiInsight->messages->where('role', '!=', 'system')->isNotEmpty())
                                <form action="{{ route('analytics.ai-strategy.refine', $latestAiInsight) }}" method="POST"
                                      onsubmit="return appConfirm(this, 'Analisis (summary, action items, suggested split) bakal diperbarui berdasarkan seluruh diskusi di atas. Lanjut?')">
                                    @csrf
                                    <button type="submit" class="w-full text-xs font-medium bg-[#eef2fb] text-[#3452a8] px-3.5 py-2.5 rounded-lg hover:bg-[#e2e8f8] active:scale-[0.98] transition-all flex items-center justify-center gap-1.5 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[#3452a8]">
                                        <span class="material-symbols-outlined text-[15px]">sync</span>
                                        Perbarui Analisis dari Diskusi Ini
                                    </button>
                                </form>
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
                                 class="relative bg-white rounded-2xl border border-[#eef0f4] shadow-xl w-full max-w-lg max-h-[85vh] overflow-y-auto">

                                <template x-if="selectedIdea">
                                    <div class="p-6">
                                        <div class="flex items-start justify-between gap-3 mb-4">
                                            <div class="flex items-center gap-2 flex-wrap">
                                                <span class="text-[10px] font-medium px-2 py-0.5 rounded-full bg-[#f0f5f4] text-[#044b46]" x-text="selectedIdea.pillar ?? '-'"></span>
                                                <span x-show="selectedIdea.predicted_score !== null && selectedIdea.predicted_score !== undefined"
                                                      class="text-[10px] font-semibold px-2 py-0.5 rounded-full"
                                                      :class="scoreBadgeClass(selectedIdea.predicted_label)"
                                                      x-text="scoreLabelText(selectedIdea.predicted_label) + ' (' + selectedIdea.predicted_score + '%)'"></span>
                                            </div>
                                            <button type="button" x-on:click="closeIdea()"
                                                    class="text-[#9aa0a4] hover:text-[#14181a] transition-colors focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[#044b46] rounded">
                                                <span class="material-symbols-outlined text-[20px]">close</span>
                                            </button>
                                        </div>

                                        <p class="font-display text-lg font-semibold text-[#14181a] leading-snug mb-2" x-text="selectedIdea.title ?? '-'"></p>
                                        <p class="text-sm text-[#5c6266] leading-relaxed mb-4" x-text="selectedIdea.brief ?? '-'"></p>

                                        <div class="flex items-center gap-2 mb-5">
                                            <span x-show="selectedIdea.type" class="text-[11px] font-medium px-2.5 py-1 rounded-full bg-[#f2f3f6] text-[#5c6266] flex items-center gap-1">
                                                <span class="material-symbols-outlined text-[13px]">design_services</span>
                                                <span x-text="selectedIdea.type"></span>
                                            </span>
                                            <span x-show="selectedIdea.platform" class="text-[11px] font-medium px-2.5 py-1 rounded-full bg-[#f2f3f6] text-[#5c6266] flex items-center gap-1">
                                                <span class="material-symbols-outlined text-[13px]">hub</span>
                                                <span x-text="selectedIdea.platform"></span>
                                            </span>
                                        </div>

                                        @if ($latestAiInsight->applied_at)
                                            <p class="text-xs text-[#9aa0a4] bg-[#f7f8fc] rounded-lg px-3.5 py-2.5 leading-relaxed">
                                                Analisis ini udah diterapkan ke Content Plan, jadi ide di sini nggak bisa di-regenerate lagi (draft yang udah dibuat bisa nggak nyambung lagi). Tarik kembali dulu kalau mau ubah ide.
                                            </p>
                                        @else
                                            <div class="border-t border-[#f2f3f6] pt-4">
                                                <label class="block text-[11px] font-semibold text-[#9aa0a4] uppercase tracking-wide mb-2">Ganti Kategori (opsional)</label>
                                                <select x-model="editPillar" :disabled="regenerating"
                                                        class="w-full text-sm border border-[#eef0f4] rounded-lg px-3.5 py-2 bg-white mb-3 focus:outline-none focus:ring-2 focus:ring-[#044b46]/15 focus:border-[#044b46]/40 disabled:opacity-60 transition-shadow">
                                                    <template x-for="pillar in pillarOptions" :key="pillar">
                                                        <option :value="pillar" x-text="pillar"></option>
                                                    </template>
                                                </select>

                                                <p x-show="regenError" x-cloak class="text-xs text-[#b3423e] mb-2" x-text="regenError"></p>

                                                <button type="button" x-on:click="regenerateIdea()" :disabled="regenerating"
                                                        class="w-full text-sm font-medium bg-[#044b46] text-white px-4 py-2.5 rounded-lg hover:bg-[#033b37] active:scale-[0.98] disabled:opacity-60 transition-all flex items-center justify-center gap-2 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[#044b46]">
                                                    <span x-show="!regenerating" class="flex items-center gap-1.5">
                                                        <span class="material-symbols-outlined text-[16px]">refresh</span>
                                                        <span x-text="editPillar === selectedIdea.pillar ? 'Cari Alternatif Lain' : 'Regenerate dengan Kategori Baru'"></span>
                                                    </span>
                                                    <span x-show="regenerating" x-cloak class="flex items-center gap-1.5">
                                                        <svg class="animate-spin h-4 w-4" viewBox="0 0 24 24" fill="none"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v4a4 4 0 00-4 4H4z"/></svg>
                                                        Nyari alternatif...
                                                    </span>
                                                </button>
                                                <p class="text-[11px] text-[#9aa0a4] mt-2 leading-relaxed">AI bakal bikin ide baru buat kategori ini, mempertimbangkan semua ide lain yang udah ada biar nggak duplikat &amp; beban kerja tim tetap seimbang.</p>
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
                    <span class="text-[11px] font-semibold text-[#9aa0a4] uppercase tracking-wider whitespace-nowrap">Performance Details</span>
                    <div class="flex-1 h-px bg-[#eef0f4]"></div>
                </div>

                {{-- Trend chart --}}
                <div class="card p-6">
                    <div class="flex items-center gap-2 mb-1">
                        <span class="material-symbols-outlined text-[#9aa0a4] text-[18px]">show_chart</span>
                        <h2 class="font-display text-lg font-semibold text-[#14181a]">Views Over Time</h2>
                    </div>
                    <p class="text-xs text-[#9aa0a4] mb-5 ml-[26px]">Total views seluruh konten pada periode terpilih.</p>
                    <x-trend-chart :trend="$trend" />
                </div>

                {{-- Traffic per Platform - sekarang full width, bukan sidebar sempit --}}
                <div class="card p-6">
                    <div class="flex items-center gap-2 mb-5">
                        <span class="material-symbols-outlined text-[#9aa0a4] text-[18px]">hub</span>
                        <h2 class="font-display text-lg font-semibold text-[#14181a]">Traffic per Platform</h2>
                    </div>

                    @if ($platformBreakdown->isEmpty())
                        <p class="text-sm text-[#9aa0a4] text-center py-6">Belum ada data.</p>
                    @else
                        @php
                            $maxPlatform = max($platformBreakdown->max('value'), 1);
                        @endphp
                        <div class="grid grid-cols-2 sm:grid-cols-4 gap-4">
                            @foreach ($platformBreakdown as $i => $row)
                                <div class="border border-[#eef0f4] rounded-xl p-4 hover:border-[#044b46]/20 transition-colors">
                                    <div class="flex items-center gap-2 mb-3">
                                        <span class="w-2 h-2 rounded-full shrink-0" style="background-color: {{ $chartColors[$i % 5] }}"></span>
                                        <span class="text-sm text-[#5c6266]">{{ $row['label'] }}</span>
                                    </div>
                                    <p class="font-display text-xl font-semibold text-[#14181a] mb-2 [font-variant-numeric:tabular-nums]">{{ number_format($row['value']) }}</p>
                                    <div class="w-full h-1.5 rounded-full bg-[#f2f3f6] overflow-hidden">
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
                        <span class="material-symbols-outlined text-[#9aa0a4] text-[18px]">military_tech</span>
                        <h2 class="font-display text-lg font-semibold text-[#14181a]">Top Performing Content</h2>
                    </div>

                    @if ($topContent->isEmpty())
                        <p class="text-sm text-[#9aa0a4] py-6 text-center">Belum ada konten dengan data performa.</p>
                    @else
                        <div class="overflow-x-auto">
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
                                        <tr class="border-t border-[#f2f3f6] hover:bg-[#f7f8fc] transition-colors">
                                            <td class="py-3 pr-3 font-medium text-[#14181a] whitespace-nowrap">
                                                {{ $content['title'] }}
                                                <p class="text-xs text-[#9aa0a4] font-normal mt-0.5">{{ $content['type'] }}</p>
                                            </td>
                                            <td class="py-3 pr-3 text-[#5c6266] whitespace-nowrap">{{ $content['client'] }}</td>
                                            <td class="py-3 pr-3 text-[#5c6266] whitespace-nowrap">{{ $content['platform'] }}</td>
                                            <td class="py-3 pr-3 font-medium text-[#14181a] whitespace-nowrap [font-variant-numeric:tabular-nums]">{{ number_format($content['views']) }}</td>
                                            <td class="py-3 pr-3">
                                                <span class="text-xs px-2 py-1 rounded-full bg-[#f0f5f4] text-[#044b46] whitespace-nowrap [font-variant-numeric:tabular-nums]">{{ $content['engagement_rate'] }}%</span>
                                            </td>
                                            <td class="py-3 text-right">
                                                <a href="{{ route('analytics.show', $content['id']) }}" class="text-xs font-medium text-[#044b46] hover:underline whitespace-nowrap focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[#044b46] rounded">Detail</a>
                                            </td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
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
                high: 'bg-[#f0f5f4] text-[#0f7a5f]',
                medium: 'bg-[#fdf6ec] text-[#8a6423]',
                low: 'bg-[#f2f3f6] text-[#5c6266]',
            }[label] ?? 'bg-[#f2f3f6] text-[#9aa0a4]';
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

@endsection
