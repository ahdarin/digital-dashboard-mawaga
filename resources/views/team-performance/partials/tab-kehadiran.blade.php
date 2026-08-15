@php
    $badgeClasses = [
        'Tepat Waktu' => 'bg-[#f0f5f4] text-[#0f7a5f]',
        'Lembur' => 'bg-[#eef2fb] text-[#3452a8]',
        'Sudah Check-In' => 'bg-[#eef2fb] text-[#3452a8]',
        'Telat' => 'bg-[#fdf6ec] text-[#8a6423]',
        'Telat (Belum Pulang)' => 'bg-[#fdf6ec] text-[#8a6423]',
        'Pulang Awal' => 'bg-[#fdf6ec] text-[#8a6423]',
        'Lupa Check-Out' => 'bg-[#fdf6ec] text-[#8a6423]',
        'Tidak Hadir' => 'bg-[#fdf2f1] text-[#b3423e]',
        'Belum Check-In' => 'bg-[#f2f3f6] text-[#5c6266]',
        'Libur' => 'bg-[#f2f3f6] text-[#9aa0a4]',
    ];
@endphp

<form id="filter-form" method="GET" action="{{ route('team-performance.index') }}">
    <input type="hidden" name="tab" value="kehadiran">
</form>

<div class="flex flex-wrap items-end gap-3 mb-5">
    <div>
        <label class="block text-xs font-medium text-[#5c6266] mb-1">Tanggal</label>
        <div class="relative">
            <span class="material-symbols-outlined absolute left-3 top-1/2 -translate-y-1/2 text-[#c3c7cb] text-[17px] pointer-events-none">calendar_month</span>
            <input type="text" name="date" form="filter-form" value="{{ $date->toDateString() }}"
                data-flatpickr="date" data-autosubmit="true" autocomplete="off"
                class="border border-[#eef0f4] rounded-lg pl-9 pr-3 py-2 text-sm bg-white focus:outline-none focus:border-[#044b46]/40 h-[40px] w-[150px]" readonly>
        </div>
    </div>
    <div>
        <label class="block text-xs font-medium text-[#5c6266] mb-1">Bulan (ringkasan)</label>
        <div class="relative">
            <span class="material-symbols-outlined absolute left-3 top-1/2 -translate-y-1/2 text-[#c3c7cb] text-[17px] pointer-events-none">calendar_month</span>
            <input type="text" name="month" form="filter-form" value="{{ $month->format('Y-m') }}"
                data-flatpickr="month-combined" data-autosubmit="true" autocomplete="off"
                class="border border-[#eef0f4] rounded-lg pl-9 pr-3 py-2 text-sm bg-white focus:outline-none focus:border-[#044b46]/40 h-[40px] w-[150px]" readonly>
        </div>
    </div>
</div>

{{-- Absensi Harian --}}
<div class="mb-8">
    <h3 class="text-sm font-semibold text-[#14181a] mb-3">
        Absensi — {{ $date->translatedFormat('l, d F Y') }}
    </h3>
    <div class="card overflow-hidden">
      <div class="overflow-x-auto">
        <table class="w-full text-sm text-left">
            <thead class="bg-[#f7f8fc]">
                <tr class="text-[#9aa0a4] text-[11px] uppercase tracking-wide">
                    <th class="px-6 py-3 font-medium whitespace-nowrap">Nama</th>
                    <th class="px-4 py-3 font-medium whitespace-nowrap">Check-in</th>
                    <th class="px-4 py-3 font-medium whitespace-nowrap">Check-out</th>
                    <th class="px-4 py-3 font-medium whitespace-nowrap">Status</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($attendanceRecords as $r)
                    <tr class="border-t border-[#f2f3f6]">
                        <td class="px-6 py-3.5">
                            <div class="flex items-center gap-3">
                                @if ($r['user']->avatar_url)
                                    <img src="{{ $r['user']->avatar_url }}" referrerpolicy="no-referrer" class="w-8 h-8 rounded-full object-cover">
                                @else
                                    <div class="w-8 h-8 rounded-full bg-[#044b46] text-white text-xs font-semibold flex items-center justify-center shrink-0">
                                        {{ strtoupper(substr($r['user']->name, 0, 1)) }}
                                    </div>
                                @endif
                                <p class="font-medium text-[#14181a]">{{ $r['user']->name }}</p>
                            </div>
                        </td>
                        <td class="px-4 py-3.5 text-[#5c6266] whitespace-nowrap">
                            {{ $r['attendance']?->check_in_at?->format('H:i') ?? '-' }}
                        </td>
                        <td class="px-4 py-3.5 text-[#5c6266] whitespace-nowrap">
                            {{ $r['attendance']?->check_out_at?->format('H:i') ?? '-' }}
                        </td>
                        <td class="px-4 py-3.5 whitespace-nowrap">
                            <span class="text-xs font-semibold px-2 py-0.5 rounded-full {{ $badgeClasses[$r['status']] ?? 'bg-[#f2f3f6] text-[#5c6266]' }}">
                                {{ $r['status'] }}
                            </span>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="4" class="px-6 py-6 text-center text-sm text-[#9aa0a4]">Tidak ada personel internal.</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
      </div>
    </div>
</div>

{{-- Ringkasan Bulanan --}}
<div>
    <h3 class="text-sm font-semibold text-[#14181a] mb-3">
        Ringkasan — {{ $month->translatedFormat('F Y') }}
    </h3>
    <div class="card overflow-hidden">
      <div class="overflow-x-auto">
        <table class="w-full text-sm text-left">
            <thead class="bg-[#f7f8fc]">
                <tr class="text-[#9aa0a4] text-[11px] uppercase tracking-wide">
                    <th class="px-6 py-3 font-medium whitespace-nowrap">Nama</th>
                    <th class="px-4 py-3 font-medium whitespace-nowrap">Hari Kerja</th>
                    <th class="px-4 py-3 font-medium whitespace-nowrap">Hadir</th>
                    <th class="px-4 py-3 font-medium whitespace-nowrap">Telat</th>
                    <th class="px-4 py-3 font-medium whitespace-nowrap">Tidak Hadir</th>
                    <th class="px-4 py-3 font-medium whitespace-nowrap">Lupa Check-Out</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($monthlySummary as $s)
                    <tr class="border-t border-[#f2f3f6]">
                        <td class="px-6 py-3.5 font-medium text-[#14181a]">{{ $s['user']->name }}</td>
                        <td class="px-4 py-3.5 text-[#5c6266] whitespace-nowrap">{{ $s['total_workdays'] }}</td>
                        <td class="px-4 py-3.5 text-[#5c6266] whitespace-nowrap">{{ $s['hadir'] }}</td>
                        <td class="px-4 py-3.5 whitespace-nowrap {{ $s['telat'] > 0 ? 'text-[#8a6423] font-semibold' : 'text-[#9aa0a4]' }}">{{ $s['telat'] }}</td>
                        <td class="px-4 py-3.5 whitespace-nowrap {{ $s['tidak_hadir'] > 0 ? 'text-[#b3423e] font-semibold' : 'text-[#9aa0a4]' }}">{{ $s['tidak_hadir'] }}</td>
                        <td class="px-4 py-3.5 whitespace-nowrap {{ $s['lupa_checkout'] > 0 ? 'text-[#8a6423] font-semibold' : 'text-[#9aa0a4]' }}">{{ $s['lupa_checkout'] }}</td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="6" class="px-6 py-6 text-center text-sm text-[#9aa0a4]">Tidak ada personel internal.</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
      </div>
    </div>
</div>
