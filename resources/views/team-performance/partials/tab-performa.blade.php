{{-- Filter --}}
<form method="GET" class="flex flex-wrap items-center gap-2.5 mb-5">
    <input type="hidden" name="tab" value="performa">
    <div class="relative">
        <span class="material-symbols-outlined absolute left-3 top-1/2 -translate-y-1/2 text-[var(--text-muted)] text-[17px] pointer-events-none">calendar_month</span>
        <input type="text" name="month" value="{{ $periodStart->format('Y-m') }}" data-flatpickr="month-combined" data-autosubmit="true" readonly
               class="text-sm border border-[var(--border)] rounded-lg pl-9 pr-3 bg-[var(--surface-card)] focus:outline-none focus:border-[#044b46]/40 h-[40px] w-[150px]">
    </div>
</form>

{{-- Ringkasan Tim --}}
<div class="mb-5">
    <h2 class="font-display text-base font-semibold text-[var(--text-primary)] mb-3">Ringkasan Tim &middot; Tren 6 Bulan Terakhir</h2>
    <div class="grid grid-cols-1 lg:grid-cols-3 gap-4">
        <div class="card p-5">
            <p class="font-display text-sm font-semibold text-[var(--text-primary)] mb-2">Rata-rata Nilai KPI</p>
            <x-kpi-trend-line :trend="$teamTrend['kpi']" />
        </div>
        <div class="card p-5">
            <p class="font-display text-sm font-semibold text-[var(--text-primary)] mb-2">Ketepatan Kerja Tim</p>
            <x-kpi-trend-line :trend="$teamTrend['timeliness']" />
        </div>
        <div class="card p-5">
            <p class="font-display text-sm font-semibold text-[var(--text-primary)] mb-2">Kualitas Kerja Tim</p>
            <x-kpi-trend-line :trend="$teamTrend['quality']" />
        </div>
    </div>
</div>

{{-- Perbandingan Nilai KPI Antar Anggota --}}
<div class="card p-5 mb-5">
    <h2 class="font-display text-base font-semibold text-[var(--text-primary)] mb-1">Perbandingan Nilai KPI Anggota</h2>
    <p class="text-xs text-[var(--text-muted)] mb-3">Periode {{ $periodStart->translatedFormat('F Y') }}, diurutkan dari nilai tertinggi.</p>
    <x-kpi-comparison-bar :trend="$comparisonChart" />
</div>

{{-- Ketepatan Prediksi Risiko Tinggi (AI Delay Risk) - header konsisten
     dengan kartu "Akurasi Prediksi AI" di Dashboard (teaser fitur yang sama). --}}
<div class="card p-6 mb-5">
    <div class="flex items-center gap-3 mb-4">
        <div class="w-9 h-9 rounded-lg bg-[var(--info-tint)] flex items-center justify-center shrink-0">
            <span class="material-symbols-outlined text-[var(--info-text)] text-[18px]">verified</span>
        </div>
        <div>
            <h2 class="font-display text-base font-semibold text-[var(--text-primary)]">Ketepatan Prediksi Risiko Tinggi</h2>
            <p class="text-xs text-[var(--text-muted)]">Dari seluruh content yang diprediksi berisiko tinggi, persentase ini menunjukkan berapa banyak yang benar-benar terlambat. Angka ini mengevaluasi model AI, bukan KPI karyawan.</p>
        </div>
    </div>

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

        <div class="grid grid-cols-1 sm:grid-cols-3 gap-3">
            @foreach (['high' => 'Risiko Tinggi', 'medium' => 'Risiko Sedang', 'low' => 'Risiko Rendah'] as $level => $label)
                @php $b = $riskAccuracy['breakdown'][$level]; @endphp
                <div class="bg-[var(--surface-page)] rounded-lg p-3">
                    <p class="text-[10px] font-semibold text-[var(--text-muted)] uppercase mb-1">{{ $label }}</p>
                    <p class="text-sm text-[var(--text-primary)]">
                        {{ $b['total'] > 0 ? round($b['late'] / $b['total'] * 100) . '%' : '-' }}
                        <span class="text-xs text-[var(--text-muted)] font-normal">telat ({{ $b['late'] }}/{{ $b['total'] }})</span>
                    </p>
                </div>
            @endforeach
        </div>
    @endif
</div>

