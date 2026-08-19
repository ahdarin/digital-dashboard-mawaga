<div class="flex flex-col sm:flex-row sm:items-center justify-between gap-2 mb-2">
    <p class="text-[var(--text-secondary)] text-sm">Kelola koneksi API per platform dan pantau riwayat sinkronisasi/import data.</p>
    <a href="{{ route('settings.import') }}" class="text-sm font-medium text-[var(--brand)] hover:underline flex items-center gap-1 shrink-0">
        <span class="material-symbols-outlined text-[15px]">upload_file</span> Import Performance Data
    </a>
</div>

{{-- Data Sources --}}
<div class="card p-6 mb-6 mt-4">
    <h2 class="font-display text-lg font-semibold text-[var(--text-primary)] mb-5">Data Sources</h2>

    <div class="grid grid-cols-2 sm:grid-cols-4 gap-4">
        @foreach ($integrations as $row)
            <div class="border border-[var(--border)] rounded-xl p-4">
                <div class="flex items-center justify-between mb-3">
                    <div class="w-9 h-9 rounded-lg bg-[var(--brand-tint)] flex items-center justify-center">
                        <span class="material-symbols-outlined text-[var(--brand)] text-[18px]">hub</span>
                    </div>
                    <span class="badge {{ $row['connected'] ? 'badge-success' : 'badge-danger' }}">
                        {{ $row['connected'] ? 'Active' : 'Disconnected' }}
                    </span>
                </div>
                <p class="text-sm font-medium text-[var(--text-primary)]">{{ $row['platform'] }}</p>
                <p class="text-xs text-[var(--text-muted)] mt-0.5 mb-3">
                    {{ $row['connected'] ? 'Last sync: '.$row['updated_at']?->diffForHumans() : 'Belum terhubung' }}
                </p>
                <button type="button" disabled title="Belum tersedia - nunggu App Review Meta/TikTok kelar dulu"
                        class="text-xs font-medium text-[var(--text-muted)] cursor-not-allowed">
                    {{ $row['connected'] ? 'Manage' : 'Reconnect' }} <span class="text-[10px]">(segera)</span>
                </button>
            </div>
        @endforeach
    </div>
</div>

{{-- Sync Log --}}
<div class="card overflow-hidden">
    <div class="p-6 pb-4 flex flex-col sm:flex-row sm:items-center justify-between gap-3">
        <h2 class="font-display text-lg font-semibold text-[var(--text-primary)]">Log Sinkronisasi</h2>

        <form method="GET" class="flex items-center gap-2.5 flex-wrap">
            <input type="hidden" name="tab" value="integrasi">
            <select name="status" onchange="this.form.submit()"
                    class="text-sm border border-[var(--border)] rounded-lg px-3 py-2 bg-[var(--surface-card)] focus:outline-none focus:border-[#044b46]/40">
                <option value="">All Statuses</option>
                <option value="success" {{ request('status') === 'success' ? 'selected' : '' }}>Success</option>
                <option value="failed" {{ request('status') === 'failed' ? 'selected' : '' }}>Failed</option>
                <option value="pending" {{ request('status') === 'pending' ? 'selected' : '' }}>Pending</option>
            </select>
            <input type="text" name="date" value="{{ request('date') }}" data-flatpickr="date" data-autosubmit="true" autocomplete="off"
                   class="text-sm border border-[var(--border)] rounded-lg px-3 py-2 focus:outline-none focus:border-[#044b46]/40">
            @if (request('status') || request('date'))
                <a href="{{ route('settings', ['tab' => 'integrasi']) }}" class="text-xs text-[var(--text-muted)] hover:text-[var(--text-secondary)]">Reset</a>
            @endif
        </form>
    </div>

    @php
        $sourceTypeLabels = [
            'performance_csv_import' => 'Performance CSV Import',
            'audience_csv_import' => 'Audience CSV Import',
            'api_sync' => 'API Sync',
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
