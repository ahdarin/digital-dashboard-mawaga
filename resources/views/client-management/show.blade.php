@extends('layouts.app')
@section('title', $client->name)
@section('content')

@php $canManageClient = auth()->user()->hasPermissionTo('client', 'manage'); @endphp
<div x-data="{
        showPackageModal: false,
        showPicModal: {{ $errors->has('user_ids') ? 'true' : 'false' }},
        removePic: null,
        confirmRegenerate: false,
        portalLinkCopied: false,
        copyPortalLink() {
            navigator.clipboard.writeText('{{ route('client.portal.dashboard', $client->portal_token) }}').then(() => {
                this.portalLinkCopied = true;
                setTimeout(() => this.portalLinkCopied = false, 2000);
            });
        },
    }" class="p-4 sm:p-6 lg:p-8 max-w-[1300px] mx-auto">

    <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4 mb-7">
        <div class="flex items-center gap-4">
            <a href="{{ route('client-management.index') }}"
               class="w-9 h-9 flex items-center justify-center rounded-lg hover:bg-[var(--surface-card)] text-[var(--text-secondary)] transition-colors shrink-0">
                <span class="material-symbols-outlined text-[19px]">arrow_back</span>
            </a>
            <div class="rounded-xl bg-[var(--brand-tint)] text-[var(--brand)] flex items-center justify-center text-lg font-semibold shrink-0 overflow-hidden" style="width:52px;height:52px">
                @if ($client->logo_url)
                    <img src="{{ $client->logo_url }}" alt="" class="w-full h-full object-cover">
                @else
                    {{ strtoupper(substr($client->name, 0, 1)) }}
                @endif
            </div>
            <div>
                <div class="flex items-center gap-2.5">
                    <h1 class="font-display text-2xl font-semibold text-[var(--text-primary)]">{{ $client->name }}</h1>
                    <span class="badge
                        {{ $client->status === 'active' ? 'badge-success' : '' }}
                        {{ $client->status === 'past_due' ? 'badge-danger' : '' }}
                        {{ $client->status === 'paused' ? 'badge-neutral' : '' }}">
                        {{ match($client->status) { 'active' => 'Aktif', 'past_due' => 'Jatuh Tempo', 'paused' => 'Dijeda', default => ucfirst($client->status) } }}
                    </span>
                </div>
                <p class="text-sm text-[var(--text-muted)] mt-0.5">{{ $client->name }} &middot; {{ $client->category->name ?? '-' }}</p>
            </div>
        </div>

        @if ($canManageClient)
            <a href="{{ route('client-management.edit', $client) }}"
               class="btn-primary shrink-0">
                <span class="material-symbols-outlined text-[17px]">edit</span> Edit Klien
            </a>
        @else
            <span title="Cuma CEO/Manager yang bisa mengubah data klien"
                  class="btn-primary shrink-0 opacity-40 cursor-not-allowed pointer-events-none">
                <span class="material-symbols-outlined text-[17px]">edit</span> Edit Klien
            </span>
        @endif
    </div>

    @if (session('status'))
        <div class="bg-[var(--brand-tint)] text-[var(--brand)] text-sm p-3.5 rounded-lg mb-6">{{ session('status') }}</div>
    @endif

    @if (session('import_success'))
        <div class="bg-[var(--brand-tint)] text-[var(--brand)] text-sm p-3.5 rounded-lg mb-6 flex items-center gap-2">
            <span class="material-symbols-outlined text-[18px]">check_circle</span> {{ session('import_success') }}
        </div>
    @endif

    @if (session('import_error'))
        <div class="bg-[var(--danger-tint)] text-[var(--danger-text)] text-sm p-3.5 rounded-lg mb-6 flex items-center gap-2">
            <span class="material-symbols-outlined text-[18px]">error</span> {{ session('import_error') }}
        </div>
    @endif

    <div class="flex flex-col lg:flex-row gap-5 items-stretch lg:items-start">

        <div class="flex-1 min-w-0 space-y-5">

            <div class="grid grid-cols-1 sm:grid-cols-2 gap-5">
                <div class="card p-6">
                    <p class="text-sm text-[var(--text-secondary)] mb-2">Total Rencana Konten</p>
                    <p class="font-display text-2xl font-semibold text-[var(--text-primary)]">{{ $planCount }}</p>
                </div>
                <div class="card p-6">
                    <p class="text-sm text-[var(--text-secondary)] mb-2">Total Konten Dibuat</p>
                    <p class="font-display text-2xl font-semibold text-[var(--text-primary)]">{{ $contentCount }}</p>
                </div>
            </div>

            <div class="card p-6">
                <h2 class="font-display text-lg font-semibold text-[var(--text-primary)] mb-4">Konten Terbaru</h2>

                @if ($recentContentItems->isEmpty())
                    <p class="text-sm text-[var(--text-muted)] py-6 text-center">Belum ada konten untuk klien ini.</p>
                @else
                    {{-- Desktop table - kolom "Judul" di-truncate dengan lebar
                         cukup (>=sm), aman. Di viewport sempit, truncate +
                         table-fixed bikin judul kepotong tanpa cara dibaca
                         penuh (title="" tooltip tidak jalan di touch) - lihat
                         list mobile di bawah, pola sama dengan
                         client-management/index.blade.php. --}}
                    <div class="overflow-x-auto hidden sm:block">
                        <table class="w-full table-fixed text-sm text-left">
                            <thead class="bg-[var(--surface-page)]">
                                <tr class="text-[var(--text-muted)] text-[11px] uppercase tracking-wide">
                                    <th class="w-[38%] px-6 py-3 font-medium whitespace-nowrap">Judul</th>
                                    <th class="w-[15%] px-4 py-3 font-medium whitespace-nowrap">Tipe</th>
                                    <th class="w-[18%] px-4 py-3 font-medium whitespace-nowrap">Deadline</th>
                                    <th class="w-[29%] px-4 py-3 font-medium whitespace-nowrap">Status</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($recentContentItems as $item)
                                    <tr class="border-t border-[var(--surface-muted)]">
                                        <td class="px-6 py-3.5 font-medium text-[var(--text-primary)] truncate" title="{{ $item->title }}">{{ $item->title }}</td>
                                        <td class="px-4 py-3.5 text-[var(--text-secondary)] truncate">{{ $item->contentType->name ?? '-' }}</td>
                                        <td class="px-4 py-3.5 text-[var(--text-secondary)] truncate">{{ $item->deadline_at ? $item->deadline_at->translatedFormat('d M Y') : '-' }}</td>
                                        <td class="px-4 py-3.5">
                                            <span class="badge {{ ($item->workflow->is_overdue ?? false) ? 'badge-danger' : 'badge-success' }}">
                                                {{ $item->workflow ? \App\Support\WorkflowTransitions::label($item->workflow->current_status) : '-' }}
                                            </span>
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>

                    {{-- Mobile card list - judul tampil PENUH (wrap, bukan
                         truncate), status jadi badge yang selalu terlihat. --}}
                    <div class="sm:hidden space-y-2.5">
                        @foreach ($recentContentItems as $item)
                            <div class="card p-3.5">
                                <div class="flex items-start justify-between gap-3 mb-1.5">
                                    <p class="text-sm font-medium text-[var(--text-primary)] leading-snug">{{ $item->title }}</p>
                                    <span class="badge shrink-0 {{ ($item->workflow->is_overdue ?? false) ? 'badge-danger' : 'badge-success' }}">
                                        {{ $item->workflow ? \App\Support\WorkflowTransitions::label($item->workflow->current_status) : '-' }}
                                    </span>
                                </div>
                                <div class="flex items-center gap-1.5 text-xs text-[var(--text-muted)]">
                                    <span>{{ $item->contentType->name ?? '-' }}</span>
                                    <span>&middot;</span>
                                    <span>{{ $item->deadline_at ? $item->deadline_at->translatedFormat('d M Y') : '-' }}</span>
                                </div>
                            </div>
                        @endforeach
                    </div>
                @endif
            </div>

        </div>

        <div class="w-full lg:w-[320px] shrink-0 space-y-5">

            <div class="card p-6">
                <div class="flex items-center justify-between mb-4">
                    <h2 class="font-display text-base font-semibold text-[var(--text-primary)]">Akses Portal Klien</h2>
                    <span class="badge {{ $client->portal_access_enabled ? 'badge-success' : 'badge-neutral' }}">
                        {{ $client->portal_access_enabled ? 'Aktif' : 'Dinonaktifkan' }}
                    </span>
                </div>

                @if ($client->portal_access_enabled)
                    <p class="text-xs font-medium text-[var(--text-muted)] uppercase mb-1.5">Link Portal Permanen</p>
                    <div class="flex items-center gap-2 mb-4">
                        <input type="text" readonly value="{{ route('client.portal.dashboard', $client->portal_token) }}"
                               class="flex-1 min-w-0 bg-[var(--surface-page)] border border-[var(--border)] rounded-lg px-3 py-2 text-xs text-[var(--text-secondary)] truncate">
                        <span x-data="tooltipHover('Salin Link')" class="contents">
                            <button type="button" @click="copyPortalLink()"
                                    @mouseenter="onEnter($event)" @mouseleave="onLeave()"
                                    aria-label="Salin Link"
                                    class="w-9 h-9 shrink-0 flex items-center justify-center rounded-lg border border-[var(--border)] text-[var(--text-secondary)] hover:bg-[var(--surface-page)] transition-colors">
                                <span class="material-symbols-outlined text-[17px]" x-text="portalLinkCopied ? 'check' : 'content_copy'"></span>
                            </button>
                            @include('components.action-tooltip')
                        </span>
                    </div>
                    <p x-show="portalLinkCopied" x-cloak x-transition class="text-xs text-[var(--brand)] font-medium -mt-3 mb-4">Link berhasil disalin.</p>

                    <div class="flex flex-wrap items-center gap-2">
                        <a href="{{ route('client.portal.dashboard', $client->portal_token) }}" target="_blank" rel="noopener"
                           class="text-xs font-medium text-white bg-[var(--brand)] px-3 py-2 rounded-lg hover:opacity-90 transition-opacity flex items-center gap-1.5">
                            <span class="material-symbols-outlined text-[14px]">open_in_new</span> Buka Portal
                        </a>

                        @if ($canManageClient)
                            <button type="button" @click="confirmRegenerate = true"
                                    class="text-xs font-medium text-[var(--text-secondary)] border border-[var(--border)] px-3 py-2 rounded-lg hover:bg-[var(--surface-page)] transition-colors flex items-center gap-1.5">
                                <span class="material-symbols-outlined text-[14px]">refresh</span> Regenerate Link
                            </button>

                            <form action="{{ route('client-management.portal.disable', $client) }}" method="POST"
                                  onsubmit="return appConfirm(this, 'Nonaktifkan akses Portal Klien untuk {{ addslashes($client->name) }}? Link tidak akan bisa dipakai sampai diaktifkan lagi.', { danger: true })">
                                @csrf @method('PATCH')
                                <button type="submit" class="text-xs font-medium text-[var(--danger-text)] border border-[var(--border)] px-3 py-2 rounded-lg hover:bg-[var(--danger-tint)] transition-colors flex items-center gap-1.5">
                                    <span class="material-symbols-outlined text-[14px]">block</span> Nonaktifkan
                                </button>
                            </form>
                        @endif
                    </div>
                @else
                    <p class="text-sm text-[var(--text-muted)] mb-4">Akses Portal Klien sedang dinonaktifkan - klien tidak bisa membuka link portal sampai diaktifkan kembali.</p>

                    @if ($canManageClient)
                        <div class="flex flex-wrap items-center gap-2">
                            <form action="{{ route('client-management.portal.enable', $client) }}" method="POST">
                                @csrf @method('PATCH')
                                <button type="submit" class="text-xs font-medium text-white bg-[var(--brand)] px-3 py-2 rounded-lg hover:opacity-90 transition-opacity flex items-center gap-1.5">
                                    <span class="material-symbols-outlined text-[14px]">check_circle</span> Aktifkan
                                </button>
                            </form>
                            <button type="button" @click="confirmRegenerate = true"
                                    class="text-xs font-medium text-[var(--text-secondary)] border border-[var(--border)] px-3 py-2 rounded-lg hover:bg-[var(--surface-page)] transition-colors flex items-center gap-1.5">
                                <span class="material-symbols-outlined text-[14px]">refresh</span> Regenerate Link
                            </button>
                        </div>
                    @endif
                @endif
            </div>

            {{-- Modal Konfirmasi Regenerate Link --}}
            <template x-teleport="body">
                <div x-show="confirmRegenerate" x-cloak
                     x-on:keydown.escape.window="confirmRegenerate = false"
                     class="fixed inset-0 z-50 flex items-center justify-center p-4" style="display: none;">
                    <div class="absolute inset-0 bg-[#14181a]/40" @click="confirmRegenerate = false"></div>

                    <div x-show="confirmRegenerate" x-transition
                         role="dialog" aria-modal="true" aria-labelledby="regenerate-modal-title"
                         class="relative bg-[var(--surface-card)] rounded-2xl shadow-xl w-full max-w-md">
                        <div class="px-6 py-5 border-b border-[var(--border)]">
                            <h3 id="regenerate-modal-title" class="font-display text-lg font-semibold text-[var(--text-primary)]">Buat Link Baru?</h3>
                        </div>
                        <div class="px-6 py-5">
                            <p class="text-sm text-[var(--text-secondary)]">Link lama akan langsung tidak dapat digunakan.</p>
                        </div>
                        <form action="{{ route('client-management.portal.regenerate', $client) }}" method="POST">
                            @csrf
                            <div class="flex items-center gap-3 px-6 py-4 border-t border-[var(--border)]">
                                <button type="submit" class="btn-danger">Ya, Buat Link Baru</button>
                                <button type="button" @click="confirmRegenerate = false" class="btn-secondary">Batal</button>
                            </div>
                        </form>
                    </div>
                </div>
            </template>

            <div class="card p-6">
                <div class="flex items-center justify-between mb-4">
                    <h2 class="font-display text-base font-semibold text-[var(--text-primary)]">Paket Aktif</h2>
                    <button type="button" @click="showPackageModal = true" {{ $canManageClient ? '' : 'disabled' }}
                            title="{{ $canManageClient ? '' : 'Cuma CEO/Manager yang bisa mengubah paket' }}"
                            class="text-xs font-medium text-[var(--brand)] hover:underline disabled:opacity-40 disabled:cursor-not-allowed disabled:no-underline">Ubah Paket</button>
                </div>

                @if ($client->activePackage)
                    <p class="text-sm font-medium text-[var(--text-primary)]">{{ $client->activePackage->package_name_snapshot }}</p>
                    <div class="mt-3 space-y-1.5 text-xs text-[var(--text-secondary)]">
                        <p>Kuota Konten: {{ $client->activePackage->monthly_content_quota }} / bulan</p>
                        <p>Kuota Desain: {{ $client->activePackage->monthly_design_quota }} / bulan</p>
                        <p>Periode: {{ optional($client->activePackage->start_date)->translatedFormat('d M Y') }} &mdash;
                            {{ optional($client->activePackage->end_date)->translatedFormat('d M Y') ?? 'Berjalan' }}</p>
                    </div>
                @else
                    <p class="text-sm text-[var(--text-muted)]">Belum ada paket aktif.</p>
                @endif
            </div>

            <div class="card p-6">
                <div class="flex items-center justify-between mb-4">
                    <h2 class="font-display text-base font-semibold text-[var(--text-primary)]">Integrasi Performa</h2>
                    <a href="{{ route('settings', ['tab' => 'integrasi']) }}" class="text-xs text-[var(--brand)] hover:underline">Riwayat sync</a>
                </div>

                @php $igConnected = $instagramIntegration && $instagramIntegration->status === 'active'; @endphp

                <div class="flex items-center justify-between mb-2">
                    <p class="text-sm font-medium text-[var(--text-primary)]">Instagram</p>
                    <span class="badge {{ $instagramSyncing ? 'badge-warning' : ($igConnected ? 'badge-success' : 'badge-danger') }}">
                        {{ $instagramSyncing ? 'Menyinkronkan' : ($igConnected ? 'Aktif' : 'Terputus') }}
                    </span>
                </div>

                @if ($igConnected)
                    @php
                        // FINAL API COVERAGE GATE - label SAMA persis dengan
                        // Settings (satu kosakata, lihat catatan di sana).
                        $accountTypeLabel = match ($instagramIntegration->external_account_type) {
                            'BUSINESS' => 'Business',
                            'MEDIA_CREATOR', 'CREATOR' => 'Creator',
                            default => null,
                        };
                    @endphp
                    <p class="text-xs text-[var(--text-muted)] mb-3">
                        &commat;{{ $instagramIntegration->external_username }}
                        @if ($accountTypeLabel)
                            &middot; Akun {{ $accountTypeLabel }}
                        @endif
                    </p>

                    {{-- SYSTEM CONSISTENCY PASS (Part AH-AM) - Client Detail
                         dulu punya implementasi sync SENDIRI (form POST
                         langsung ke SettingsController::syncInstagram()/
                         ClientManagementController::syncInstagramAudience(),
                         BYPASS AnalyticsSyncOrchestrator total, 2 tombol
                         primer terpisah Instagram+Audience, TikTok pakai
                         polling custom sendiri ke endpoint client-management-
                         only) - SEKARANG satu "Perbarui Data" per platform,
                         SAMA PERSIS engine+presenter yang dipakai Analytics
                         & Settings (AnalyticsSyncOrchestrator + shared
                         public/js/analytics-sync-panel.js) - run yang mulai
                         dari sini kelihatan di Analytics/Settings juga,
                         begitu juga sebaliknya. --}}
                    <p id="ig-freshness" class="text-[11px] text-[var(--text-muted)] mb-2">Memuat status data...</p>
                    <div class="flex items-center gap-2 flex-wrap mb-2">
                        <button type="button" id="ig-sync-button"
                                class="text-xs font-medium text-white bg-[var(--brand)] px-3 py-1.5 rounded-lg hover:opacity-90 transition-opacity flex items-center gap-1 disabled:opacity-50 disabled:cursor-not-allowed">
                            <span class="material-symbols-outlined text-[14px]" id="ig-sync-icon">sync</span>
                            <span id="ig-sync-label">Perbarui Data</span>
                        </button>
                        <a href="{{ route('publishing-tracker.instagram.unmatched', $instagramIntegration) }}?return_to={{ urlencode(url()->full()) }}"
                           class="text-xs font-medium text-[var(--text-muted)] hover:text-[var(--text-secondary)]">Media belum ter-link</a>
                    </div>
                    <div id="ig-sync-panel" class="mb-2" hidden></div>

                    <details class="text-xs">
                        <summary class="cursor-pointer text-[var(--brand)] font-medium select-none">Sinkronisasi Konten Historis</summary>
                        <div class="mt-2 space-y-1.5">
                            <p class="text-[11px] text-[var(--text-muted)]">Gunakan ini untuk mengambil data bulan lama yang belum tersync.</p>
                            <form action="{{ route('settings.sync-instagram') }}" method="POST" class="flex items-center gap-2">
                                @csrf
                                <input type="hidden" name="client_id" value="{{ $client->id }}">
                                <input type="month" name="month" required max="{{ now()->format('Y-m') }}"
                                       class="text-xs border border-[var(--border)] rounded-lg px-2 py-1.5 bg-[var(--surface-card)] focus:outline-none focus:border-[#044b46]/40">
                                <button type="submit"
                                        class="text-xs font-medium text-white bg-[var(--brand)] px-3 py-1.5 rounded-lg hover:opacity-90 transition-opacity disabled:opacity-50 disabled:cursor-not-allowed">
                                    Sinkronkan Bulan Terpilih
                                </button>
                            </form>
                        </div>
                    </details>
                @elseif ($instagramOauthConfigured)
                    @if ($instagramIntegration && $instagramIntegration->last_error)
                        <p class="text-xs font-medium text-[var(--danger-text)] mb-1">Koneksi Instagram perlu perhatian</p>
                        <p class="text-xs text-[var(--danger-text)] mb-3">{{ $instagramIntegration->last_error }}</p>
                    @else
                        <p class="text-xs text-[var(--text-muted)] mb-3">Belum terhubung.</p>
                    @endif
                    <a href="{{ route('client-management.instagram.connect', $client) }}"
                       class="inline-flex items-center gap-1.5 text-xs font-medium text-white bg-[var(--brand)] px-3 py-1.5 rounded-lg hover:opacity-90 transition-opacity">
                        <span class="material-symbols-outlined text-[14px]">link</span>
                        {{ $instagramIntegration && $instagramIntegration->last_error ? 'Sambungkan Ulang Instagram' : 'Hubungkan Instagram' }}
                    </a>
                @else
                    <p class="text-xs text-[var(--text-muted)]">
                        OAuth belum dikonfigurasi. Isi <code>INSTAGRAM_CLIENT_ID</code>/<code>INSTAGRAM_CLIENT_SECRET</code> di <code>.env</code>
                        dan daftarkan redirect URI di Meta App Dashboard dulu.
                    </p>
                @endif

                {{-- TikTok - MIRROR blok Instagram di atas, TANPA Audience
                     Insights (TikTok Display API standar tidak menyediakan
                     demografis - lihat docs/TIKTOK_INTEGRATION.md). --}}
                @php $ttConnected = $tiktokIntegration && $tiktokIntegration->status === 'active'; @endphp

                <div class="flex items-center justify-between mb-2 mt-5 pt-5 border-t border-[var(--surface-muted)]">
                    <p class="text-sm font-medium text-[var(--text-primary)]">TikTok</p>
                    <span class="badge {{ $tiktokSyncing ? 'badge-warning' : ($ttConnected ? 'badge-success' : 'badge-danger') }}">
                        {{ $tiktokSyncing ? 'Menyinkronkan' : ($ttConnected ? 'Aktif' : 'Terputus') }}
                    </span>
                </div>

                @if ($ttConnected)
                    <p class="text-xs text-[var(--text-muted)] mb-1">&commat;{{ $tiktokIntegration->external_username }}</p>
                    <p class="text-[11px] text-[var(--text-muted)] mb-3">
                        @if ($tiktokStatsScopeGranted)
                            Followers: {{ $tiktokFollowerInsight?->follower_count !== null ? number_format($tiktokFollowerInsight->follower_count) : 'Belum ada data' }}
                        @else
                            Statistik profil tidak tersedia dari scope TikTok yang diberikan.
                        @endif
                    </p>

                    {{-- SYSTEM CONSISTENCY PASS (Part AH-AM) - lihat catatan
                         sama di blok Instagram di atas. Polling custom lama
                         (client-management.tiktok.sync-status, endpoint
                         TERPISAH dari analytics.sync-status) DIHAPUS - satu
                         engine, satu presenter. --}}
                    <p id="tt-freshness" class="text-[11px] text-[var(--text-muted)] mb-2">Memuat status data...</p>
                    <div class="flex items-center gap-2 flex-wrap mb-2">
                        <button type="button" id="tt-sync-button"
                                class="text-xs font-medium text-white bg-[var(--brand)] px-3 py-1.5 rounded-lg hover:opacity-90 transition-opacity flex items-center gap-1 disabled:opacity-50 disabled:cursor-not-allowed">
                            <span class="material-symbols-outlined text-[14px]" id="tt-sync-icon">sync</span>
                            <span id="tt-sync-label">Perbarui Data</span>
                        </button>
                        <a href="{{ route('publishing-tracker.tiktok.unmatched', $tiktokIntegration) }}?return_to={{ urlencode(url()->full()) }}"
                           class="text-xs font-medium text-[var(--text-muted)] hover:text-[var(--text-secondary)]">Video belum ter-link</a>
                    </div>
                    <div id="tt-sync-panel" class="mb-2" hidden></div>

                    @if (! $tiktokVideoListScopeGranted)
                        <p class="text-[11px] text-[var(--text-muted)] mb-2">Daftar video tidak tersedia dari scope TikTok yang diberikan.</p>
                    @elseif ($tiktokIntegration->last_synced_at && $tiktokVideoCount === 0)
                        <p class="text-[11px] text-[var(--text-muted)] mb-2">Tidak ada video yang dikembalikan TikTok untuk akun ini.</p>
                    @elseif ($tiktokVideoCount > 0)
                        <p class="text-[11px] text-[var(--text-muted)] mb-2">{{ $tiktokVideoCount }} video tersinkron</p>
                    @endif

                    <details class="text-xs">
                        <summary class="cursor-pointer text-[var(--brand)] font-medium select-none">Sinkronisasi Konten Historis</summary>
                        <div class="mt-2 space-y-1.5">
                            <p class="text-[11px] text-[var(--text-muted)]">Gunakan ini untuk mengambil data bulan lama yang belum tersync.</p>
                            <form action="{{ route('settings.sync-tiktok') }}" method="POST" class="flex items-center gap-2">
                                @csrf
                                <input type="hidden" name="client_id" value="{{ $client->id }}">
                                <input type="month" name="month" required max="{{ now()->format('Y-m') }}"
                                       class="text-xs border border-[var(--border)] rounded-lg px-2 py-1.5 bg-[var(--surface-card)] focus:outline-none focus:border-[#044b46]/40">
                                <button type="submit"
                                        class="text-xs font-medium text-white bg-[var(--brand)] px-3 py-1.5 rounded-lg hover:opacity-90 transition-opacity disabled:opacity-50 disabled:cursor-not-allowed">
                                    Sinkronkan Bulan Terpilih
                                </button>
                            </form>
                        </div>
                    </details>
                @elseif ($tiktokOauthConfigured)
                    @if ($tiktokIntegration && $tiktokIntegration->last_error)
                        <p class="text-xs font-medium text-[var(--danger-text)] mb-1">Koneksi TikTok perlu perhatian</p>
                        <p class="text-xs text-[var(--danger-text)] mb-3">{{ $tiktokIntegration->last_error }}</p>
                    @else
                        <p class="text-xs text-[var(--text-muted)] mb-3">Belum terhubung.</p>
                    @endif
                    <a href="{{ route('client-management.tiktok.connect', $client) }}"
                       class="inline-flex items-center gap-1.5 text-xs font-medium text-white bg-[var(--brand)] px-3 py-1.5 rounded-lg hover:opacity-90 transition-opacity">
                        <span class="material-symbols-outlined text-[14px]">link</span>
                        {{ $tiktokIntegration && $tiktokIntegration->last_error ? 'Sambungkan Ulang TikTok' : 'Hubungkan TikTok' }}
                    </a>
                @else
                    <p class="text-xs text-[var(--text-muted)]">
                        OAuth belum dikonfigurasi. Isi <code>TIKTOK_CLIENT_KEY</code>/<code>TIKTOK_CLIENT_SECRET</code> di <code>.env</code>
                        dan daftarkan redirect URI di TikTok Developer Portal dulu.
                    </p>
                @endif
            </div>

            <div class="card p-6">
                <div class="flex items-center justify-between mb-4">
                    <h2 class="font-display text-base font-semibold text-[var(--text-primary)]">Tim yang Menangani</h2>
                    <button type="button" @click="showPicModal = true" {{ $canManageClient ? '' : 'disabled' }}
                            title="{{ $canManageClient ? '' : 'Cuma CEO/Manager yang bisa mengatur tim' }}"
                            class="text-xs font-medium text-[var(--brand)] hover:underline disabled:opacity-40 disabled:cursor-not-allowed disabled:no-underline">Atur</button>
                </div>

                {{-- Satu-satunya sumber "siapa yang menangani client ini"
                     (user_client_assignments) - "satu orang = satu record",
                     tidak ada domain TeamMember terpisah lagi. TIDAK ada
                     konsep "Primary PIC" tunggal di sini (audit membuktikan
                     workbook sumber cuma punya 1 nama per client sebagai
                     snapshot account-manager historis, BUKAN bukti kuat
                     siapa satu-satunya penanggung jawab permanen - beberapa
                     client real terbukti ditangani lebih dari 1 orang). --}}
                @if ($client->assignedUsers->isEmpty())
                    <p class="text-sm text-[var(--text-muted)]">Belum ada akun sistem yang ditugaskan ke client ini.</p>
                @else
                    <div class="space-y-2.5">
                        @foreach ($client->assignedUsers as $staff)
                            @php $picActiveCount = $picActiveCounts[$staff->id] ?? 0; @endphp
                            <div class="flex items-center gap-2.5">
                                <div class="w-7 h-7 rounded-full bg-[var(--brand-solid)] text-white flex items-center justify-center text-xs font-semibold shrink-0 overflow-hidden">
                                    @if ($staff->avatar_url)
                                        <img src="{{ $staff->avatar_url }}" alt="" referrerpolicy="no-referrer" class="w-full h-full object-cover">
                                    @else
                                        {{ strtoupper(substr($staff->name, 0, 1)) }}
                                    @endif
                                </div>
                                <div class="min-w-0 flex-1">
                                    <p class="text-sm font-medium text-[var(--text-primary)] truncate">{{ $staff->name }}</p>
                                    <p class="text-xs text-[var(--text-muted)] truncate">{{ $staff->roleNamesLabel() }}
                                        @if ($picActiveCount > 0)
                                            &middot; {{ $picActiveCount }} konten aktif
                                        @endif
                                    </p>
                                </div>
                                @if ($canManageClient)
                                    <span x-data="tooltipHover('Keluarkan dari PIC')" class="contents">
                                        <button type="button" @click="removePic = {{ $staff->id }}"
                                                @mouseenter="onEnter($event)" @mouseleave="onLeave()"
                                                aria-label="Keluarkan dari PIC"
                                                class="w-7 h-7 flex items-center justify-center rounded-lg text-[var(--text-muted)] hover:bg-[var(--danger-tint)] hover:text-[var(--danger-text)] transition-colors shrink-0">
                                            <span class="material-symbols-outlined text-[16px]">person_remove</span>
                                        </button>
                                        @include('components.action-tooltip')
                                    </span>
                                @endif
                            </div>
                        @endforeach
                    </div>
                @endif
            </div>

            <div class="card p-6">
                <h2 class="font-display text-base font-semibold text-[var(--text-primary)] mb-4">Aset Klien</h2>

                @if ($client->asset_link)
                    <a href="{{ $client->asset_link }}" target="_blank" rel="noopener"
                       class="flex items-center gap-2.5 bg-[var(--brand-tint)] text-[var(--brand)] text-sm font-medium px-4 py-2.5 rounded-lg hover:bg-[var(--brand-tint-hover)] transition-colors">
                        <span class="material-symbols-outlined text-[17px]">folder_open</span>
                        Buka Google Drive
                        <span class="material-symbols-outlined text-[14px] ml-auto">open_in_new</span>
                    </a>
                @elseif ($canManageClient)
                    <p class="text-sm text-[var(--text-muted)]">Belum ada link aset. <a href="{{ route('client-management.edit', $client) }}" class="text-[var(--brand)] hover:underline">Tambahkan di Edit Klien</a>.</p>
                @else
                    <p class="text-sm text-[var(--text-muted)]">Belum ada link aset.</p>
                @endif
            </div>

            <div class="card p-6">
                <h2 class="text-sm font-semibold text-[var(--danger-text)] mb-3">Zona Berbahaya</h2>
                <form action="{{ route('client-management.destroy', $client) }}" method="POST"
                      onsubmit="return appConfirm(this, 'Yakin hapus {{ addslashes($client->name) }}? Kalau sudah punya riwayat konten, klien hanya akan dinonaktifkan, bukan dihapus permanen.', { danger: true })">
                    @csrf
                    @method('DELETE')
                    <button type="submit" {{ $canManageClient ? '' : 'disabled' }}
                            title="{{ $canManageClient ? '' : 'Cuma CEO/Manager yang bisa menghapus klien' }}"
                            class="btn-danger w-full disabled:opacity-40 disabled:cursor-not-allowed">
                        Hapus Klien
                    </button>
                </form>
            </div>

        </div>

    </div>

    {{-- Modal Ubah Paket --}}
    <template x-teleport="body">
        <div x-show="showPackageModal" x-cloak
             x-on:keydown.escape.window="showPackageModal = false"
             class="fixed inset-0 z-50 flex items-center justify-center p-4" style="display: none;">
            <div class="absolute inset-0 bg-[#14181a]/40" @click="showPackageModal = false"></div>

            <div x-show="showPackageModal" x-transition
                 role="dialog" aria-modal="true" aria-labelledby="package-modal-title" x-trap="showPackageModal"
                 class="relative bg-[var(--surface-card)] rounded-2xl shadow-xl w-full max-w-md max-h-[90vh] overflow-y-auto">
                <div class="flex items-center justify-between px-6 py-5 border-b border-[var(--border)]">
                    <div>
                        <h3 id="package-modal-title" class="font-display text-lg font-semibold text-[var(--text-primary)]">Ubah Paket</h3>
                        <p class="text-xs text-[var(--text-muted)] mt-0.5">Untuk {{ $client->name }}</p>
                    </div>
                    <button type="button" @click="showPackageModal = false" class="text-[var(--text-muted)] hover:text-[var(--text-secondary)]">
                        <span class="material-symbols-outlined text-[19px]">close</span>
                    </button>
                </div>

                @if ($packageTemplates->isEmpty())
                    <div class="px-6 py-5">
                        <p class="text-sm text-[var(--text-secondary)]">Belum ada paket tersedia. Tambahkan dulu di
                            <a href="{{ route('settings', ['tab' => 'data-pilihan', 'type' => 'package-template']) }}" class="text-[var(--brand)] hover:underline">Pengaturan &rarr; Data Pilihan &rarr; Paket</a>.</p>
                    </div>
                    <div class="flex items-center gap-3 px-6 py-4 border-t border-[var(--border)]">
                        <button type="button" @click="showPackageModal = false" class="btn-secondary">Tutup</button>
                    </div>
                @else
                    <form action="{{ route('client-management.package.update', $client) }}" method="POST">
                        @csrf @method('PUT')
                        <div class="px-6 py-5">
                            <label class="block text-xs font-medium text-[var(--text-muted)] uppercase mb-1.5">Pilih Paket</label>
                            <select name="package_template_id" required
                                    class="w-full border border-[var(--border)] rounded-lg px-3.5 py-2.5 text-sm bg-[var(--surface-card)] focus:outline-none focus:border-[#044b46]/40">
                                <option value="">-- Pilih Paket --</option>
                                @foreach ($packageTemplates as $template)
                                    <option value="{{ $template->id }}" {{ $client->activePackage?->package_template_id === $template->id ? 'selected' : '' }}>
                                        {{ $template->name }} ({{ $template->monthly_content_quota }} Konten / {{ $template->monthly_design_quota }} Desain)
                                    </option>
                                @endforeach
                            </select>
                        </div>
                        <div class="flex items-center gap-3 px-6 py-4 border-t border-[var(--border)]">
                            <button type="submit" class="btn-primary">Simpan</button>
                            <button type="button" @click="showPackageModal = false" class="btn-secondary">Batal</button>
                        </div>
                    </form>
                @endif
            </div>
        </div>
    </template>

    {{-- Modal Atur PIC --}}
    <template x-teleport="body">
        <div x-show="showPicModal" x-cloak
             x-on:keydown.escape.window="showPicModal = false"
             class="fixed inset-0 z-50 flex items-center justify-center p-4" style="display: none;">
            <div class="absolute inset-0 bg-[#14181a]/40" @click="showPicModal = false"></div>

            <div x-show="showPicModal" x-transition
                 role="dialog" aria-modal="true" aria-labelledby="pic-modal-title" x-trap="showPicModal"
                 class="relative bg-[var(--surface-card)] rounded-2xl shadow-xl w-full max-w-md max-h-[90vh] overflow-y-auto">
                <div class="flex items-center justify-between px-6 py-5 border-b border-[var(--border)]">
                    <div>
                        <h3 id="pic-modal-title" class="font-display text-lg font-semibold text-[var(--text-primary)]">Atur Akun Sistem</h3>
                        <p class="text-xs text-[var(--text-muted)] mt-0.5">Untuk {{ $client->name }}</p>
                    </div>
                    <button type="button" @click="showPicModal = false" class="text-[var(--text-muted)] hover:text-[var(--text-secondary)]">
                        <span class="material-symbols-outlined text-[19px]">close</span>
                    </button>
                </div>

                <form action="{{ route('client-management.pic.update', $client) }}" method="POST">
                    @csrf @method('PUT')
                    <div class="px-6 py-5">
                        <p class="text-xs font-semibold text-[var(--text-muted)] uppercase mb-3">Pilih Staff yang Diberi Akses Sistem</p>
                        @error('user_ids') <p class="text-xs text-[var(--danger-text)] mb-3">{{ $message }}</p> @enderror

                        <div class="space-y-2 max-h-72 overflow-y-auto">
                            @php
                                $assignedStaffIds = $client->assignedUsers->pluck('id')->toArray();
                            @endphp
                            @forelse ($staffOptions as $staff)
                                @php
                                    $isAssigned = in_array($staff->id, $assignedStaffIds);
                                    $lockChecked = $isAssigned && ($picActiveCounts[$staff->id] ?? 0) > 0;
                                @endphp
                                <label class="flex items-center gap-3 p-3 border border-[var(--border)] rounded-lg {{ $lockChecked ? '' : 'hover:bg-[var(--surface-page)] cursor-pointer' }}">
                                    <input type="checkbox" name="user_ids[]" value="{{ $staff->id }}"
                                           {{ $isAssigned ? 'checked' : '' }} {{ $lockChecked ? 'disabled' : '' }}
                                           class="rounded border-[var(--border-strong)] text-[var(--brand)] focus:ring-[var(--brand)]">
                                    {{-- Checkbox disabled tidak ikut ke-submit - hidden input jaga dia tetap terhitung "assigned" di request. --}}
                                    @if ($lockChecked)
                                        <input type="hidden" name="user_ids[]" value="{{ $staff->id }}">
                                    @endif
                                    <div class="min-w-0">
                                        <p class="text-sm font-medium text-[var(--text-primary)]">{{ $staff->name }}</p>
                                        <p class="text-xs text-[var(--text-muted)]">{{ $staff->roleNamesLabel() }}
                                            @if ($lockChecked)
                                                &middot; {{ $picActiveCounts[$staff->id] }} konten aktif, keluarkan lewat tombol "Keluarkan" di kartu PIC
                                            @endif
                                        </p>
                                    </div>
                                </label>
                            @empty
                                <p class="text-xs text-[var(--text-muted)] italic">Belum ada staff internal aktif.</p>
                            @endforelse
                        </div>
                    </div>

                    <div class="flex items-center gap-3 px-6 py-4 border-t border-[var(--border)]">
                        <button type="submit" class="btn-primary">Simpan</button>
                        <button type="button" @click="showPicModal = false" class="btn-secondary">Batal</button>
                    </div>
                </form>
            </div>
        </div>
    </template>

    {{-- Modal Keluarkan dari PIC - satu per staff ter-assign, biar bisa
         nawarin pengganti kalau dia masih PIC di konten aktif client ini. --}}
    @foreach ($client->assignedUsers as $staff)
        @php $picActiveCount = $picActiveCounts[$staff->id] ?? 0; @endphp
        <template x-teleport="body">
            <div x-show="removePic === {{ $staff->id }}" x-cloak
                 x-on:keydown.escape.window="removePic = null"
                 class="fixed inset-0 z-50 flex items-center justify-center p-4" style="display: none;">
                <div class="absolute inset-0 bg-[#14181a]/40" @click="removePic = null"></div>

                <div x-show="removePic === {{ $staff->id }}" x-transition
                     role="dialog" aria-modal="true" aria-labelledby="remove-pic-modal-title-{{ $staff->id }}" x-trap="removePic === {{ $staff->id }}"
                     class="relative bg-[var(--surface-card)] rounded-2xl shadow-xl w-full max-w-md max-h-[90vh] overflow-y-auto">
                    <div class="flex items-center justify-between px-6 py-5 border-b border-[var(--border)]">
                        <div>
                            <h3 id="remove-pic-modal-title-{{ $staff->id }}" class="font-display text-lg font-semibold text-[var(--text-primary)]">Keluarkan dari PIC</h3>
                            <p class="text-xs text-[var(--text-muted)] mt-0.5">{{ $staff->name }} &middot; {{ $client->name }}</p>
                        </div>
                        <button type="button" @click="removePic = null" class="text-[var(--text-muted)] hover:text-[var(--text-secondary)]">
                            <span class="material-symbols-outlined text-[19px]">close</span>
                        </button>
                    </div>

                    <form action="{{ route('client-management.pic.remove', [$client, $staff]) }}" method="POST">
                        @csrf @method('DELETE')
                        <div class="px-6 py-5 space-y-4">
                            @if ($picActiveCount > 0)
                                <div class="bg-[var(--warning-tint)] text-[var(--warning-text)] text-xs p-3 rounded-lg">
                                    {{ $staff->name }} masih PIC di <strong>{{ $picActiveCount }} konten aktif</strong> untuk {{ $client->name }}. Pilih pengganti supaya konten-konten itu tidak nyangkut.
                                </div>
                                <div>
                                    <label class="block text-xs font-medium text-[var(--text-muted)] uppercase mb-1.5">Pindahkan Semua Tugas Ke</label>
                                    <select name="replacement_user_id" required
                                            class="w-full border border-[var(--border)] rounded-lg px-3.5 py-2.5 text-sm bg-[var(--surface-card)] focus:outline-none focus:border-[#044b46]/40">
                                        <option value="">-- Pilih Pengganti --</option>
                                        @foreach ($client->assignedUsers as $otherStaff)
                                            @if ($otherStaff->id !== $staff->id)
                                                <option value="{{ $otherStaff->id }}">{{ $otherStaff->name }} ({{ $otherStaff->roleNamesLabel() }})</option>
                                            @endif
                                        @endforeach
                                    </select>
                                    @if ($client->assignedUsers->count() <= 1)
                                        <p class="text-xs text-[var(--danger-text)] mt-1.5">Belum ada PIC lain di client ini buat jadi pengganti - tambahkan PIC lain dulu lewat "Atur PIC".</p>
                                    @endif
                                </div>
                            @else
                                <p class="text-sm text-[var(--text-secondary)]">Yakin keluarkan <strong class="text-[var(--text-primary)]">{{ $staff->name }}</strong> dari PIC {{ $client->name }}?</p>
                            @endif
                        </div>
                        <div class="flex items-center gap-3 px-6 py-4 border-t border-[var(--border)]">
                            <button type="submit" class="btn-danger">Keluarkan</button>
                            <button type="button" @click="removePic = null" class="btn-secondary">Batal</button>
                        </div>
                    </form>
                </div>
            </div>
        </template>
    @endforeach
