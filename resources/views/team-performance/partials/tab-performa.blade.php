{{-- Kartu Ringkasan --}}
<div class="grid grid-cols-3 gap-2.5 sm:gap-5 mb-5">
    <div class="card p-3 sm:p-6">
        <div class="w-7 h-7 sm:w-9 sm:h-9 rounded-lg bg-[var(--brand-tint)] flex items-center justify-center mb-2 sm:mb-3">
            <span class="material-symbols-outlined text-[var(--brand)] text-[15px] sm:text-[18px]">groups</span>
        </div>
        <p class="text-[11px] sm:text-sm text-[var(--text-secondary)] mb-1 sm:mb-2">Personel Aktif</p>
        <p class="font-display text-lg sm:text-2xl font-semibold text-[var(--text-primary)]">{{ $summary['personnel_active'] }}</p>
    </div>
    <div class="card p-3 sm:p-6">
        <div class="w-7 h-7 sm:w-9 sm:h-9 rounded-lg bg-[var(--info-tint)] flex items-center justify-center mb-2 sm:mb-3">
            <span class="material-symbols-outlined text-[var(--info-text)] text-[15px] sm:text-[18px]">assignment</span>
        </div>
        <p class="text-[11px] sm:text-sm text-[var(--text-secondary)] mb-1 sm:mb-2">Total Tugas Aktif</p>
        <p class="font-display text-lg sm:text-2xl font-semibold text-[var(--text-primary)]">{{ $summary['total_active_items'] }}</p>
    </div>
    <div class="card p-3 sm:p-6">
        <div class="w-7 h-7 sm:w-9 sm:h-9 rounded-lg bg-[var(--warning-tint)] flex items-center justify-center mb-2 sm:mb-3">
            <span class="material-symbols-outlined text-[var(--warning-text)] text-[15px] sm:text-[18px]">history_edu</span>
        </div>
        <p class="text-[11px] sm:text-sm text-[var(--text-secondary)] mb-1 sm:mb-2">Rata-rata Revisi/Orang</p>
        <p class="font-display text-lg sm:text-2xl font-semibold text-[var(--text-primary)]">{{ $summary['avg_revision'] }}</p>
    </div>
</div>

{{-- Akurasi Prediksi AI Delay Risk (feedback loop) --}}
<div class="card p-6 mb-5">
    <div class="flex items-center gap-3 mb-4">
        <div class="w-9 h-9 rounded-lg bg-[var(--info-tint)] flex items-center justify-center shrink-0">
            <span class="material-symbols-outlined text-[var(--info-text)] text-[18px]">verified</span>
        </div>
        <div>
            <h2 class="text-sm font-semibold text-[var(--text-primary)]">Akurasi Prediksi AI Delay Risk</h2>
            <p class="text-xs text-[var(--text-muted)]">Dibandingkan dengan status telat/tidak aktual dari konten yang sudah upload</p>
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

