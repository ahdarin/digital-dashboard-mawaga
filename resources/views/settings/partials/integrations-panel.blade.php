{{-- Integrasi - CLIENT-CENTRIC (dulu: 1 kartu Instagram global + dropdown
     client di dalam form Sync Now, membingungkan karena koneksi Instagram
     sebenarnya per-client lewat api_integrations - lihat audit "Settings
     Integrasi client-centric"). User pilih client dulu SEBAGAI FILTER
     UTAMA halaman, baru integration MILIK client itu yang ditampilkan. --}}

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

    {{-- Automatic Integrations --}}
    <div class="card p-6 mb-6">
        <h2 class="font-display text-lg font-semibold text-[var(--text-primary)] mb-1">Automatic Integrations</h2>
        <p class="text-xs text-[var(--text-muted)] mb-5">Koneksi API real-time untuk {{ $selectedClient->name }}.</p>

        {{-- Instagram --}}
        <div class="border border-[var(--border)] rounded-xl p-5 mb-4">
            <div class="flex items-center justify-between mb-1">
                <p class="text-sm font-semibold text-[var(--text-primary)]">Instagram</p>
                @if ($instagramCard['connected'])
                    <span class="badge badge-success inline-flex items-center gap-1">
                        <span class="w-1.5 h-1.5 rounded-full bg-current"></span> Connected
                    </span>
                @else
                    <span class="badge badge-neutral">Not Connected</span>
                @endif
            </div>

            @if ($instagramCard['connected'])
                @php $integration = $instagramCard['integration']; @endphp
                <p class="text-xs text-[var(--text-muted)] mb-4">&commat;{{ $integration->external_username }}</p>

                {{-- Content Analytics --}}
                <div class="border-t border-[var(--surface-muted)] pt-3">
                    <div class="flex items-center justify-between mb-1">
                        <p class="text-xs font-semibold text-[var(--text-primary)]">Content Analytics</p>
                        <span class="badge {{ $instagramCard['content_syncing'] ? 'badge-warning' : ($instagramCard['content_last_sync_log']?->status === 'failed' ? 'badge-danger' : 'badge-success') }}">
                            {{ $instagramCard['content_syncing'] ? 'Syncing' : ($instagramCard['content_last_sync_log']?->status === 'failed' ? 'Failed' : 'Synced') }}
                        </span>
                    </div>
                    <p class="text-[11px] text-[var(--text-muted)] mb-2">
                        Last Sync: {{ $integration->last_synced_at ? $integration->last_synced_at->format('d M Y, H:i') : 'Belum pernah sync' }}
                    </p>
                    @if (! $instagramCard['content_syncing'] && $instagramCard['content_last_sync_log']?->status === 'failed')
                        <p class="text-[11px] text-[var(--danger-text)] mb-2">Failed - {{ $instagramCard['content_last_sync_log']->error_message }}</p>
                    @endif

                    <form action="{{ route('settings.sync-instagram') }}" method="POST" class="inline-block mb-2">
                        @csrf
                        <input type="hidden" name="client_id" value="{{ $selectedClient->id }}">
                        <button type="submit" {{ $instagramCard['content_syncing'] ? 'disabled' : '' }}
                                class="text-xs font-medium text-white bg-[var(--brand)] px-3 py-1.5 rounded-lg hover:opacity-90 transition-opacity flex items-center gap-1 disabled:opacity-50 disabled:cursor-not-allowed">
                            <span class="material-symbols-outlined text-[14px]">sync</span>
                            {{ $instagramCard['content_syncing'] ? 'Syncing...' : 'Sync Content' }}
                        </button>
                    </form>

                    <details class="text-xs mt-1">
                        <summary class="cursor-pointer text-[var(--brand)] font-medium select-none">Historical Content Sync</summary>
                        <div class="mt-2 flex items-center gap-2">
                            <form action="{{ route('settings.sync-instagram') }}" method="POST" class="flex items-center gap-2">
                                @csrf
                                <input type="hidden" name="client_id" value="{{ $selectedClient->id }}">
                                <input type="month" name="month" required max="{{ now()->format('Y-m') }}" {{ $instagramCard['content_syncing'] ? 'disabled' : '' }}
                                       class="text-xs border border-[var(--border)] rounded-lg px-2 py-1.5 bg-[var(--surface-card)] focus:outline-none focus:border-[#044b46]/40">
                                <button type="submit" {{ $instagramCard['content_syncing'] ? 'disabled' : '' }}
                                        class="text-xs font-medium text-white bg-[var(--brand)] px-3 py-1.5 rounded-lg hover:opacity-90 transition-opacity disabled:opacity-50 disabled:cursor-not-allowed">
                                    Sync Selected Month
                                </button>
                            </form>
                        </div>
                    </details>
                </div>

                {{-- Audience Insights --}}
                <div class="border-t border-[var(--surface-muted)] pt-3 mt-3">
                    <div class="flex items-center justify-between mb-1">
                        <p class="text-xs font-semibold text-[var(--text-primary)]">Audience Insights</p>
                        <span class="badge {{ $instagramCard['audience_syncing'] ? 'badge-warning' : ($instagramCard['audience_last_sync_log']?->status === 'failed' ? 'badge-danger' : ($instagramCard['audience_last_success_at'] ? 'badge-success' : 'badge-neutral')) }}">
                            {{ $instagramCard['audience_syncing'] ? 'Syncing' : ($instagramCard['audience_last_sync_log']?->status === 'failed' ? 'Failed' : ($instagramCard['audience_last_success_at'] ? 'Synced' : '-')) }}
                        </span>
                    </div>
                    <p class="text-[11px] text-[var(--text-muted)] mb-2">
                        Last Audience Sync: {{ $instagramCard['audience_last_success_at'] ? \Illuminate\Support\Carbon::parse($instagramCard['audience_last_success_at'])->format('d M Y, H:i') : 'Belum pernah disinkronkan' }}
                    </p>
                    @if (! $instagramCard['audience_syncing'] && $instagramCard['audience_last_sync_log']?->status === 'failed')
                        <p class="text-[11px] text-[var(--danger-text)] mb-2">Sinkronisasi Audience terakhir gagal.</p>
                    @endif

                    <form action="{{ route('client-management.instagram.sync-audience', $selectedClient) }}" method="POST">
                        @csrf
                        <button type="submit" {{ $instagramCard['audience_syncing'] ? 'disabled' : '' }}
                                class="text-xs font-medium text-white bg-[var(--brand)] px-3 py-1.5 rounded-lg hover:opacity-90 transition-opacity flex items-center gap-1 disabled:opacity-50 disabled:cursor-not-allowed">
                            <span class="material-symbols-outlined text-[14px]">groups</span>
                            {{ $instagramCard['audience_syncing'] ? 'Syncing...' : 'Sync Audience' }}
                        </button>
                    </form>
                </div>

                <div class="border-t border-[var(--surface-muted)] pt-3 mt-3 flex items-center gap-3 flex-wrap">
                    <a href="{{ route('publishing-tracker.instagram.unmatched', $integration) }}"
                       class="text-xs font-medium text-[var(--text-muted)] hover:text-[var(--text-secondary)]">Media belum ter-link</a>
                    @if ($instagramOauthConfigured)
                        <a href="{{ route('client-management.instagram.connect', $selectedClient) }}"
                           class="text-xs font-medium text-[var(--text-muted)] hover:text-[var(--text-secondary)]">Reconnect Instagram</a>
                    @endif
                </div>
            @elseif ($instagramOauthConfigured)
                <p class="text-sm text-[var(--text-secondary)] mb-4 max-w-md">Hubungkan akun Instagram Professional client untuk mengambil data performa dan audience secara otomatis.</p>
                <a href="{{ route('client-management.instagram.connect', $selectedClient) }}"
                   class="inline-flex items-center gap-1.5 text-xs font-medium text-white bg-[var(--brand)] px-3 py-1.5 rounded-lg hover:opacity-90 transition-opacity">
                    <span class="material-symbols-outlined text-[14px]">link</span> Connect Instagram
                </a>
            @else
                <p class="text-xs text-[var(--text-muted)]">
                    OAuth belum dikonfigurasi. Isi <code>INSTAGRAM_CLIENT_ID</code>/<code>INSTAGRAM_CLIENT_SECRET</code> di <code>.env</code>
                    dan daftarkan redirect URI di Meta App Dashboard dulu.
                </p>
            @endif
        </div>

        {{-- TikTok - BELUM diimplementasikan sama sekali (API belum
             dibangun). SENGAJA statis, tidak query ApiIntegration apapun -
             sebelumnya row placeholder DemoSeeder (status=active acak,
             tanpa token) bikin badge "Active" palsu muncul. --}}
        <div class="border border-[var(--border)] rounded-xl p-5 opacity-75">
            <div class="flex items-center justify-between mb-1">
                <p class="text-sm font-semibold text-[var(--text-primary)]">TikTok</p>
                <span class="badge badge-neutral">Coming Soon</span>
            </div>
            <p class="text-xs text-[var(--text-muted)]">TikTok Analytics integration belum tersedia.</p>
        </div>
    </div>

    {{-- Manual Data Import - dipisah jelas dari Automatic Integrations di
         atas, biar nggak kecampur seolah CSV = API (Langkah 12). --}}
    <div class="card p-6 mb-6">
        <h2 class="font-display text-lg font-semibold text-[var(--text-primary)] mb-1">Manual Data Import</h2>
        <p class="text-xs text-[var(--text-muted)] mb-4">Fallback manual - dipakai kalau API belum tersedia atau perlu isi data historis di luar jangkauan API.</p>
        <div class="flex items-center gap-3 flex-wrap">
            <a href="{{ route('settings.import') }}" class="text-sm font-medium text-[var(--brand)] hover:underline flex items-center gap-1.5">
                <span class="material-symbols-outlined text-[16px]">upload_file</span> Import Performance CSV
            </a>
            <a href="{{ route('analytics', ['tab' => 'audience', 'client_id' => $selectedClient->id]) }}" class="text-sm font-medium text-[var(--brand)] hover:underline flex items-center gap-1.5">
                <span class="material-symbols-outlined text-[16px]">upload_file</span> Import Audience CSV
            </a>
        </div>
    </div>

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
                <option value="">All Statuses</option>
                <option value="success" {{ request('status') === 'success' ? 'selected' : '' }}>Success</option>
                <option value="failed" {{ request('status') === 'failed' ? 'selected' : '' }}>Failed</option>
                <option value="pending" {{ request('status') === 'pending' ? 'selected' : '' }}>Pending</option>
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
                   class="text-xs text-[var(--text-muted)] hover:text-[var(--text-secondary)]">All Clients</a>
            @endif
            @if (request('status') || request('date'))
                <a href="{{ route('settings', ['tab' => 'integrasi', 'client_id' => $selectedClientId, 'all_clients' => $logsAllClients ? 1 : null]) }}" class="text-xs text-[var(--text-muted)] hover:text-[var(--text-secondary)]">Reset</a>
            @endif
        </form>
    </div>

    @php
        $sourceTypeLabels = [
            'performance_csv_import' => 'Performance CSV Import',
            'audience_csv_import' => 'Audience CSV Import',
            'api_sync' => 'Content API Sync',
            'audience_api_sync' => 'Audience API Sync',
        ];
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
                        <th class="px-6 py-3 font-medium whitespace-nowrap">Date</th>
                        <th class="px-4 py-3 font-medium whitespace-nowrap">Klien</th>
                        <th class="px-4 py-3 font-medium whitespace-nowrap">Platform</th>
                        <th class="px-4 py-3 font-medium whitespace-nowrap">Source Type</th>
                        <th class="px-4 py-3 font-medium whitespace-nowrap">Imported By</th>
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
                                    {{ ucfirst($log->status) }}
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
                                {{ ucfirst($log->status) }}
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
                            <span class="text-[var(--text-muted)]">Source Type</span>
                            <span class="text-[var(--text-primary)] text-right">{{ $sourceTypeLabels[$log->source_type] ?? \Illuminate\Support\Str::headline($log->source_type) }}</span>
                        </div>
                        <div class="flex justify-between gap-3">
                            <span class="text-[var(--text-muted)]">Imported By</span>
                            <span class="text-[var(--text-primary)] text-right">{{ $log->importedBy->name ?? '-' }}</span>
                        </div>
                    </div>
                </div>
            @endforeach
        </div>

        <div class="px-6 py-4 border-t border-[var(--surface-muted)]">{{ $syncLogs->links() }}</div>
    @endif
</div>

@endif {{-- clientOptions->isNotEmpty() --}}
