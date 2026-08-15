{{-- Kartu Ringkasan --}}
<div class="grid grid-cols-3 gap-2.5 sm:gap-5 mb-5">
    <div class="card p-3 sm:p-6">
        <div class="w-7 h-7 sm:w-9 sm:h-9 rounded-lg bg-[#f0f5f4] flex items-center justify-center mb-2 sm:mb-3">
            <span class="material-symbols-outlined text-[#044b46] text-[15px] sm:text-[18px]">groups</span>
        </div>
        <p class="text-[11px] sm:text-sm text-[#5c6266] mb-1 sm:mb-2">Personel Aktif</p>
        <p class="font-display text-lg sm:text-2xl font-semibold text-[#14181a]">{{ $summary['personnel_active'] }}</p>
    </div>
    <div class="card p-3 sm:p-6">
        <div class="w-7 h-7 sm:w-9 sm:h-9 rounded-lg bg-[#eef2fb] flex items-center justify-center mb-2 sm:mb-3">
            <span class="material-symbols-outlined text-[#3452a8] text-[15px] sm:text-[18px]">assignment</span>
        </div>
        <p class="text-[11px] sm:text-sm text-[#5c6266] mb-1 sm:mb-2">Total Task Aktif</p>
        <p class="font-display text-lg sm:text-2xl font-semibold text-[#14181a]">{{ $summary['total_active_items'] }}</p>
    </div>
    <div class="card p-3 sm:p-6">
        <div class="w-7 h-7 sm:w-9 sm:h-9 rounded-lg bg-[#fdf6ec] flex items-center justify-center mb-2 sm:mb-3">
            <span class="material-symbols-outlined text-[#b8873a] text-[15px] sm:text-[18px]">history_edu</span>
        </div>
        <p class="text-[11px] sm:text-sm text-[#5c6266] mb-1 sm:mb-2">Rata-rata Revisi/Orang</p>
        <p class="font-display text-lg sm:text-2xl font-semibold text-[#14181a]">{{ $summary['avg_revision'] }}</p>
    </div>
</div>

{{-- Akurasi Prediksi AI Delay Risk (feedback loop) --}}
<div class="card p-6 mb-5">
    <div class="flex items-center gap-3 mb-4">
        <div class="w-9 h-9 rounded-lg bg-[#eef2fb] flex items-center justify-center shrink-0">
            <span class="material-symbols-outlined text-[#3452a8] text-[18px]">verified</span>
        </div>
        <div>
            <h3 class="text-sm font-semibold text-[#14181a]">Akurasi Prediksi AI Delay Risk</h3>
            <p class="text-xs text-[#9aa0a4]">Dibandingkan dengan status telat/tidak aktual dari konten yang sudah upload</p>
        </div>
    </div>

    @if ($riskAccuracy['total_evaluated'] === 0)
        <p class="text-sm text-[#9aa0a4] py-2">Belum ada cukup data (butuh konten yang sudah upload dan pernah dapat skor risiko).</p>
    @else
        <div class="flex items-baseline gap-2 mb-4">
            @if ($riskAccuracy['high_risk_accuracy'] !== null)
                <p class="font-display text-2xl font-semibold text-[#14181a]">{{ $riskAccuracy['high_risk_accuracy'] }}%</p>
                <p class="text-xs text-[#5c6266]">dari konten yang diprediksi <strong>High Risk</strong> benar-benar terlambat</p>
            @else
                <p class="text-sm text-[#9aa0a4]">Belum ada konten dengan prediksi High Risk yang sudah selesai upload.</p>
            @endif
        </div>

        <div class="grid grid-cols-1 sm:grid-cols-3 gap-3">
            @foreach (['high' => 'High', 'medium' => 'Medium', 'low' => 'Low'] as $level => $label)
                @php $b = $riskAccuracy['breakdown'][$level]; @endphp
                <div class="bg-[#f7f8fc] rounded-lg p-3">
                    <p class="text-[10px] font-semibold text-[#9aa0a4] uppercase mb-1">{{ $label }}</p>
                    <p class="text-sm text-[#14181a]">
                        {{ $b['total'] > 0 ? round($b['late'] / $b['total'] * 100) . '%' : '-' }}
                        <span class="text-xs text-[#9aa0a4] font-normal">telat ({{ $b['late'] }}/{{ $b['total'] }})</span>
                    </p>
                </div>
            @endforeach
        </div>
    @endif