</div>

@if ($instagramIntegration || $tiktokIntegration)
    {{-- SYSTEM CONSISTENCY PASS (Part AH-AM) - SATU engine (AnalyticsSyncOrchestrator)
         + SATU presenter (public/js/analytics-sync-panel.js), SAMA PERSIS
         dipakai Analytics & Settings - run yang mulai dari sini terlihat
         dari kedua halaman itu juga, dan sebaliknya (Part AJ, "shared
         active run across all pages"). --}}
    <script src="{{ asset('js/analytics-sync-panel.js') }}"></script>
    <script>
        (function () {
            if (! window.AnalyticsSyncPanel) return;
            var clientId = {{ (int) $client->id }};
            var csrfToken = document.querySelector('meta[name="csrf-token"]').content;
            var urls = {
                dispatch: @json(route('analytics.sync')),
                status: @json(route('analytics.sync-status')),
                retryTask: @json(route('analytics.sync.retry-task')),
                retryFailedItems: @json(route('analytics.sync.retry-failed-items')),
            };
            @if ($instagramIntegration)
                window.AnalyticsSyncPanel.createSyncController({
                    clientId: clientId,
                    platformId: {{ (int) $instagramIntegration->platform_id }},
                    groups: [window.AnalyticsSyncPanel.DEFAULT_PLATFORM_GROUPS[0]],
                    urls: urls,
                    reconnectUrl: @json(route('client-management.instagram.connect', $client)),
                    csrfToken: csrfToken,
                    elements: {
                        button: document.getElementById('ig-sync-button'),
                        icon: document.getElementById('ig-sync-icon'),
                        label: document.getElementById('ig-sync-label'),
                        freshness: document.getElementById('ig-freshness'),
                        panel: document.getElementById('ig-sync-panel'),
                    },
                });
            @endif
            @if ($tiktokIntegration)
                window.AnalyticsSyncPanel.createSyncController({
                    clientId: clientId,
                    platformId: {{ (int) $tiktokIntegration->platform_id }},
                    groups: [window.AnalyticsSyncPanel.DEFAULT_PLATFORM_GROUPS[1]],
                    urls: urls,
                    reconnectUrl: @json(route('client-management.tiktok.connect', $client)),
                    csrfToken: csrfToken,
                    elements: {
                        button: document.getElementById('tt-sync-button'),
                        icon: document.getElementById('tt-sync-icon'),
                        label: document.getElementById('tt-sync-label'),
                        freshness: document.getElementById('tt-freshness'),
                        panel: document.getElementById('tt-sync-panel'),
                    },
                });
            @endif
        })();
    </script>
@endif
@endsection