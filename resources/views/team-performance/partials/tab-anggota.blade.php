{{-- Filter - flatpickr bulan (konsisten pola halaman lain) + auto-submit tiap ada perubahan, tanpa tombol Terapkan --}}
<form method="GET" class="card p-4 mb-5 flex flex-wrap items-end gap-3">
    <input type="hidden" name="tab" value="anggota">
    <div>
        <label for="period_start_anggota" class="block text-[10px] font-medium text-[var(--text-muted)] uppercase mb-1">Periode</label>
        <div class="relative">
            <span class="material-symbols-outlined absolute left-3 top-1/2 -translate-y-1/2 text-[var(--text-muted)] text-[17px] pointer-events-none">calendar_month</span>
            <input type="text" id="period_start_anggota" name="period_start" value="{{ $periodStart->format('Y-m') }}"
                data-flatpickr="month-combined" data-autosubmit="true" autocomplete="off" placeholder="Pilih bulan" readonly
                class="bg-[var(--surface-card)] border border-[var(--border)] rounded-lg pl-9 pr-3 py-2 text-sm focus:outline-none focus:border-[#044b46]/40 w-[160px]">
        </div>
    </div>
    <div>
        <label for="client_id_anggota" class="block text-[10px] font-medium text-[var(--text-muted)] uppercase mb-1">Klien</label>
        <select id="client_id_anggota" name="client_id" onchange="this.form.submit()" class="bg-[var(--surface-card)] border border-[var(--border)] rounded-lg px-3 py-2 text-sm focus:outline-none focus:border-[#044b46]/40">
            <option value="">Semua Klien</option>
            @foreach ($filterOptions['clients'] as $client)
                <option value="{{ $client->id }}" @selected(($filters['client_id'] ?? null) == $client->id)>{{ $client->name }}</option>
            @endforeach
        </select>
    </div>
    <div>
        <label for="role_id_anggota" class="block text-[10px] font-medium text-[var(--text-muted)] uppercase mb-1">Role</label>
        <select id="role_id_anggota" name="role_id" onchange="this.form.submit()" class="bg-[var(--surface-card)] border border-[var(--border)] rounded-lg px-3 py-2 text-sm focus:outline-none focus:border-[#044b46]/40">
            <option value="">Semua Role</option>
            @foreach ($filterOptions['roles'] as $role)
                <option value="{{ $role->id }}" @selected(($filters['role_id'] ?? null) == $role->id)>{{ $role->name }}</option>
            @endforeach
        </select>
    </div>
    <div>
        <label for="coverage_status_anggota" class="block text-[10px] font-medium text-[var(--text-muted)] uppercase mb-1">Kelengkapan Data</label>
        <select id="coverage_status_anggota" name="coverage_status" onchange="this.form.submit()" class="bg-[var(--surface-card)] border border-[var(--border)] rounded-lg px-3 py-2 text-sm focus:outline-none focus:border-[#044b46]/40">
            <option value="">Semua</option>
            @foreach (['full' => 'Lengkap', 'partial' => 'Sebagian', 'provisional' => 'Sementara', 'unavailable' => 'Belum Tersedia'] as $value => $label)
                <option value="{{ $value }}" @selected(($filters['coverage_status'] ?? null) === $value)>{{ $label }}</option>
            @endforeach
        </select>
    </div>
</form>

@if ($isCalculating)
    <div class="card p-3 mb-5 flex items-center gap-2 bg-[var(--info-tint)]">
        <span class="material-symbols-outlined text-[var(--info-text)] text-[16px] animate-spin">progress_activity</span>
        <p class="text-xs text-[var(--info-text)]">Data sedang diperbarui otomatis di latar belakang.</p>
    </div>
@endif

@if (! $run)
    <div class="card p-6 text-center">
        <p class="text-sm font-medium text-[var(--text-primary)]">Data KPI sedang disiapkan otomatis.</p>
    </div>
