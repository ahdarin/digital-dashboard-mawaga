@extends('layouts.app')
@section('title', $client->brand_name)
@section('content')

<div class="p-4 sm:p-6 lg:p-8 max-w-[1300px] mx-auto">

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
                    {{ strtoupper(substr($client->brand_name, 0, 1)) }}
                @endif
            </div>
            <div>
                <div class="flex items-center gap-2.5">
                    <h1 class="font-display text-2xl font-semibold text-[var(--text-primary)]">{{ $client->brand_name }}</h1>
                    <span class="badge
                        {{ $client->status === 'active' ? 'badge-success' : '' }}
                        {{ $client->status === 'past_due' ? 'badge-danger' : '' }}
                        {{ $client->status === 'paused' ? 'badge-neutral' : '' }}">
                        {{ $client->status === 'past_due' ? 'Past Due' : ucfirst($client->status) }}
                    </span>
                </div>
                <p class="text-sm text-[var(--text-muted)] mt-0.5">{{ $client->name }} &middot; {{ $client->category->name ?? '-' }}</p>
            </div>
        </div>

        <a href="{{ route('client-management.edit', $client) }}"
           class="btn-primary shrink-0">
            <span class="material-symbols-outlined text-[17px]">edit</span> Edit Client
        </a>
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
                    <p class="text-sm text-[var(--text-secondary)] mb-2">Total Content Plan</p>
                    <p class="font-display text-2xl font-semibold text-[var(--text-primary)]">{{ $planCount }}</p>
                </div>
                <div class="card p-6">
                    <p class="text-sm text-[var(--text-secondary)] mb-2">Total Content Created</p>
                    <p class="font-display text-2xl font-semibold text-[var(--text-primary)]">{{ $contentCount }}</p>
                </div>
            </div>

            <div class="card p-6">
                <h2 class="font-display text-lg font-semibold text-[var(--text-primary)] mb-4">Recent Content</h2>

                @if ($recentContentItems->isEmpty())
                    <p class="text-sm text-[var(--text-muted)] py-6 text-center">Belum ada konten untuk client ini.</p>
                @else
                    <div class="overflow-x-auto">
                        <table class="w-full text-sm text-left">
                            <thead class="bg-[var(--surface-page)]">
                                <tr class="text-[var(--text-muted)] text-[11px] uppercase tracking-wide">
                                    <th class="px-6 py-3 font-medium whitespace-nowrap">Title</th>
                                    <th class="px-4 py-3 font-medium whitespace-nowrap">Type</th>
                                    <th class="px-4 py-3 font-medium whitespace-nowrap">Deadline</th>
                                    <th class="px-4 py-3 font-medium whitespace-nowrap">Status</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($recentContentItems as $item)
                                    <tr class="border-t border-[var(--surface-muted)]">
                                        <td class="px-6 py-3.5 font-medium text-[var(--text-primary)] whitespace-nowrap">{{ $item->title }}</td>
                                        <td class="px-4 py-3.5 text-[var(--text-secondary)] whitespace-nowrap">{{ $item->contentType->name ?? '-' }}</td>
                                        <td class="px-4 py-3.5 text-[var(--text-secondary)] whitespace-nowrap">{{ $item->deadline_at ? $item->deadline_at->translatedFormat('d M Y') : '-' }}</td>
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
                @endif
            </div>

        </div>

        <div class="w-full lg:w-[320px] shrink-0 space-y-5">

            <div class="card p-6">
                <h2 class="font-display text-base font-semibold text-[var(--text-primary)] mb-4">Owner Account</h2>

                @if ($client->owner)
                    <div class="flex items-center gap-3 mb-4">
                        <div class="w-9 h-9 rounded-full bg-[var(--brand-solid)] text-white flex items-center justify-center text-sm font-semibold shrink-0">
                            {{ strtoupper(substr($client->owner->name, 0, 1)) }}
                        </div>
                        <div>
                            <p class="text-sm font-medium text-[var(--text-primary)]">{{ $client->owner->name }}</p>
                            <p class="text-xs text-[var(--text-muted)]">{{ ucfirst($client->owner->status) }}</p>
                        </div>
                    </div>

                    <div class="space-y-2.5 text-sm">
                        <div class="flex items-center gap-2 text-[var(--text-secondary)]">
                            <span class="material-symbols-outlined text-[15px]">mail</span> {{ $client->owner->email }}
                        </div>
                        <div class="flex items-center gap-2 text-[var(--text-secondary)]">
                            <span class="material-symbols-outlined text-[15px]">call</span> {{ $client->owner->phone_number ?? '-' }}
                        </div>
                    </div>
                @else
                    <p class="text-sm text-[var(--text-muted)]">Belum ada akun owner terdaftar.</p>
                @endif
            </div>

            <div class="card p-6">
                <h2 class="font-display text-base font-semibold text-[var(--text-primary)] mb-4">Active Package</h2>

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
                    <h2 class="font-display text-base font-semibold text-[var(--text-primary)]">Integrasi Analytics</h2>
                    <a href="{{ route('settings', ['tab' => 'integrasi']) }}" class="text-xs text-[var(--brand)] hover:underline">Riwayat sync</a>
                </div>

                @php $igConnected = $instagramIntegration && $instagramIntegration->status === 'active'; @endphp

                <div class="flex items-center justify-between mb-2">
                    <p class="text-sm font-medium text-[var(--text-primary)]">Instagram</p>
                    <span class="badge {{ $instagramSyncing ? 'badge-warning' : ($igConnected ? 'badge-success' : 'badge-danger') }}">
                        {{ $instagramSyncing ? 'Syncing' : ($igConnected ? 'Active' : 'Disconnected') }}
                    </span>
                </div>

                @if ($igConnected)
                    <p class="text-xs text-[var(--text-muted)] mb-3">&commat;{{ $instagramIntegration->external_username }}</p>

                    {{-- Content Analytics --}}
                    <div class="border-t border-[var(--surface-muted)] pt-3">
                        <div class="flex items-center justify-between mb-1">
                            <p class="text-xs font-semibold text-[var(--text-primary)]">Content Analytics</p>
                            <span class="badge {{ $instagramSyncing ? 'badge-warning' : ($instagramLastSyncLog?->status === 'failed' ? 'badge-danger' : 'badge-success') }}">
                                {{ $instagramSyncing ? 'Syncing' : ($instagramLastSyncLog?->status === 'failed' ? 'Failed' : 'Synced') }}
                            </span>
                        </div>
                        <p class="text-[11px] text-[var(--text-muted)] mb-2">
                            Last Sync: {{ $instagramIntegration->last_synced_at ? $instagramIntegration->last_synced_at->format('d M Y, H:i') : 'Belum pernah sync' }}
                        </p>
                        @if (! $instagramSyncing && $instagramLastSyncLog?->status === 'failed')
                            <p class="text-[11px] text-[var(--danger-text)] mb-2">Failed - {{ $instagramLastSyncLog->error_message }}</p>
                        @endif

                        <div class="flex items-center gap-2 flex-wrap mb-2">
                            <form action="{{ route('settings.sync-instagram') }}" method="POST">
                                @csrf
                                <input type="hidden" name="client_id" value="{{ $client->id }}">
                                <button type="submit" {{ $instagramSyncing ? 'disabled' : '' }}
                                        class="text-xs font-medium text-white bg-[var(--brand)] px-3 py-1.5 rounded-lg hover:opacity-90 transition-opacity flex items-center gap-1 disabled:opacity-50 disabled:cursor-not-allowed">
                                    <span class="material-symbols-outlined text-[14px]">sync</span>
                                    {{ $instagramSyncing ? 'Syncing...' : 'Sync Content Analytics' }}
                                </button>
                            </form>
                            <a href="{{ route('publishing-tracker.instagram.unmatched', $instagramIntegration) }}"
                               class="text-xs font-medium text-[var(--text-muted)] hover:text-[var(--text-secondary)]">Media belum ter-link</a>
                        </div>
                        @if ($instagramSyncing)
                            <p class="text-[11px] text-[var(--brand)] mb-2">Sedang menyinkronkan data Content Analytics Instagram.</p>
                        @endif
                        <p class="text-[11px] text-[var(--text-muted)] mb-2">Sync rutin hanya mengambil 2 bulan terakhir agar proses lebih cepat.</p>

                        <details class="text-xs">
                            <summary class="cursor-pointer text-[var(--brand)] font-medium select-none">Historical Sync (bulan lama)</summary>
                            <div class="mt-2 space-y-1.5">
                                <p class="text-[11px] text-[var(--text-muted)]">Gunakan ini untuk mengambil data bulan lama yang belum tersync.</p>
                                <form action="{{ route('settings.sync-instagram') }}" method="POST" class="flex items-center gap-2">
                                    @csrf
                                    <input type="hidden" name="client_id" value="{{ $client->id }}">
                                    <input type="month" name="month" required max="{{ now()->format('Y-m') }}" {{ $instagramSyncing ? 'disabled' : '' }}
                                           class="text-xs border border-[var(--border)] rounded-lg px-2 py-1.5 bg-[var(--surface-card)] focus:outline-none focus:border-[#044b46]/40">
                                    <button type="submit" {{ $instagramSyncing ? 'disabled' : '' }}
                                            class="text-xs font-medium text-white bg-[var(--brand)] px-3 py-1.5 rounded-lg hover:opacity-90 transition-opacity disabled:opacity-50 disabled:cursor-not-allowed">
                                        Sync Selected Month
                                    </button>
                                </form>
                            </div>
                        </details>
                    </div>

                    {{-- Audience Insights - card & lock TERPISAH dari Content
                         Analytics di atas (job beda: SyncInstagramAudienceJob). --}}
                    <div class="border-t border-[var(--surface-muted)] pt-3 mt-3">
                        <div class="flex items-center justify-between mb-1">
                            <p class="text-xs font-semibold text-[var(--text-primary)]">Audience Insights</p>
                            <span class="badge {{ $instagramAudienceSyncing ? 'badge-warning' : ($instagramAudienceLastSyncLog?->status === 'failed' ? 'badge-danger' : ($instagramAudienceLastSuccessAt ? 'badge-success' : 'badge-neutral')) }}">
                                {{ $instagramAudienceSyncing ? 'Syncing' : ($instagramAudienceLastSyncLog?->status === 'failed' ? 'Failed' : ($instagramAudienceLastSuccessAt ? 'Synced' : '-')) }}
                            </span>
                        </div>
                        <p class="text-[11px] text-[var(--text-muted)] mb-2">
                            Last Audience Sync: {{ $instagramAudienceLastSuccessAt ? \Illuminate\Support\Carbon::parse($instagramAudienceLastSuccessAt)->format('d M Y, H:i') : 'Belum pernah disinkronkan' }}
                        </p>
                        {{-- Raw exception Meta TIDAK PERNAH ditampilkan - cuma
                             pesan aman generik (beda dari Content Analytics di
                             atas yang sudah lama nampilin error_message apa
                             adanya - itu behavior existing, sengaja nggak diubah). --}}
                        @if (! $instagramAudienceSyncing && $instagramAudienceLastSyncLog?->status === 'failed')
                            <p class="text-[11px] text-[var(--danger-text)] mb-2">Sinkronisasi Audience terakhir gagal.</p>
                        @endif

                        <form action="{{ route('client-management.instagram.sync-audience', $client) }}" method="POST" class="mb-1">
                            @csrf
                            <button type="submit" {{ $instagramAudienceSyncing ? 'disabled' : '' }}
                                    class="text-xs font-medium text-white bg-[var(--brand)] px-3 py-1.5 rounded-lg hover:opacity-90 transition-opacity flex items-center gap-1 disabled:opacity-50 disabled:cursor-not-allowed">
                                <span class="material-symbols-outlined text-[14px]">groups</span>
                                {{ $instagramAudienceSyncing ? 'Syncing...' : 'Sync Audience Insights' }}
                            </button>
                        </form>
                        @if ($instagramAudienceSyncing)
                            <p class="text-[11px] text-[var(--brand)]">Sedang menyinkronkan data audience Instagram.</p>
                        @endif
                    </div>
                @elseif ($instagramOauthConfigured)
                    @if ($instagramIntegration && $instagramIntegration->last_error)
                        <p class="text-xs font-medium text-[var(--danger-text)] mb-1">Instagram connection needs attention</p>
                        <p class="text-xs text-[var(--danger-text)] mb-3">{{ $instagramIntegration->last_error }}</p>
                    @else
                        <p class="text-xs text-[var(--text-muted)] mb-3">Belum terhubung.</p>
                    @endif
                    <a href="{{ route('client-management.instagram.connect', $client) }}"
                       class="inline-flex items-center gap-1.5 text-xs font-medium text-white bg-[var(--brand)] px-3 py-1.5 rounded-lg hover:opacity-90 transition-opacity">
                        <span class="material-symbols-outlined text-[14px]">link</span>
                        {{ $instagramIntegration && $instagramIntegration->last_error ? 'Reconnect Instagram' : 'Connect Instagram' }}
                    </a>
                @else
                    <p class="text-xs text-[var(--text-muted)]">
                        OAuth belum dikonfigurasi. Isi <code>INSTAGRAM_CLIENT_ID</code>/<code>INSTAGRAM_CLIENT_SECRET</code> di <code>.env</code>
                        dan daftarkan redirect URI di Meta App Dashboard dulu.
                    </p>
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
                @else
                    <p class="text-sm text-[var(--text-muted)]">Belum ada link aset. <a href="{{ route('client-management.edit', $client) }}" class="text-[var(--brand)] hover:underline">Tambahkan di Edit Client</a>.</p>
                @endif
            </div>

            <div class="card p-6">
                <h2 class="text-sm font-semibold text-[var(--danger-text)] mb-3">Danger Zone</h2>
                <form action="{{ route('client-management.destroy', $client) }}" method="POST"
                      onsubmit="return appConfirm(this, 'Yakin hapus {{ addslashes($client->brand_name) }}? Kalau sudah punya riwayat konten, client hanya akan dinonaktifkan, bukan dihapus permanen.', { danger: true })">
                    @csrf
                    @method('DELETE')
                    <button type="submit" class="btn-danger w-full">
                        Hapus Client
                    </button>
                </form>
            </div>

        </div>

    </div>
</div>
@endsection