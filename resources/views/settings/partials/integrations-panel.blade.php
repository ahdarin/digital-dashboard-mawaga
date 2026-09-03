@php
    $canManageSettings = auth()->user()->hasPermissionTo('settings', 'manage');
    $canManageClientIntegration = auth()->user()->hasPermissionTo('client', 'manage');
    // Live status polling (lihat script di bawah) pakai endpoint yang sama
    // dengan tombol "Sinkronkan Data" di halaman Performa
    // (AnalyticsSyncOrchestrator::statusForClient(), route analytics.sync-
    // status), yang di-gate 'analytics,view'. Cek di sini biar user yang
    // punya settings,manage/client,manage TAPI TIDAK analytics,view
    // (kombinasi tidak lazim, tapi bukan tidak mungkin) tidak diam-diam
    // memicu fetch() ke endpoint yang bakal 403 - fallback-nya cukup
    // tampilan statis apa adanya seperti sebelumnya.
    $canPollSyncStatus = auth()->user()->hasPermissionTo('analytics', 'view');
@endphp

<div class="flex flex-col sm:flex-row sm:items-center justify-between gap-2 mb-5">
    <p class="text-[var(--text-secondary)] text-sm">Kelola koneksi API per client dan pantau riwayat sinkronisasi/import data.</p>
</div>

@if (session('import_success'))
    <div class="mb-5 bg-[var(--brand-tint)] border border-[var(--brand-tint-border)] rounded-lg p-4">
        <p class="text-sm font-medium text-[var(--brand)] flex items-center gap-2">
            <span class="material-symbols-outlined text-[18px]">check_circle</span>
            {{ session('import_success') }}
        </p>
    </div>
@endif

@if (session('import_error'))
    <div class="mb-5 bg-[var(--danger-tint)] border border-[var(--danger-border)] rounded-lg p-4">
        <p class="text-sm font-medium text-[var(--danger-text)] flex items-center gap-2">
            <span class="material-symbols-outlined text-[18px]">error</span>
            {{ session('import_error') }}
        </p>
    </div>
@endif

{{-- Client selector - filter utama halaman, BUKAN assignment integration.
     Ganti client cuma ganti apa yang DITAMPILKAN, nggak nulis apapun. --}}
@if ($clientOptions->isEmpty())
    <div class="card p-8 text-center">
        <span class="material-symbols-outlined text-[var(--icon-disabled)] text-[26px] mb-2 block">business</span>
        <p class="text-sm text-[var(--text-muted)]">Belum ada klien yang dapat dikelola.</p>
    </div>
@else
    <div class="card p-5 mb-6">
        <form method="GET" class="flex items-center gap-3 flex-wrap">
            <input type="hidden" name="tab" value="integrasi">
            <label class="text-xs font-medium text-[var(--text-muted)] uppercase tracking-wide">Klien</label>
            <select name="client_id" onchange="this.form.submit()"
                    class="text-sm border border-[var(--border)] rounded-lg px-3 py-2 bg-[var(--surface-card)] focus:outline-none focus:border-[#044b46]/40 min-w-[240px]">
                @foreach ($clientOptions as $c)
                    <option value="{{ $c->id }}" {{ (string) $selectedClientId === (string) $c->id ? 'selected' : '' }}>{{ $c->name }}</option>
                @endforeach
            </select>
        </form>
    </div>