</div>

{{-- Tabel Team Members --}}
<div>
    <h3 class="text-sm font-semibold text-[#14181a] mb-3">Team Members</h3>
    <div class="card overflow-hidden hidden sm:block">
      <div class="overflow-x-auto">
        <table class="w-full text-sm text-left">
            <thead class="bg-[#f7f8fc]">
                <tr class="text-[#9aa0a4] text-[11px] uppercase tracking-wide">
                    <th class="px-6 py-3 font-medium whitespace-nowrap">Anggota</th>
                    <th class="px-4 py-3 font-medium whitespace-nowrap">Task Aktif</th>
                    <th class="px-4 py-3 font-medium whitespace-nowrap">Overdue</th>
                    <th class="px-4 py-3 font-medium whitespace-nowrap">Selesai Bulan Ini</th>
                    <th class="px-4 py-3 font-medium whitespace-nowrap">Jumlah Revisi</th>
                    <th class="px-4 py-3 font-medium whitespace-nowrap">Rata-rata Delay Risk</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($members as $m)
                    <tr class="border-t border-[#f2f3f6] hover:bg-[#f7f8fc] cursor-pointer transition-colors"
                        onclick="window.location='{{ route('profile.show', $m['user']) }}'">
                        <td class="px-6 py-3.5">
                            <div class="flex items-center gap-3">
                                @if ($m['user']->avatar_url)
                                    <img src="{{ $m['user']->avatar_url }}" referrerpolicy="no-referrer" class="w-9 h-9 rounded-full object-cover">
                                @else
                                    <div class="w-9 h-9 rounded-full bg-[#044b46] text-white text-sm font-semibold flex items-center justify-center shrink-0">
                                        {{ strtoupper(substr($m['user']->name, 0, 1)) }}
                                    </div>
                                @endif
                                <div>
                                    <p class="font-medium text-[#14181a]">{{ $m['user']->name }}</p>
                                    <p class="text-xs text-[#9aa0a4]">{{ $m['user']->role->name ?? '-' }}</p>
                                </div>
                            </div>
                        </td>
                        <td class="px-4 py-3.5 whitespace-nowrap">
                            <span class="flex items-center gap-1.5 text-[#5c6266]">
                                <span class="w-1.5 h-1.5 rounded-full {{ $m['is_overloaded'] ? 'bg-[#b3423e]' : 'bg-[#044b46]' }}"></span>
                                {{ $m['active_count'] }} Task Aktif
                            </span>
                        </td>
                        <td class="px-4 py-3.5 whitespace-nowrap {{ $m['overdue_count'] > 0 ? 'text-[#b3423e] font-semibold' : 'text-[#9aa0a4]' }}">
                            {{ $m['overdue_count'] }}
                        </td>
                        <td class="px-4 py-3.5 text-[#5c6266] whitespace-nowrap">{{ $m['done_count'] }}</td>
                        <td class="px-4 py-3.5 text-[#5c6266] whitespace-nowrap">{{ $m['revision_count'] }}</td>
                        <td class="px-4 py-3.5 whitespace-nowrap">
                            @if ($m['avg_risk_score'] !== null)
                                <span class="text-xs font-semibold px-2 py-0.5 rounded-full
                                    {{ $m['avg_risk_score'] >= 70 ? 'bg-[#fdf2f1] text-[#b3423e]' : ($m['avg_risk_score'] >= 40 ? 'bg-[#fdf6ec] text-[#8a6423]' : 'bg-[#f0f5f4] text-[#0f7a5f]') }}">
                                    {{ $m['avg_risk_score'] }}%
                                </span>
                            @else
                                <span class="text-xs text-[#c3c7cb]">-</span>
                            @endif
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
      </div>
    </div>

    {{-- Mobile accordion list --}}
    <div class="sm:hidden space-y-3">
        @foreach ($members as $m)
            <div class="card p-3.5" x-data="{ open: false }">
                <div class="flex items-center justify-between gap-3 cursor-pointer" @click="open = !open">
                    <div class="flex items-center gap-3 min-w-0">
                        @if ($m['user']->avatar_url)
                            <img src="{{ $m['user']->avatar_url }}" referrerpolicy="no-referrer" class="w-9 h-9 rounded-full object-cover shrink-0">
                        @else
                            <div class="w-9 h-9 rounded-full bg-[#044b46] text-white text-sm font-semibold flex items-center justify-center shrink-0">
                                {{ strtoupper(substr($m['user']->name, 0, 1)) }}
                            </div>
                        @endif
                        <div class="min-w-0">
                            <p class="font-medium text-[#14181a] truncate">{{ $m['user']->name }}</p>
                            <div class="flex items-center gap-2 mt-1">
                                <span class="text-xs {{ $m['overdue_count'] > 0 ? 'text-[#b3423e] font-semibold' : 'text-[#9aa0a4]' }}">Overdue: {{ $m['overdue_count'] }}</span>
                                @if ($m['avg_risk_score'] !== null)
                                    <span class="text-[10px] font-semibold px-2 py-0.5 rounded-full
                                        {{ $m['avg_risk_score'] >= 70 ? 'bg-[#fdf2f1] text-[#b3423e]' : ($m['avg_risk_score'] >= 40 ? 'bg-[#fdf6ec] text-[#8a6423]' : 'bg-[#f0f5f4] text-[#0f7a5f]') }}">
                                        {{ $m['avg_risk_score'] }}%
                                    </span>
                                @else
                                    <span class="text-xs text-[#c3c7cb]">-</span>
                                @endif
                            </div>
                        </div>
                    </div>
                    <span class="shrink-0 w-8 h-8 flex items-center justify-center">
                        <span class="material-symbols-outlined text-[#9aa0a4] transition-transform" :class="open && 'rotate-180'">expand_more</span>
                    </span>
                </div>

                <div x-show="open" x-cloak x-transition class="mt-3 pt-3 border-t border-[#f2f3f6] space-y-2">
                    <div class="flex items-center justify-between text-xs">
                        <span class="text-[#9aa0a4]">Task Aktif</span>
                        <span class="text-[#14181a] font-medium flex items-center gap-1.5">
                            <span class="w-1.5 h-1.5 rounded-full {{ $m['is_overloaded'] ? 'bg-[#b3423e]' : 'bg-[#044b46]' }}"></span>
                            {{ $m['active_count'] }}
                        </span>
                    </div>
                    <div class="flex items-center justify-between text-xs">
                        <span class="text-[#9aa0a4]">Selesai Bulan Ini</span>
                        <span class="text-[#14181a] font-medium">{{ $m['done_count'] }}</span>
                    </div>
                    <div class="flex items-center justify-between text-xs">
                        <span class="text-[#9aa0a4]">Jumlah Revisi</span>
                        <span class="text-[#14181a] font-medium">{{ $m['revision_count'] }}</span>
                    </div>
                    <a href="{{ route('profile.show', $m['user']) }}"
                        class="mt-2 flex items-center justify-center gap-1.5 text-xs font-semibold text-[#044b46] bg-[#f0f5f4] hover:bg-[#e4ede9] rounded-lg py-2 transition-colors">
                        Lihat Profil <span class="material-symbols-outlined text-[15px]">arrow_forward</span>
                    </a>
                </div>
            </div>
        @endforeach
    </div>
</div>
