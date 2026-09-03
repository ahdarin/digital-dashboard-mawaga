{{-- Filter periode/klien/role - flatpickr bulan (konsisten pola halaman lain) + auto-submit tiap ada perubahan, tanpa tombol Terapkan --}}
<form method="GET" class="card p-4 mb-5 flex flex-wrap items-end gap-3">
    <input type="hidden" name="tab" value="ringkasan">
    <div>
        <label for="period_start_ringkasan" class="block text-[10px] font-medium text-[var(--text-muted)] uppercase mb-1">Periode</label>
        <div class="relative">
            <span class="material-symbols-outlined absolute left-3 top-1/2 -translate-y-1/2 text-[var(--text-muted)] text-[17px] pointer-events-none">calendar_month</span>
            <input type="text" id="period_start_ringkasan" name="period_start" value="{{ $periodStart->format('Y-m') }}"
                data-flatpickr="month-combined" data-autosubmit="true" autocomplete="off" placeholder="Pilih bulan" readonly
                class="bg-[var(--surface-card)] border border-[var(--border)] rounded-lg pl-9 pr-3 py-2 text-sm focus:outline-none focus:border-[#044b46]/40 w-[160px]">
        </div>
    </div>
    <div>
        <label for="client_id_ringkasan" class="block text-[10px] font-medium text-[var(--text-muted)] uppercase mb-1">Klien</label>
        <select id="client_id_ringkasan" name="client_id" onchange="this.form.submit()" class="bg-[var(--surface-card)] border border-[var(--border)] rounded-lg px-3 py-2 text-sm focus:outline-none focus:border-[#044b46]/40">
            <option value="">Semua Klien</option>
            @foreach ($filterOptions['clients'] as $client)
                <option value="{{ $client->id }}" @selected(($filters['client_id'] ?? null) == $client->id)>{{ $client->name }}</option>
            @endforeach
        </select>
    </div>
    <div>
        <label for="role_id_ringkasan" class="block text-[10px] font-medium text-[var(--text-muted)] uppercase mb-1">Role</label>
        <select id="role_id_ringkasan" name="role_id" onchange="this.form.submit()" class="bg-[var(--surface-card)] border border-[var(--border)] rounded-lg px-3 py-2 text-sm focus:outline-none focus:border-[#044b46]/40">
            <option value="">Semua Role</option>
            @foreach ($filterOptions['roles'] as $role)
                <option value="{{ $role->id }}" @selected(($filters['role_id'] ?? null) == $role->id)>{{ $role->name }}</option>
            @endforeach
        </select>
    </div>
</form>

@if ($isCalculating)
    <div class="card p-4 mb-5 flex items-center gap-3 bg-[var(--info-tint)]">
        <span class="material-symbols-outlined text-[var(--info-text)] text-[18px] animate-spin">progress_activity</span>
        <p class="text-xs text-[var(--info-text)]">Data KPI sedang diperbarui otomatis di latar belakang - halaman ini akan menampilkan angka terbaru begitu selesai (biasanya dalam beberapa menit).</p>
    </div>
@endif

@if (! $run)
    {{-- Belum PERNAH ada kalkulasi apa pun - TIDAK ADA instruksi command developer di sini. --}}
    <div class="card p-6 text-center">
        <span class="material-symbols-outlined text-[28px] text-[var(--text-muted)] mb-2">database</span>
        <p class="text-sm font-medium text-[var(--text-primary)]">Data KPI sedang disiapkan otomatis.</p>
        <p class="text-xs text-[var(--text-muted)] mt-1">Belum ada aktivitas yang cukup untuk dihitung, atau kalkulasi pertama sedang berjalan. Halaman ini akan terisi otomatis begitu selesai.</p>
    </div>
