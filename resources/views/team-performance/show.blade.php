@extends('layouts.app')
@section('title', 'Detail KPI - '.$member->name)
@section('content')
<div class="p-4 sm:p-6 lg:p-8 max-w-[1100px] mx-auto">

    <a href="{{ route('team-performance.index', ['tab' => 'anggota']) }}" class="inline-flex items-center gap-1 text-xs text-[var(--text-muted)] hover:text-[var(--text-secondary)] mb-4">
        <span class="material-symbols-outlined text-[15px]">arrow_back</span> Kembali ke Anggota
    </a>

    <div class="flex items-center gap-3 mb-7">
        @if ($member->avatar_url)
            <img src="{{ $member->avatar_url }}" alt="" referrerpolicy="no-referrer" class="w-12 h-12 rounded-full object-cover">
        @else
            <div class="w-12 h-12 rounded-full bg-[var(--brand-solid)] text-white text-lg font-semibold flex items-center justify-center">
                {{ strtoupper(substr($member->name, 0, 1)) }}
            </div>
        @endif
        <div>
            <h1 class="font-display text-xl sm:text-2xl font-semibold text-[var(--text-primary)]">{{ $member->name }}</h1>
            <p class="text-[var(--text-secondary)] text-sm">Periode {{ $periodStart->translatedFormat('F Y') }}</p>
        </div>
    </div>

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
    @elseif ($results->isEmpty())
        <div class="card p-6 text-center">
            <p class="text-sm font-medium text-[var(--text-primary)]">{{ $member->name }} tidak punya aktivitas KPI pada periode ini.</p>
            <p class="text-xs text-[var(--text-muted)] mt-1">Belum ada brief, produksi, publikasi, atau keputusan yang tercatat untuk periode ini.</p>
        </div>
    @else
        @if ($usingFallbackPeriod)
            <div class="card p-3 mb-5 flex items-center gap-2 bg-[var(--warning-tint)]">
                <span class="material-symbols-outlined text-[var(--warning-text)] text-[16px]">history</span>
                <p class="text-xs text-[var(--warning-text)]">Menampilkan data periode {{ $run->period_start->translatedFormat('F Y') }} sementara periode ini diperbarui.</p>
            </div>
        @endif

        {{-- Role selector - tab kecil per (role[, client]), TIDAK ADA overall score gabungan --}}
        <div class="flex flex-wrap gap-2 mb-5" role="tablist">
            @foreach ($results as $r)
                <a href="{{ route('team-performance.show', ['user' => $member, 'role_id' => $r->role_id, 'client_id' => $r->client_id, 'period_start' => $periodStart->format('Y-m')]) }}#role-{{ $r->id }}"
                   role="tab" aria-selected="{{ $selectedRoleId === $r->role_id ? 'true' : 'false' }}"
                   class="text-sm font-medium px-4 py-2 rounded-lg border {{ $selectedRoleId === $r->role_id ? 'border-[#044b46]/40 bg-[var(--brand-tint)] text-[var(--brand)]' : 'border-[var(--border)] text-[var(--text-secondary)] hover:bg-[var(--surface-page)]' }}">
                    {{ $r->role->name ?? '-' }}{{ $r->client ? ' - '.$r->client->name : '' }}
                </a>
            @endforeach
        </div>

        @foreach ($results->where('role_id', $selectedRoleId) as $result)
            <div id="role-{{ $result->id }}" class="card p-6 mb-5">
                <div class="flex items-center justify-between mb-4">
                    <h2 class="text-sm font-semibold text-[var(--text-primary)]">
                        {{ $result->role->name ?? '-' }}{{ $result->client ? ' - '.$result->client->name : '' }}
                    </h2>
                    @php
                        $statusText = match ($result->status_label->value) {
                            'sehat' => 'Sehat', 'perlu_perhatian' => 'Perlu Perhatian', 'sementara' => 'Sementara', default => 'Data Belum Cukup',
                        };
                        $statusBadge = match ($result->status_label->value) {
                            'sehat' => 'badge-success', 'perlu_perhatian' => 'badge-warning', default => 'badge-neutral',
                        };
                    @endphp
                    <span class="badge {{ $statusBadge }}">{{ $statusText }}</span>
                </div>

                <div class="grid grid-cols-1 sm:grid-cols-4 gap-3 mb-4">
                    <div class="bg-[var(--surface-page)] rounded-lg p-3 text-center" title="Ketepatan dan kualitas alur kerja sesuai role.">
                        <p class="text-[10px] uppercase text-[var(--text-muted)] mb-1">Kualitas Proses</p>
                        <p class="font-display text-lg font-semibold text-[var(--text-primary)]">{{ $result->process_score !== null ? round($result->process_score) : '-' }}</p>
                    </div>
                    <div class="bg-[var(--surface-page)] rounded-lg p-3 text-center" title="Performa analytics konten yang ditangani.">
                        <p class="text-[10px] uppercase text-[var(--text-muted)] mb-1">Hasil Konten</p>
                        <p class="font-display text-lg font-semibold text-[var(--text-primary)]">{{ $result->direct_outcome_score !== null ? round($result->direct_outcome_score) : '-' }}</p>
                    </div>
                    <div class="bg-[var(--surface-page)] rounded-lg p-3 text-center" title="Perkembangan akun klien yang dibagikan ke seluruh PIC yang terlibat.">
                        <p class="text-[10px] uppercase text-[var(--text-muted)] mb-1">Performa Klien</p>
                        <p class="font-display text-lg font-semibold text-[var(--text-primary)]">{{ $result->portfolio_outcome_score !== null ? round($result->portfolio_outcome_score) : '-' }}</p>
                    </div>
                    <div class="bg-[var(--brand-tint)] rounded-lg p-3 text-center">
                        <p class="text-[10px] uppercase text-[var(--brand)] mb-1">Nilai KPI</p>
                        {{-- #13: nilai KPI TIDAK PERNAH ditampilkan kalau status Data Belum Cukup. --}}
                        <p class="font-display text-lg font-semibold text-[var(--brand)]">
                            {{ $result->status_label->value !== 'data_belum_cukup' && $result->composite_score !== null ? round($result->composite_score) : 'Data belum cukup' }}
                        </p>
                    </div>
                </div>

                <p class="text-xs text-[var(--text-muted)] mb-4" title="Apakah jumlah dan kualitas data cukup untuk menyimpulkan KPI.">
                    Jumlah Data: {{ $result->sample_size }} &middot; Kelengkapan Data: {{ $result->coverage_status->label() }}
                </p>

                {{-- Breakdown proses mentah - untuk audit formula --}}
                @if (!empty($result->component_breakdown['process']))
                    <div class="mb-4">
                        <h3 class="text-xs font-semibold text-[var(--text-primary)] uppercase mb-2">Rincian Kualitas Proses</h3>
                        <div class="overflow-x-auto">
                            <table class="w-full text-xs">
                                <thead>
                                    <tr class="text-[var(--text-muted)] text-left border-b border-[var(--surface-muted)]">
                                        <th class="py-1.5 pr-3 font-medium">Metrik</th>
                                        <th class="py-1.5 pr-3 font-medium">Nilai</th>
                                        <th class="py-1.5 pr-3 font-medium">Kelengkapan Data</th>
                                        <th class="py-1.5 font-medium">Jumlah Data</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach ($result->component_breakdown['process'] as $key => $metric)
                                        <tr class="border-b border-[var(--surface-muted)] last:border-0">
                                            <td class="py-1.5 pr-3 text-[var(--text-secondary)]">{{ str_replace('_', ' ', $key) }}</td>
                                            <td class="py-1.5 pr-3 text-[var(--text-primary)] font-medium">
                                                {{ $metric['value'] !== null ? $metric['value'] : 'Data belum cukup' }}{{ $metric['value'] !== null && $metric['unit'] === 'hours' ? ' jam' : '' }}
                                            </td>
                                            <td class="py-1.5 pr-3 text-[var(--text-muted)]">{{ is_string($metric['coverage']) ? $metric['coverage'] : $metric['coverage']->value }}</td>
                                            <td class="py-1.5 text-[var(--text-muted)]">{{ $metric['sample_size'] }}</td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    </div>
                @endif
            </div>
        @endforeach

        {{-- Detail content outcome yang berkontribusi --}}
        @if ($contentOutcomes->isNotEmpty())
            <div class="card p-6">
                <h2 class="text-sm font-semibold text-[var(--text-primary)] mb-4">Konten yang Berkontribusi pada Hasil Konten</h2>
                <div class="overflow-x-auto">
                    <table class="w-full text-xs">
                        <thead>
                            <tr class="text-[var(--text-muted)] text-left border-b border-[var(--surface-muted)]">
                                <th class="py-2 pr-3 font-medium">Konten</th>
                                <th class="py-2 pr-3 font-medium">Format</th>
                                <th class="py-2 pr-3 font-medium">Periode Ukur</th>
                                <th class="py-2 pr-3 font-medium">Kelengkapan Data</th>
                                <th class="py-2 pr-3 font-medium">Jumlah Pembanding</th>
                                <th class="py-2 font-medium text-right">Skor</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($contentOutcomes as $outcome)
                                <tr class="border-b border-[var(--surface-muted)] last:border-0">
                                    <td class="py-2 pr-3 text-[var(--text-primary)]">
                                        {{ $outcome->contentItem?->title ?? '-' }}
                                        <span class="block text-[var(--text-muted)]">{{ $outcome->contentItem?->client?->name }}</span>
                                    </td>
                                    <td class="py-2 pr-3 text-[var(--text-secondary)]">{{ $outcome->format_group->label() }}</td>
                                    <td class="py-2 pr-3 text-[var(--text-secondary)]">{{ $outcome->measurement_window->label() }}</td>
                                    <td class="py-2 pr-3 text-[var(--text-secondary)]">{{ $outcome->coverage_status->label() }}</td>
                                    <td class="py-2 pr-3 text-[var(--text-secondary)]">{{ $outcome->peer_sample_size }}</td>
                                    <td class="py-2 text-right font-medium text-[var(--text-primary)]">
                                        {{ $outcome->normalized_score !== null ? round($outcome->normalized_score) : ($outcome->exclusion_reason ?? 'Data belum cukup') }}
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>
        @endif
    @endif
</div>
@endsection