@if ($selectedClient)

    {{-- Integrasi Otomatis --}}
    <div class="card p-6 mb-6">
        <h2 class="font-display text-lg font-semibold text-[var(--text-primary)] mb-1">Integrasi Otomatis</h2>
        <p class="text-xs text-[var(--text-muted)] mb-5">Koneksi API real-time untuk {{ $selectedClient->name }}.</p>

        {{-- Instagram --}}
        <div class="border border-[var(--border)] rounded-xl p-5 mb-4">
            <div class="flex items-center justify-between mb-1">
                <p class="text-sm font-semibold text-[var(--text-primary)]">Instagram</p>
                @if ($instagramCard['connected'])
                    <span class="badge badge-success inline-flex items-center gap-1">
                        <span class="w-1.5 h-1.5 rounded-full bg-current"></span> Terhubung
                    </span>
                @else
                    <span class="badge badge-neutral">Belum Terhubung</span>
                @endif
            </div>

            @if ($instagramCard['connected'])
                @php
                    $integration = $instagramCard['integration'];
                    // FINAL API COVERAGE GATE - diagnostik saja (Part 4,
                    // "INTERNAL_METADATA" - bukan metric analytics utama),
                    // berguna karena sebagian insight metric Meta beda
                    // ketersediaannya per tipe akun.
                    $accountTypeLabel = match ($integration->external_account_type) {
                        'BUSINESS' => 'Business',
                        'MEDIA_CREATOR', 'CREATOR' => 'Creator',
                        default => null,
                    };
                @endphp
                <p class="text-xs text-[var(--text-muted)] mb-4">
                    &commat;{{ $integration->external_username }}
                    @if ($accountTypeLabel)
                        &middot; Akun {{ $accountTypeLabel }}
                    @endif
                </p>

                {{-- SETTINGS/ANALYTICS SYNC UX CONSISTENCY FIX - SATU aksi
                     "Perbarui Data" per platform (Langkah 1), dispatch/poll
                     lewat endpoint SAMA PERSIS dengan halaman Performa
                     (analytics.sync / analytics.sync-status ->
                     AnalyticsSyncOrchestrator, Langkah 2) - content+audiens
                     TAMPIL SEBAGAI SATU pengalaman Instagram (Langkah 4),
                     bukan 2 tombol/status terpisah lagi. Rendering-nya
                     REUSE public/js/analytics-sync-panel.js (Langkah 11),
                     BUKAN implementasi kedua yang independen. --}}
                <div class="border-t border-[var(--surface-muted)] pt-3">
                    <p id="ig-freshness" class="text-xs text-[var(--text-secondary)] mb-2">Memuat status data...</p>

                    @if ($canManageSettings)
                        <button type="button" id="ig-sync-button"
                                class="text-xs font-medium text-white bg-[var(--brand)] px-3 py-1.5 rounded-lg hover:opacity-90 transition-opacity flex items-center gap-1 disabled:opacity-50 disabled:cursor-not-allowed">
                            <span class="material-symbols-outlined text-[14px]" id="ig-sync-icon">sync</span>
                            <span id="ig-sync-label">Perbarui Data</span>
                        </button>
                    @endif

                    <div id="ig-sync-panel" class="mt-3" hidden></div>

                    @if ($canManageSettings)
                        {{-- Sinkronisasi bulan tertentu - fitur TERPISAH dari
                             "Perbarui Data" (backfill 1 bulan spesifik, bukan
                             "ambil data terbaru") - SENGAJA tetap jalur lama
                             (settings.sync-instagram + $month), dikumpulkan
                             di disclosure sekunder biar TIDAK bersaing
                             sebagai aksi utama (Langkah 12, "avoid multiple
                             sync buttons"). --}}
                        <details class="text-xs mt-3">
                            <summary class="cursor-pointer text-[var(--brand)] font-medium select-none">Sinkronisasi Konten Historis</summary>
                            <div class="mt-2 flex items-center gap-2">
                                <form action="{{ route('settings.sync-instagram') }}" method="POST" class="flex items-center gap-2">
                                    @csrf
                                    <input type="hidden" name="client_id" value="{{ $selectedClient->id }}">
                                    <input type="month" name="month" required max="{{ now()->format('Y-m') }}"
                                           class="text-xs border border-[var(--border)] rounded-lg px-2 py-1.5 bg-[var(--surface-card)] focus:outline-none focus:border-[#044b46]/40">
                                    <button type="submit"
                                            class="text-xs font-medium text-white bg-[var(--brand)] px-3 py-1.5 rounded-lg hover:opacity-90 transition-opacity disabled:opacity-50 disabled:cursor-not-allowed">
                                        Sinkronkan Bulan Terpilih
                                    </button>
                                </form>
                            </div>
                        </details>
                    @endif
                </div>

                <div class="border-t border-[var(--surface-muted)] pt-3 mt-3 flex items-center gap-3 flex-wrap">
                    <a href="{{ route('publishing-tracker.instagram.unmatched', $integration) }}?return_to={{ urlencode(url()->full()) }}"
                       class="text-xs font-medium text-[var(--text-muted)] hover:text-[var(--text-secondary)]">Media belum ter-link</a>
                    @if ($instagramOauthConfigured && $canManageClientIntegration)
                        <a href="{{ route('client-management.instagram.connect', $selectedClient) }}"
                           class="text-xs font-medium text-[var(--text-muted)] hover:text-[var(--text-secondary)]">Sambungkan Ulang Instagram</a>
                    @endif
                </div>
            @elseif ($instagramOauthConfigured && $canManageClientIntegration)
                <p class="text-sm text-[var(--text-secondary)] mb-4 max-w-md">Hubungkan akun Instagram Professional client untuk mengambil data performa dan audience secara otomatis.</p>
                <a href="{{ route('client-management.instagram.connect', $selectedClient) }}"
                   class="inline-flex items-center gap-1.5 text-xs font-medium text-white bg-[var(--brand)] px-3 py-1.5 rounded-lg hover:opacity-90 transition-opacity">
                    <span class="material-symbols-outlined text-[14px]">link</span> Hubungkan Instagram
                </a>
            @elseif ($instagramOauthConfigured && ! $canManageClientIntegration)
                <p class="text-xs text-[var(--text-muted)]">Belum terhubung. Hubungi CEO/Manager untuk menyambungkan akun Instagram.</p>
            @else
                <p class="text-xs text-[var(--text-muted)]">
                    OAuth belum dikonfigurasi. Isi <code>INSTAGRAM_CLIENT_ID</code>/<code>INSTAGRAM_CLIENT_SECRET</code> di <code>.env</code>
                    dan daftarkan redirect URI di Meta App Dashboard dulu.
                </p>
            @endif
        </div>

        {{-- TikTok - Official TikTok for Developers (Login Kit + Display
             API v2). Mirror struktur kartu Instagram di atas, TANPA
             "Audience Insights" (TikTok Display API standar tidak
             menyediakan demografis - lihat docs/TIKTOK_INTEGRATION.md).
             follower_count (kalau scope user.info.stats granted) tampil
             ringkas di dalam kartu Content Analytics, bukan card terpisah. --}}
        <div class="border border-[var(--border)] rounded-xl p-5">
            <div class="flex items-center justify-between mb-1">
                <p class="text-sm font-semibold text-[var(--text-primary)]">TikTok</p>
                @if ($tiktokCard['connected'])
                    <span class="badge badge-success inline-flex items-center gap-1">
                        <span class="w-1.5 h-1.5 rounded-full bg-current"></span> Terhubung
                    </span>
                @else
                    <span class="badge badge-neutral">Belum Terhubung</span>
                @endif
            </div>

            @if ($tiktokCard['connected'])
                @php $tiktokIntegration = $tiktokCard['integration']; @endphp
                <p class="text-xs text-[var(--text-muted)] mb-4">&commat;{{ $tiktokIntegration->external_username }}</p>

                <div class="border-t border-[var(--surface-muted)] pt-3">
                    {{-- follower_count dkk - NULL != 0 (Langkah 9): kalau
                         scope user.info.stats belum granted, baris ini
                         disembunyikan sama sekali, BUKAN tampil "0". --}}
                    @if ($tiktokCard['has_stats_scope'])
                        <p class="text-[11px] text-[var(--text-muted)] mb-2">
                            Pengikut: {{ $tiktokCard['follower_count'] !== null ? number_format($tiktokCard['follower_count']) : 'Belum pernah sync' }}
                        </p>
                    @else
                        <p class="text-[11px] text-[var(--text-muted)] italic mb-2">Data followers tidak tersedia melalui TikTok API (scope belum disetujui).</p>
                    @endif

                    {{-- SETTINGS/ANALYTICS SYNC UX CONSISTENCY FIX - SATU
                         aksi "Perbarui Data" (Langkah 1), MIRROR kartu
                         Instagram di atas (Langkah 11, shared JS module). --}}
                    <p id="tt-freshness" class="text-xs text-[var(--text-secondary)] mb-2">Memuat status data...</p>

                    @if ($canManageSettings)
                        <button type="button" id="tt-sync-button"
                                class="text-xs font-medium text-white bg-[var(--brand)] px-3 py-1.5 rounded-lg hover:opacity-90 transition-opacity flex items-center gap-1 disabled:opacity-50 disabled:cursor-not-allowed">
                            <span class="material-symbols-outlined text-[14px]" id="tt-sync-icon">sync</span>
                            <span id="tt-sync-label">Perbarui Data</span>
                        </button>
                    @endif

                    <div id="tt-sync-panel" class="mt-3" hidden></div>

                    @if ($canManageSettings)
                        <details class="text-xs mt-3">
                            <summary class="cursor-pointer text-[var(--brand)] font-medium select-none">Sinkronisasi Konten Historis</summary>
                            <div class="mt-2 flex items-center gap-2">
                                <form action="{{ route('settings.sync-tiktok') }}" method="POST" class="flex items-center gap-2">
                                    @csrf
                                    <input type="hidden" name="client_id" value="{{ $selectedClient->id }}">
                                    <input type="month" name="month" required max="{{ now()->format('Y-m') }}"
                                           class="text-xs border border-[var(--border)] rounded-lg px-2 py-1.5 bg-[var(--surface-card)] focus:outline-none focus:border-[#044b46]/40">
                                    <button type="submit"
                                            class="text-xs font-medium text-white bg-[var(--brand)] px-3 py-1.5 rounded-lg hover:opacity-90 transition-opacity disabled:opacity-50 disabled:cursor-not-allowed">
                                        Sinkronkan Bulan Terpilih
                                    </button>
                                </form>
                            </div>
                        </details>
                    @endif
                </div>

                <div class="border-t border-[var(--surface-muted)] pt-3 mt-3 flex items-center gap-3 flex-wrap">
                    <a href="{{ route('publishing-tracker.tiktok.unmatched', $tiktokIntegration) }}?return_to={{ urlencode(url()->full()) }}"
                       class="text-xs font-medium text-[var(--text-muted)] hover:text-[var(--text-secondary)]">Video belum ter-link</a>
                    @if ($tiktokOauthConfigured && $canManageClientIntegration)
                        <a href="{{ route('client-management.tiktok.connect', $selectedClient) }}"
                           class="text-xs font-medium text-[var(--text-muted)] hover:text-[var(--text-secondary)]">Sambungkan Ulang TikTok</a>
                    @endif
                </div>
            @elseif ($tiktokOauthConfigured && $canManageClientIntegration)
                <p class="text-sm text-[var(--text-secondary)] mb-4 max-w-md">Hubungkan akun TikTok client untuk mengambil data performa video secara otomatis.</p>
                <a href="{{ route('client-management.tiktok.connect', $selectedClient) }}"
                   class="inline-flex items-center gap-1.5 text-xs font-medium text-white bg-[var(--brand)] px-3 py-1.5 rounded-lg hover:opacity-90 transition-opacity">
                    <span class="material-symbols-outlined text-[14px]">link</span> Hubungkan TikTok
                </a>
            @elseif ($tiktokOauthConfigured && ! $canManageClientIntegration)
                <p class="text-xs text-[var(--text-muted)]">Belum terhubung. Hubungi CEO/Manager untuk menyambungkan akun TikTok.</p>
            @else
                <p class="text-xs text-[var(--text-muted)]">
                    OAuth belum dikonfigurasi. Isi <code>TIKTOK_CLIENT_KEY</code>/<code>TIKTOK_CLIENT_SECRET</code> di <code>.env</code>
                    dan daftarkan redirect URI di TikTok Developer Portal dulu.
                </p>
            @endif
        </div>
    </div>

    {{-- Import Data Manual - dipisah jelas dari Integrasi Otomatis di
         atas, biar nggak kecampur seolah CSV = API (Langkah 12). --}}
    @if ($canManageSettings)
        <div class="card p-6 mb-6">
            <h2 class="font-display text-lg font-semibold text-[var(--text-primary)] mb-1">Import Data Manual</h2>
            <p class="text-xs text-[var(--text-muted)] mb-4">Fallback manual - dipakai kalau API belum tersedia atau perlu isi data historis di luar jangkauan API.</p>
            <div class="flex items-center gap-3 flex-wrap">
                <a href="{{ route('settings.import') }}" class="text-sm font-medium text-[var(--brand)] hover:underline flex items-center gap-1.5">
                    <span class="material-symbols-outlined text-[16px]">upload_file</span> Import Data Performa
                </a>
                <a href="{{ route('analytics', ['tab' => 'audience', 'client_id' => $selectedClient->id]) }}" class="text-sm font-medium text-[var(--brand)] hover:underline flex items-center gap-1.5">
                    <span class="material-symbols-outlined text-[16px]">upload_file</span> Import Data Audiens
                </a>
            </div>
        </div>
    @endif

@endif

{{-- Sync Log - default scoped ke client yang dipilih (Langkah 13), "All
     Clients" tetap boleh diakses eksplisit lewat link. --}}
<div class="card overflow-hidden">
    <div class="p-6 pb-4 flex flex-col sm:flex-row sm:items-center justify-between gap-3">
        <div>
            <h2 class="font-display text-lg font-semibold text-[var(--text-primary)]">Log Sinkronisasi</h2>
            <p class="text-xs text-[var(--text-muted)] mt-0.5">
                {{ $logsAllClients ? 'Menampilkan semua client.' : ($selectedClient ? 'Menampilkan log '.$selectedClient->name.' saja.' : '') }}
            </p>
        </div>

        <form method="GET" class="flex items-center gap-2.5 flex-wrap">
            <input type="hidden" name="tab" value="integrasi">
            <input type="hidden" name="client_id" value="{{ $selectedClientId }}">
            <select name="status" onchange="this.form.submit()"
                    class="text-sm border border-[var(--border)] rounded-lg px-3 bg-[var(--surface-card)] focus:outline-none focus:border-[#044b46]/40 h-[40px]">
                <option value="">Semua Status</option>
                <option value="success" {{ request('status') === 'success' ? 'selected' : '' }}>Berhasil</option>
                <option value="failed" {{ request('status') === 'failed' ? 'selected' : '' }}>Gagal</option>
                <option value="pending" {{ request('status') === 'pending' ? 'selected' : '' }}>Menunggu</option>
            </select>
            <div class="relative">
                <span class="material-symbols-outlined absolute left-3 top-1/2 -translate-y-1/2 text-[var(--text-muted)] text-[17px] pointer-events-none">calendar_month</span>
                <input type="text" name="date" value="{{ request('date') }}" data-flatpickr="date" data-autosubmit="true" autocomplete="off"
                       class="border border-[var(--border)] rounded-lg pl-9 pr-3 text-sm bg-[var(--surface-card)] focus:outline-none focus:border-[#044b46]/40 h-[40px] w-[150px]" readonly>
            </div>
            @if ($logsAllClients)
                <input type="hidden" name="all_clients" value="1">
                <a href="{{ route('settings', array_merge(['tab' => 'integrasi', 'client_id' => $selectedClientId], request()->only(['status', 'date']))) }}"
                   class="text-xs text-[var(--brand)] font-medium hover:underline">Kembali ke {{ $selectedClient->name ?? 'client ini' }}</a>
            @else
                <a href="{{ route('settings', array_merge(['tab' => 'integrasi', 'all_clients' => 1], request()->only(['status', 'date']))) }}"
                   class="text-xs text-[var(--text-muted)] hover:text-[var(--text-secondary)]">Semua Klien</a>
            @endif
            @if (request('status') || request('date'))
                <a href="{{ route('settings', ['tab' => 'integrasi', 'client_id' => $selectedClientId, 'all_clients' => $logsAllClients ? 1 : null]) }}" class="text-xs text-[var(--text-muted)] hover:text-[var(--text-secondary)]">Atur Ulang</a>
            @endif
        </form>
    </div>

    @php
        $sourceTypeLabels = [
            'performance_csv_import' => 'Import CSV Performa',
            'audience_csv_import' => 'Import CSV Audiens',
            'api_sync' => 'Sinkronisasi API Konten',
            'audience_api_sync' => 'Sinkronisasi API Audiens',
        ];
        $syncStatusLabels = ['success' => 'Berhasil', 'failed' => 'Gagal', 'pending' => 'Menunggu'];
    @endphp

    @if ($syncLogs->isEmpty())
        <div class="px-6 py-12 text-center">
            <span class="material-symbols-outlined text-[var(--icon-disabled)] text-[26px] mb-2 block">history</span>
            <p class="text-sm text-[var(--text-muted)]">Belum ada riwayat sinkronisasi/import.</p>
            <p class="text-xs text-[var(--text-muted)] mt-1">Riwayat muncul di sini begitu ada import CSV performa atau sinkronisasi API.</p>
        </div>
    @else
        <div class="overflow-x-auto hidden sm:block">
            <table class="w-full text-sm text-left">
                <thead class="bg-[var(--surface-page)]">
                    <tr class="text-[var(--text-muted)] text-[11px] uppercase tracking-wide">
                        <th class="px-6 py-3 font-medium whitespace-nowrap">Tanggal</th>
                        <th class="px-4 py-3 font-medium whitespace-nowrap">Klien</th>
                        <th class="px-4 py-3 font-medium whitespace-nowrap">Platform</th>
                        <th class="px-4 py-3 font-medium whitespace-nowrap">Jenis Sumber</th>
                        <th class="px-4 py-3 font-medium whitespace-nowrap">Diimpor Oleh</th>
                        <th class="px-6 py-3 font-medium whitespace-nowrap">Status</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($syncLogs as $log)
                        <tr class="border-t border-[var(--surface-muted)]">
                            <td class="px-6 py-3.5 text-[var(--text-secondary)] whitespace-nowrap">{{ $log->created_at->format('d M Y, H:i') }}</td>
                            <td class="px-4 py-3.5 font-medium text-[var(--text-primary)] whitespace-nowrap">{{ $log->client->name ?? '-' }}</td>
                            <td class="px-4 py-3.5 text-[var(--text-secondary)] whitespace-nowrap">{{ $log->platform->name ?? 'Campuran' }}</td>
                            <td class="px-4 py-3.5 text-[var(--text-secondary)] whitespace-nowrap">{{ $sourceTypeLabels[$log->source_type] ?? \Illuminate\Support\Str::headline($log->source_type) }}</td>
                            <td class="px-4 py-3.5 text-[var(--text-secondary)] whitespace-nowrap">{{ $log->importedBy->name ?? '-' }}</td>
                            <td class="px-6 py-3.5">
                                <span class="badge
                                    {{ $log->status === 'success' ? 'badge-success' : '' }}
                                    {{ $log->status === 'failed' ? 'badge-danger' : '' }}
                                    {{ $log->status === 'pending' ? 'badge-warning' : '' }}">
                                    {{ $syncStatusLabels[$log->status] ?? ucfirst($log->status) }}
                                </span>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        {{-- Mobile accordion list --}}
        <div class="sm:hidden divide-y divide-[var(--surface-muted)]">
            @foreach ($syncLogs as $log)
                <div x-data="{ open: false }" class="px-4">
                    <button type="button" class="w-full text-left py-3.5 flex items-center justify-between gap-3 cursor-pointer" @click="open = !open" :aria-expanded="open">
                        <div class="min-w-0">
                            <p class="text-sm font-medium text-[var(--text-primary)] truncate">{{ $log->client->name ?? '-' }}</p>
                            <p class="text-xs text-[var(--text-muted)] mt-0.5">{{ $log->created_at->format('d M Y, H:i') }}</p>
                        </div>
                        <div class="flex items-center gap-2 shrink-0">
                            <span class="badge
                                {{ $log->status === 'success' ? 'badge-success' : '' }}
                                {{ $log->status === 'failed' ? 'badge-danger' : '' }}
                                {{ $log->status === 'pending' ? 'badge-warning' : '' }}">
                                {{ $syncStatusLabels[$log->status] ?? ucfirst($log->status) }}
                            </span>
                            <span class="material-symbols-outlined text-[var(--text-muted)] text-[18px] transition-transform" :class="open ? 'rotate-180' : ''">expand_more</span>
                        </div>
                    </button>
                    <div x-show="open" x-cloak x-transition class="pb-4 -mt-1 space-y-2 text-sm">
                        <div class="flex justify-between gap-3">
                            <span class="text-[var(--text-muted)]">Platform</span>
                            <span class="text-[var(--text-primary)] text-right">{{ $log->platform->name ?? 'Campuran' }}</span>
                        </div>
                        <div class="flex justify-between gap-3">
                            <span class="text-[var(--text-muted)]">Jenis Sumber</span>
                            <span class="text-[var(--text-primary)] text-right">{{ $sourceTypeLabels[$log->source_type] ?? \Illuminate\Support\Str::headline($log->source_type) }}</span>
                        </div>
                        <div class="flex justify-between gap-3">
                            <span class="text-[var(--text-muted)]">Diimpor Oleh</span>
                            <span class="text-[var(--text-primary)] text-right">{{ $log->importedBy->name ?? '-' }}</span>
                        </div>
                    </div>
                </div>
            @endforeach
        </div>

        <div class="px-6 py-4 border-t border-[var(--surface-muted)]">{{ $syncLogs->links() }}</div>
    @endif
</div>

{{-- SETTINGS/ANALYTICS SYNC UX CONSISTENCY FIX - dispatch/poll/render/retry
     SEKARANG lewat public/js/analytics-sync-panel.js (Langkah 11, "do not
     maintain two independently-diverging implementations of sync status
     UI") - SATU controller per KARTU PLATFORM (Langkah 1, "one update
     action per platform" - beda dari Analytics yang 1 tombol bisa
     men-scope ke 1+ platform tergantung filter global). Endpoint SAMA
     PERSIS dengan halaman Performa (analytics.sync/analytics.sync-status,
     Langkah 2/6 - "Settings and Analytics are two views of one shared
     server-side sync state", jadi dispatch dari salah satu SELALU terlihat
     di keduanya, TIDAK PERNAH duplikat run). --}}
@if ($selectedClient && $canPollSyncStatus)
<script src="{{ asset('js/analytics-sync-panel.js') }}"></script>
<script>
    (function () {
        if (! window.AnalyticsSyncPanel) return;

        var clientId = {{ (int) $selectedClient->id }};
        var csrfToken = document.querySelector('meta[name="csrf-token"]').content;
        var urls = {
            dispatch: @json(route('analytics.sync')),
            status: @json(route('analytics.sync-status')),
            retryTask: @json(route('analytics.sync.retry-task')),
            retryFailedItems: @json(route('analytics.sync.retry-failed-items')),
        };

        @if ($instagramCard['connected'])
            window.AnalyticsSyncPanel.createSyncController({
                clientId: clientId,
                platformId: {{ (int) $instagramCard['integration']->platform_id }},
                groups: [window.AnalyticsSyncPanel.DEFAULT_PLATFORM_GROUPS[0]],
                urls: urls,
                reconnectUrl: @json(route('client-management.instagram.connect', $selectedClient)),
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

        @if ($tiktokCard['connected'])
            window.AnalyticsSyncPanel.createSyncController({
                clientId: clientId,
                platformId: {{ (int) $tiktokCard['integration']->platform_id }},
                groups: [window.AnalyticsSyncPanel.DEFAULT_PLATFORM_GROUPS[1]],
                urls: urls,
                reconnectUrl: @json(route('client-management.tiktok.connect', $selectedClient)),
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

@endif {{-- clientOptions->isNotEmpty() --}}