@else
    @php
        $statusBadge = fn (string $label) => match ($label) {
            'sehat' => 'badge-success',
            'perlu_perhatian' => 'badge-warning',
            'sementara' => 'badge-neutral',
            default => 'badge-neutral',
        };
        $statusText = fn (string $label) => match ($label) {
            'sehat' => 'Sehat',
            'perlu_perhatian' => 'Perlu Perhatian',
            'sementara' => 'Sementara',
            default => 'Data Belum Cukup',
        };
        $coverageText = fn (string $value) => match ($value) {
            'full' => 'Lengkap', 'partial' => 'Sebagian', 'provisional' => 'Sementara', default => 'Belum Tersedia',
        };
    @endphp

    {{-- BUKAN leaderboard - urutan alfabetis nama, TIDAK diurutkan berdasarkan skor.
         Setiap baris = satu (user, role[, client]) - tidak pernah satu
         overall score lintas role. --}}
    <div class="space-y-3">
        @forelse ($memberRows->sortBy(fn ($r) => $r->user->name) as $row)
            <div class="card p-4 sm:p-5">
                <div class="flex flex-wrap items-center justify-between gap-3 mb-3">
                    <div class="flex items-center gap-3 min-w-0">
                        @if ($row->user->avatar_url)
                            <img src="{{ $row->user->avatar_url }}" alt="" referrerpolicy="no-referrer" class="w-9 h-9 rounded-full object-cover shrink-0">
                        @else
                            <div class="w-9 h-9 rounded-full bg-[var(--brand-solid)] text-white text-sm font-semibold flex items-center justify-center shrink-0">
                                {{ strtoupper(substr($row->user->name, 0, 1)) }}
                            </div>
                        @endif
                        <div class="min-w-0">
                            <a href="{{ route('team-performance.show', ['user' => $row->user, 'role_id' => $row->role_id, 'client_id' => $row->client_id, 'period_start' => $periodStart->format('Y-m')]) }}"
                                class="font-medium text-[var(--text-primary)] hover:text-[var(--brand)] truncate">{{ $row->user->name }}</a>
                            <p class="text-xs text-[var(--text-muted)] truncate">
                                {{ $row->role->name ?? '-' }}
                                @if ($row->client) &middot; {{ $row->client->name }} @endif
                            </p>
                        </div>
                    </div>
                    <span class="badge {{ $statusBadge($row->status_label->value) }}">{{ $statusText($row->status_label->value) }}</span>
                </div>

                <div class="grid grid-cols-3 gap-3 text-center mb-3">
                    <div title="Ketepatan dan kualitas alur kerja sesuai role.">
                        <p class="text-[10px] uppercase text-[var(--text-muted)]">Kualitas Proses</p>
                        <p class="text-sm font-semibold text-[var(--text-primary)]">{{ $row->process_score !== null ? round($row->process_score) : '-' }}</p>
                    </div>
                    <div title="Performa analytics konten yang ditangani.">
                        <p class="text-[10px] uppercase text-[var(--text-muted)]">Hasil Konten</p>
                        <p class="text-sm font-semibold text-[var(--text-primary)]">{{ $row->direct_outcome_score !== null ? round($row->direct_outcome_score) : '-' }}</p>
                    </div>
                    <div title="Perkembangan akun klien yang dibagikan ke seluruh PIC yang terlibat.">
                        <p class="text-[10px] uppercase text-[var(--text-muted)]">Performa Klien</p>
                        <p class="text-sm font-semibold text-[var(--text-primary)]">{{ $row->portfolio_outcome_score !== null ? round($row->portfolio_outcome_score) : '-' }}</p>
                    </div>
                </div>

                <div class="flex items-center justify-between text-xs text-[var(--text-muted)] pt-3 border-t border-[var(--surface-muted)]">
                    <span title="Apakah jumlah dan kualitas data cukup untuk menyimpulkan KPI.">Jumlah Data: {{ $row->sample_size }} &middot; Kelengkapan Data: {{ $coverageText($row->coverage_status->value) }}</span>
                    <span>
                        {{-- #13: nilai KPI TIDAK PERNAH ditampilkan kalau status Data Belum Cukup,
                             walau angkanya tersimpan (untuk audit) di database. --}}
                        @if ($row->status_label->value === 'data_belum_cukup')
                            Nilai KPI: Data belum cukup
                        @else
                            Nilai KPI: {{ $row->composite_score !== null ? round($row->composite_score) : 'Data belum cukup' }}
                        @endif
                    </span>
                </div>
            </div>
        @empty
            <div class="card p-6 text-center text-sm text-[var(--text-muted)]">Belum ada baris KPI yang cocok dengan filter ini.</div>
        @endforelse
    </div>

    <p class="text-xs text-[var(--text-muted)] mt-4">
        Baris dengan kelengkapan data berbeda tidak dibandingkan langsung satu sama lain.
    </p>
@endif