@else
    @if ($usingFallbackPeriod)
        <div class="card p-4 mb-5 flex items-center gap-3 bg-[var(--warning-tint)]">
            <span class="material-symbols-outlined text-[var(--warning-text)] text-[18px]">history</span>
            <p class="text-xs text-[var(--warning-text)]">
                Periode {{ $periodStart->translatedFormat('F Y') }} belum selesai dihitung - menampilkan data periode {{ $run->period_start->translatedFormat('F Y') }} sementara pembaruan berjalan.
            </p>
        </div>
    @endif

    {{-- Coverage/status banner --}}
    <div class="card p-4 mb-5 flex items-center gap-3 bg-[var(--info-tint)]">
        <span class="material-symbols-outlined text-[var(--info-text)] text-[18px]">info</span>
        <p class="text-xs text-[var(--info-text)]">
            Terakhir diperbarui {{ $run->finished_at?->translatedFormat('d M Y, H:i') }}.
            {{ $summary['rows_with_composite_score'] }} dari {{ $summary['total_rows'] }} baris (user &times; role) punya skor composite lengkap;
            sisanya berstatus Sementara/Data Belum Cukup (ditampilkan apa adanya, bukan 0).
        </p>
    </div>

    {{-- Kartu operasional --}}
    <div class="grid grid-cols-2 sm:grid-cols-3 gap-2.5 sm:gap-5 mb-5">
        <div class="card p-3 sm:p-6">
            <p class="text-[11px] sm:text-sm text-[var(--text-secondary)] mb-1 sm:mb-2">Pemenuhan Kuota</p>
            <p class="font-display text-lg sm:text-2xl font-semibold text-[var(--text-primary)]">
                {{ $summary['quota_fulfillment']['rate'] !== null ? $summary['quota_fulfillment']['rate'].'%' : 'Data belum cukup' }}
            </p>
            @if ($summary['quota_fulfillment']['rate'] !== null)
                <p class="text-[10px] text-[var(--text-muted)] mt-1">{{ $summary['quota_fulfillment']['released'] }} / {{ $summary['quota_fulfillment']['quota'] }} slot dirilis</p>
            @endif
        </div>
        <div class="card p-3 sm:p-6">
            <p class="text-[11px] sm:text-sm text-[var(--text-secondary)] mb-1 sm:mb-2">Handoff Tepat Waktu</p>
            <p class="font-display text-lg sm:text-2xl font-semibold text-[var(--text-primary)]">
                {{ $summary['handoff_on_time_rate'] !== null ? $summary['handoff_on_time_rate'].'%' : 'Data belum cukup' }}
            </p>
        </div>
        <div class="card p-3 sm:p-6">
            <p class="text-[11px] sm:text-sm text-[var(--text-secondary)] mb-1 sm:mb-2">Ketepatan Jadwal Publish</p>
            <p class="font-display text-lg sm:text-2xl font-semibold text-[var(--text-primary)]">
                {{ $summary['publication_adherence_rate'] !== null ? $summary['publication_adherence_rate'].'%' : 'Data belum cukup' }}
            </p>
        </div>
        <div class="card p-3 sm:p-6">
            <p class="text-[11px] sm:text-sm text-[var(--text-secondary)] mb-1 sm:mb-2">Ketimpangan Beban Kerja</p>
            <p class="font-display text-lg sm:text-2xl font-semibold text-[var(--text-primary)]">
                {{ $summary['workload']['ratio'] !== null ? $summary['workload']['ratio'].'x' : 'Data belum cukup' }}
            </p>
            <p class="text-[10px] text-[var(--text-muted)] mt-1">Rasio beban terberat vs median tim (1.0x = merata)</p>
        </div>
        <div class="card p-3 sm:p-6">
            <p class="text-[11px] sm:text-sm text-[var(--text-secondary)] mb-1 sm:mb-2">Tahap Tersendat</p>
            @if ($summary['bottleneck'])
                <p class="font-display text-base sm:text-lg font-semibold text-[var(--text-primary)]">{{ $summary['bottleneck']['stage'] }}</p>
                <p class="text-[10px] text-[var(--text-muted)] mt-1">Median {{ $summary['bottleneck']['median_hours'] }} jam</p>
            @else
                <p class="font-display text-lg sm:text-2xl font-semibold text-[var(--text-primary)]">Data belum cukup</p>
            @endif
        </div>
        <div class="card p-3 sm:p-6">
            <p class="text-[11px] sm:text-sm text-[var(--text-secondary)] mb-1 sm:mb-2">Konten Terhambat</p>
            <p class="font-display text-lg sm:text-2xl font-semibold {{ $summary['active_blockers'] > 0 ? 'text-[var(--danger-text)]' : 'text-[var(--text-primary)]' }}">{{ $summary['active_blockers'] }}</p>
            <p class="text-[10px] text-[var(--text-muted)] mt-1">Konten aktif yang sudah lewat tenggat</p>
        </div>
    </div>

    <div class="grid grid-cols-2 sm:grid-cols-4 gap-2.5 sm:gap-5 mb-5">
        <div class="card p-3 sm:p-6">
            <p class="text-[11px] sm:text-sm text-[var(--text-secondary)] mb-1 sm:mb-2">Sehat</p>
            <p class="font-display text-lg sm:text-2xl font-semibold text-[var(--success-text,theme(colors.green.600))]">{{ $summary['rows_sehat'] }}</p>
        </div>
        <div class="card p-3 sm:p-6">
            <p class="text-[11px] sm:text-sm text-[var(--text-secondary)] mb-1 sm:mb-2">Perlu Perhatian</p>
            <p class="font-display text-lg sm:text-2xl font-semibold text-[var(--warning-text)]">{{ $summary['rows_perlu_perhatian'] }}</p>
        </div>
        <div class="card p-3 sm:p-6">
            <p class="text-[11px] sm:text-sm text-[var(--text-secondary)] mb-1 sm:mb-2">Sementara</p>
            <p class="font-display text-lg sm:text-2xl font-semibold text-[var(--info-text)]">{{ $summary['rows_sementara'] }}</p>
        </div>
        <div class="card p-3 sm:p-6">
            <p class="text-[11px] sm:text-sm text-[var(--text-secondary)] mb-1 sm:mb-2">Data Belum Cukup</p>
            <p class="font-display text-lg sm:text-2xl font-semibold text-[var(--text-muted)]">{{ $summary['rows_data_belum_cukup'] }}</p>
        </div>
    </div>

    <p class="text-xs text-[var(--text-muted)] mb-6">
        Kartu status BUKAN leaderboard - ini distribusi status di seluruh baris (user &times; role) pada periode ini, bukan perbandingan antarindividu.
    </p>

    <div class="card p-6">
        <h2 class="text-sm font-semibold text-[var(--text-primary)] mb-1">Ketepatan Prediksi Risiko Tinggi</h2>
        <p class="text-xs text-[var(--text-muted)] mb-4">Dari seluruh konten yang diprediksi berisiko tinggi, berapa persen yang benar-benar terlambat. Ini KESEHATAN MODEL prediksi AI, bukan KPI karyawan.</p>

        @if ($riskAccuracy['total_evaluated'] === 0)
            <p class="text-sm text-[var(--text-muted)] py-2">Belum ada cukup data (butuh konten yang sudah upload dan pernah dapat skor risiko).</p>
        @else
            <div class="flex items-baseline gap-2 mb-4">
                @if ($riskAccuracy['high_risk_precision'] !== null)
                    <p class="font-display text-2xl font-semibold text-[var(--text-primary)]">{{ $riskAccuracy['high_risk_precision'] }}%</p>
                    <p class="text-xs text-[var(--text-secondary)]">dari konten yang diprediksi <strong>Risiko Tinggi</strong> benar-benar terlambat</p>
                @else
                    <p class="text-sm text-[var(--text-muted)]">Belum ada konten dengan prediksi Risiko Tinggi yang sudah selesai upload.</p>
                @endif
            </div>
        @endif
    </div>

    <p class="text-xs text-[var(--text-muted)] mt-4">
        Lihat rincian tiap anggota (Kualitas Proses, Hasil Konten, Performa Klien, Jumlah Data, Kelengkapan Data) di tab <strong>Anggota</strong>.
    </p>
@endif