{{-- Tabel Anggota Tim --}}
<div>
    <h2 class="text-sm font-semibold text-[var(--text-primary)] mb-3">Anggota Tim</h2>
    <div class="card overflow-hidden hidden sm:block">
      <div class="overflow-x-auto">
        <table class="w-full table-fixed text-sm text-left">
            <thead class="bg-[var(--surface-page)]">
                <tr class="text-[var(--text-muted)] text-[11px] uppercase tracking-wide">
                    <th class="w-[28%] px-6 py-3 font-medium whitespace-nowrap">Anggota</th>
                    <th class="w-[12%] px-4 py-3 font-medium text-right whitespace-nowrap">Konten</th>
                    <th class="w-[14%] px-4 py-3 font-medium text-right whitespace-nowrap">Tugas Aktif</th>
                    <th class="w-[12%] px-4 py-3 font-medium text-right whitespace-nowrap">Terlambat</th>
                    <th class="w-[12%] px-4 py-3 font-medium text-right whitespace-nowrap">Selesai</th>
                    <th class="w-[10%] px-4 py-3 font-medium text-right whitespace-nowrap">Revisi</th>
                    <th class="w-[12%] px-4 py-3 font-medium text-right whitespace-nowrap">Risiko</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($members as $m)
                    @php $user = $m['user']; @endphp
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
                                    @unless ($user->login_enabled)
                                        <span class="badge badge-neutral">Belum ada akses</span>
                                    @endunless
                                </div>
                            </div>
                        </td>
                        <td class="px-4 py-3.5 text-right text-[var(--text-secondary)] [font-variant-numeric:tabular-nums]">{{ $m['content_count'] }}</td>
                        <td class="px-4 py-3.5 text-right [font-variant-numeric:tabular-nums]">
                            <span class="inline-flex items-center gap-1.5 text-[var(--text-secondary)]">
                                <span class="w-1.5 h-1.5 rounded-full shrink-0 {{ $m['is_overloaded'] ? 'bg-[var(--danger-text)]' : 'bg-[var(--brand)]' }}"></span>
                                {{ $m['active_count'] }}
                            </span>
                        </td>
                        <td class="px-4 py-3.5 text-right [font-variant-numeric:tabular-nums] {{ $m['overdue_count'] > 0 ? 'text-[var(--danger-text)] font-semibold' : 'text-[var(--text-muted)]' }}">
                            {{ $m['overdue_count'] }}
                        </td>
                        <td class="px-4 py-3.5 text-right text-[var(--text-secondary)] [font-variant-numeric:tabular-nums]">{{ $m['done_count'] }}</td>
                        <td class="px-4 py-3.5 text-right text-[var(--text-secondary)] [font-variant-numeric:tabular-nums]">{{ $m['revision_count'] }}</td>
                        <td class="px-4 py-3.5 text-right">
                            @if ($m['avg_risk_score'] !== null)
                                <span class="badge {{ $m['avg_risk_score'] >= 70 ? 'badge-danger' : ($m['avg_risk_score'] >= 40 ? 'badge-warning' : 'badge-success') }}">
                                    {{ $m['avg_risk_score'] }}%
                                </span>
                            @else
                                <span class="text-xs text-[var(--text-muted)]">-</span>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="7" class="px-6 py-10 text-center text-[var(--text-muted)] text-sm">Belum ada anggota tim tercatat.</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
      </div>
    </div>

    {{-- Mobile accordion list --}}
    <div class="sm:hidden space-y-3">
        @forelse ($members as $m)
            @php $userMobile = $m['user']; @endphp
            <div class="card p-3.5" x-data="{ open: false }">
                <button type="button" class="w-full text-left flex items-center justify-between gap-3 cursor-pointer" @click="open = !open" :aria-expanded="open">
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
                            <div class="flex items-center gap-2 mt-1">
                                <span class="text-xs text-[var(--text-muted)]">{{ $m['content_count'] }} konten</span>
                                <span class="text-xs {{ $m['overdue_count'] > 0 ? 'text-[var(--danger-text)] font-semibold' : 'text-[var(--text-muted)]' }}">&middot; Terlambat: {{ $m['overdue_count'] }}</span>
                                @if ($m['avg_risk_score'] !== null)
                                    <span class="badge {{ $m['avg_risk_score'] >= 70 ? 'badge-danger' : ($m['avg_risk_score'] >= 40 ? 'badge-warning' : 'badge-success') }}">
                                        {{ $m['avg_risk_score'] }}%
                                    </span>
                                @endif
                            </div>
                            @unless ($userMobile->login_enabled)
                                <span class="badge badge-neutral mt-1">Belum memiliki akses dashboard</span>
                            @endunless
                        </div>
                    </div>
                    <span class="shrink-0 w-8 h-8 flex items-center justify-center">
                        <span class="material-symbols-outlined text-[var(--text-muted)] transition-transform" :class="open && 'rotate-180'">expand_more</span>
                    </span>
                </button>

                <div x-show="open" x-cloak x-transition class="mt-3 pt-3 border-t border-[var(--surface-muted)] space-y-2">
                    <div class="flex items-center justify-between text-xs">
                        <span class="text-[var(--text-muted)]">Tugas Aktif</span>
                        <span class="text-[var(--text-primary)] font-medium flex items-center gap-1.5">
                            <span class="w-1.5 h-1.5 rounded-full {{ $m['is_overloaded'] ? 'bg-[var(--danger-text)]' : 'bg-[var(--brand)]' }}"></span>
                            {{ $m['active_count'] }}
                        </span>
                    </div>
                    <div class="flex items-center justify-between text-xs">
                        <span class="text-[var(--text-muted)]">Total Selesai</span>
                        <span class="text-[var(--text-primary)] font-medium">{{ $m['done_count'] }}</span>
                    </div>
                    <div class="flex items-center justify-between text-xs">
                        <span class="text-[var(--text-muted)]">Jumlah Revisi</span>
                        <span class="text-[var(--text-primary)] font-medium">{{ $m['revision_count'] }}</span>
                    </div>
                    <a href="{{ route('profile.show', $userMobile) }}"
                        class="mt-2 flex items-center justify-center gap-1.5 text-xs font-semibold text-[var(--brand)] bg-[var(--brand-tint)] hover:bg-[var(--brand-tint-hover)] rounded-lg py-2 transition-colors">
                        Lihat Profil <span class="material-symbols-outlined text-[15px]">arrow_forward</span>
                    </a>
                </div>
            </div>
        @empty
            <div class="card p-6 text-center text-sm text-[var(--text-muted)]">Belum ada anggota tim tercatat.</div>
        @endforelse
    </div>
</div>