{{-- Daftar Anggota --}}
<div>
    <h2 class="font-display text-base font-semibold text-[var(--text-primary)] mb-3">Daftar Anggota</h2>
    <div class="card overflow-hidden hidden sm:block">
      <div class="overflow-x-auto">
        <table class="w-full table-fixed text-sm text-left">
            <thead class="bg-[var(--surface-page)]">
                <tr class="text-[var(--text-muted)] text-[11px] uppercase tracking-wide">
                    <th class="w-[28%] px-6 py-3 font-medium whitespace-nowrap">Anggota</th>
                    <th class="w-[14%] px-4 py-3 font-medium text-right whitespace-nowrap">Nilai KPI</th>
                    <th class="w-[15%] px-4 py-3 font-medium text-right whitespace-nowrap">Ketepatan Kerja</th>
                    <th class="w-[15%] px-4 py-3 font-medium text-right whitespace-nowrap">Kualitas Kerja</th>
                    <th class="w-[15%] px-4 py-3 font-medium text-right whitespace-nowrap">Bonus Performa</th>
                    <th class="w-[13%] px-4 py-3 font-medium text-right whitespace-nowrap">Konten</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($members as $m)
                    @php $user = $m['user']; $result = $m['result']; @endphp
                    <tr class="border-t border-[var(--surface-muted)] hover:bg-[var(--surface-page)] transition-colors cursor-pointer"
                        onclick="navigateTo('{{ route('profile.show', $user) }}')">
                        <td class="px-6 py-3.5">
                            <div class="flex items-center gap-3 min-w-0">
                                @if ($user->avatar_url)
                                    <img src="{{ $user->avatar_url }}" alt="" referrerpolicy="no-referrer" class="w-9 h-9 rounded-full object-cover shrink-0">
                                @else
                                    <div class="w-9 h-9 rounded-full bg-[var(--brand-solid)] text-white text-sm font-semibold flex items-center justify-center shrink-0">
                                        {{ strtoupper(substr($user->name, 0, 1)) }}
                                    </div>
                                @endif
                                <div class="min-w-0">
                                    <p class="font-medium text-[var(--text-primary)] truncate">{{ $user->name }}</p>
                                    <p class="text-xs text-[var(--text-muted)] truncate">{{ $user->roleNamesLabel() }}</p>
                                </div>
                            </div>
                        </td>
                        <td class="px-4 py-3.5 text-right [font-variant-numeric:tabular-nums]">
                            @if ($result)
                                <span class="font-semibold text-[var(--text-primary)]">{{ round($result->final_score) }}</span>
                            @else
                                <span class="text-xs text-[var(--text-muted)]">-</span>
                            @endif
                        </td>
                        <td class="px-4 py-3.5 text-right text-[var(--text-secondary)] [font-variant-numeric:tabular-nums]">
                            {{ $result && $result->timeliness_score !== null ? round($result->timeliness_score).'%' : '-' }}
                        </td>
                        <td class="px-4 py-3.5 text-right text-[var(--text-secondary)] [font-variant-numeric:tabular-nums]">
                            {{ $result ? round($result->quality_score).'%' : '-' }}
                        </td>
                        <td class="px-4 py-3.5 text-right text-[var(--text-secondary)] [font-variant-numeric:tabular-nums]">
                            {{ $result && $result->analytics_available ? '+'.round($result->analytics_bonus, 1) : '-' }}
                        </td>
                        <td class="px-4 py-3.5 text-right text-[var(--text-secondary)] [font-variant-numeric:tabular-nums]">{{ $result->sample_size ?? 0 }}</td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="6" class="px-6 py-10 text-center text-[var(--text-muted)] text-sm">Belum ada anggota tim tercatat.</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
      </div>
    </div>

    {{-- Mobile accordion list --}}
    <div class="sm:hidden space-y-3">
        @forelse ($members as $m)
            @php $userMobile = $m['user']; $resultMobile = $m['result']; @endphp
            <a href="{{ route('profile.show', $userMobile) }}" class="card p-3.5 block">
                <div class="flex items-center justify-between gap-3">
                    <div class="flex items-center gap-3 min-w-0">
                        @if ($userMobile->avatar_url)
                            <img src="{{ $userMobile->avatar_url }}" alt="" referrerpolicy="no-referrer" class="w-9 h-9 rounded-full object-cover shrink-0">
                        @else
                            <div class="w-9 h-9 rounded-full bg-[var(--brand-solid)] text-white text-sm font-semibold flex items-center justify-center shrink-0">
                                {{ strtoupper(substr($userMobile->name, 0, 1)) }}
                            </div>
                        @endif
                        <div class="min-w-0">
                            <p class="font-medium text-[var(--text-primary)] truncate">{{ $userMobile->name }}</p>
                            <p class="text-xs text-[var(--text-muted)] truncate">{{ $userMobile->roleNamesLabel() }}</p>
                        </div>
                    </div>
                    <div class="text-right shrink-0">
                        @if ($resultMobile)
                            <p class="font-display text-lg font-semibold text-[var(--text-primary)]">{{ round($resultMobile->final_score) }}</p>
                        @else
                            <p class="text-xs text-[var(--text-muted)]">-</p>
                        @endif
                    </div>
                </div>
            </a>
        @empty
            <div class="card p-6 text-center text-sm text-[var(--text-muted)]">Belum ada anggota tim tercatat.</div>
        @endforelse
    </div>

    {{-- Keterangan kolom - lihat docs/KPI_TEAM_PERFORMANCE.md untuk formula lengkap. --}}
    <div class="mt-4 px-1 text-xs text-[var(--text-muted)] space-y-1.5">
        <p><strong class="text-[var(--text-secondary)]">Nilai KPI</strong> = (Ketepatan Kerja &times; 60%) + (Kualitas Kerja &times; 40%), ditambah Bonus Performa. Tidak pernah lebih dari 100.</p>
        <p><strong class="text-[var(--text-secondary)]">Ketepatan Kerja</strong> = persentase konten yang tayang tepat waktu, dari konten yang datanya cukup untuk dinilai (dibandingkan dengan jadwal upload atau, kalau tidak ada, dengan deadline).</p>
        <p><strong class="text-[var(--text-secondary)]">Kualitas Kerja</strong> = persentase konten tanpa revisi internal dari tim (revisi permintaan klien tidak mengurangi nilai ini).</p>
        <p><strong class="text-[var(--text-secondary)]">Bonus Performa</strong> = tambahan nilai (maks. +10) dari performa reach/engagement konten dibanding rata-rata konten sejenis sebelumnya (klien, platform, dan format yang sama). Tidak pernah mengurangi nilai.</p>
        <p><strong class="text-[var(--text-secondary)]">Konten</strong> = jumlah konten yang tayang bulan ini dan menjadi dasar perhitungan anggota tersebut.</p>
        <p>Tanda &ldquo;-&rdquo; berarti belum ada cukup data untuk indikator itu (bukan nilai nol) - klik nama anggota untuk melihat rincian per konten.</p>
    </div>
</div>
