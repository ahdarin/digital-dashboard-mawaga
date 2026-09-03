@php
    $kpiStatusBadgeClass = fn ($score) => match (\App\Models\UserMonthlyKpiResult::statusFromScore($score)) {
        'sangat_baik', 'baik' => 'badge-success',
        'perlu_perhatian' => 'badge-warning',
        default => 'badge-danger',
    };
@endphp

<div class="mb-8">
    <h2 class="font-display text-base font-semibold text-[var(--text-primary)] mb-3">KPI Bulan Ini &middot; {{ $kpiPeriod->translatedFormat('F Y') }}</h2>

    @if (! $kpiResult)
        <div class="card p-6 text-center">
            <p class="text-sm font-medium text-[var(--text-primary)]">Belum ada data KPI untuk periode ini.</p>
            <p class="text-xs text-[var(--text-muted)] mt-1">Belum ada konten dengan publication pada bulan ini yang tercatat atas nama {{ $user->name }}.</p>
        </div>
    @else
        <div class="grid grid-cols-2 sm:grid-cols-4 gap-4 mb-6">
            <div class="card p-5 bg-[var(--brand-tint)]">
                <p class="text-xs text-[var(--brand)] mb-1">Nilai KPI</p>
                <p class="font-display text-[26px] font-semibold text-[var(--brand)] [font-variant-numeric:tabular-nums]">{{ round($kpiResult->final_score) }}</p>
                <span class="badge {{ $kpiStatusBadgeClass($kpiResult->final_score) }} mt-2">{{ $kpiResult->scoreLabel() }}</span>
            </div>
            <div class="card p-5">
                <p class="text-xs text-[var(--text-muted)] mb-1">Ketepatan Kerja</p>
                <p class="font-display text-[26px] font-semibold text-[var(--text-primary)] [font-variant-numeric:tabular-nums]">{{ $kpiResult->timeliness_score !== null ? round($kpiResult->timeliness_score).'%' : '-' }}</p>
            </div>
            <div class="card p-5">
                <p class="text-xs text-[var(--text-muted)] mb-1">Kualitas Kerja</p>
                <p class="font-display text-[26px] font-semibold text-[var(--text-primary)] [font-variant-numeric:tabular-nums]">{{ round($kpiResult->quality_score) }}%</p>
            </div>
            <div class="card p-5">
                <p class="text-xs text-[var(--text-muted)] mb-1">Bonus Performa</p>
                <p class="font-display text-[26px] font-semibold text-[var(--text-primary)] [font-variant-numeric:tabular-nums]">{{ $kpiResult->analytics_available ? '+'.round($kpiResult->analytics_bonus, 1) : '-' }}</p>
            </div>
        </div>

        <div class="card overflow-hidden">
            <div class="p-5 pb-4">
                <h3 class="font-display text-base font-semibold text-[var(--text-primary)]">Konten yang Menjadi Dasar Nilai ({{ $kpiResult->sample_size }})</h3>
            </div>
            <div class="px-5 pb-5">
                <div class="overflow-x-auto">
                    <table class="w-full text-xs">
                        <thead>
                            <tr class="text-[var(--text-muted)] text-left border-b border-[var(--surface-muted)]">
                                <th class="py-2 pr-3 font-medium">Konten</th>
                                <th class="py-2 pr-3 font-medium">Klien</th>
                                <th class="py-2 pr-3 font-medium">Tanggal Publikasi</th>
                                <th class="py-2 pr-3 font-medium">Ketepatan</th>
                                <th class="py-2 pr-3 font-medium">Revisi Internal</th>
                                <th class="py-2 font-medium text-right">Bonus Performa</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($kpiResult->breakdown as $row)
                                <tr class="border-b border-[var(--surface-muted)] last:border-0">
                                    <td class="py-2 pr-3 text-[var(--text-primary)]">{{ $row['title'] ?? '-' }}</td>
                                    <td class="py-2 pr-3 text-[var(--text-secondary)]">{{ $row['client_name'] ?? '-' }}</td>
                                    <td class="py-2 pr-3 text-[var(--text-secondary)]">{{ $row['published_at'] ? \Illuminate\Support\Carbon::parse($row['published_at'])->translatedFormat('d M Y') : '-' }}</td>
                                    <td class="py-2 pr-3">
                                        @if ($row['on_time'] === true)
                                            <span class="badge badge-success">Tepat waktu</span>
                                        @elseif ($row['on_time'] === false)
                                            <span class="badge badge-danger">Terlambat</span>
                                        @else
                                            <span class="badge badge-neutral" title="{{ $row['timeliness_reason'] }}">Tidak dapat dinilai</span>
                                        @endif
                                    </td>
                                    <td class="py-2 pr-3 text-[var(--text-secondary)]">{{ $row['has_internal_revision'] ? 'Ada' : 'Tidak ada' }}</td>
                                    <td class="py-2 text-right text-[var(--text-secondary)]">
                                        {{ $row['analytics_bonus'] !== null ? '+'.round($row['analytics_bonus'], 1) : ($row['analytics_reason'] ?? 'Belum tersedia') }}
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    @endif
</div>
