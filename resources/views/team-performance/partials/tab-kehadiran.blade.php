@php
    // Warna badge status dipetakan ke 5 token badge yang sudah ada di
    // seluruh sistem (badge-success/warning/danger/info/neutral), plus
    // titik warna kecil di depan label - biar konsisten dengan badge di
    // halaman lain, bukan palet baru yang cuma dipakai di sini.
    $statusMeta = [
        'Tepat Waktu'          => ['class' => 'badge-success', 'dot' => '#0f7a5f'],
        'Lembur'                => ['class' => 'badge-info',    'dot' => '#3452a8'],
        'Sudah Check-In'        => ['class' => 'badge-info',    'dot' => '#3452a8'],
        'Telat'                 => ['class' => 'badge-warning', 'dot' => '#8a6423'],
        'Telat (Belum Pulang)'  => ['class' => 'badge-warning', 'dot' => '#8a6423'],
        'Pulang Awal'           => ['class' => 'badge-warning', 'dot' => '#8a6423'],
        'Lupa Check-Out'        => ['class' => 'badge-warning', 'dot' => '#8a6423'],
        'Tidak Hadir'           => ['class' => 'badge-danger',  'dot' => '#b3423e'],
        'Belum Check-In'        => ['class' => 'badge-neutral', 'dot' => '#5c6266'],
        'Belum Datang'          => ['class' => 'badge-neutral', 'dot' => '#5c6266'],
        'Libur'                 => ['class' => 'badge-neutral', 'dot' => '#5c6266'],
    ];

    // Ringkasan hari ini dihitung dari $attendanceRecords yang sudah ada -
    // tidak perlu query tambahan, cuma agregasi status yang sama dipakai
    // tabel di bawahnya.
    $totalTim = $attendanceRecords->count();
    $hadirCount = $attendanceRecords->filter(fn ($r) => $r['attendance']?->check_in_at)->count();
    $telatCount = $attendanceRecords->filter(fn ($r) => str_starts_with($r['status'], 'Telat'))->count();
    $absenCount = $attendanceRecords->filter(fn ($r) => in_array($r['status'], ['Belum Check-In', 'Tidak Hadir']))->count();
    $absenLabel = $date->isToday() ? 'Belum Absen' : 'Tidak Hadir';
@endphp

<form id="filter-form" method="GET" action="{{ route('team-performance.index') }}">
    <input type="hidden" name="tab" value="kehadiran">
</form>

{{-- Kehadiran Hari Ini --}}
<div class="mb-8">
    <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-3 mb-4">
        <div>
            <h2 class="font-display text-lg font-semibold text-[#14181a]">Kehadiran Hari Ini</h2>
            <p class="text-xs text-[#767c80] mt-0.5">{{ $date->translatedFormat('l, d F Y') }}</p>
        </div>
        <div class="flex items-center gap-2">
            <a href="{{ route('team-performance.index', array_merge(request()->except('page'), ['tab' => 'kehadiran', 'date' => $date->copy()->subDay()->toDateString()])) }}"
               title="Hari sebelumnya"
               class="w-9 h-9 shrink-0 flex items-center justify-center rounded-lg border border-[#eef0f4] text-[#5c6266] hover:bg-[#f7f8fc] transition-colors">
                <span class="material-symbols-outlined text-[18px]">chevron_left</span>
            </a>
            <div class="relative">
                <span class="material-symbols-outlined absolute left-3 top-1/2 -translate-y-1/2 text-[#767c80] text-[17px] pointer-events-none">calendar_month</span>
                <input id="date" type="text" name="date" form="filter-form" value="{{ $date->toDateString() }}"
                    data-flatpickr="date" data-autosubmit="true" autocomplete="off"
                    class="border border-[#eef0f4] rounded-lg pl-9 pr-3 py-2 text-sm bg-white focus:outline-none focus:border-[#044b46]/40 h-[40px] w-[150px]" readonly>
            </div>
            <a href="{{ route('team-performance.index', array_merge(request()->except('page'), ['tab' => 'kehadiran', 'date' => $date->copy()->addDay()->toDateString()])) }}"
               title="Hari berikutnya"
               class="w-9 h-9 shrink-0 flex items-center justify-center rounded-lg border border-[#eef0f4] text-[#5c6266] hover:bg-[#f7f8fc] transition-colors">
                <span class="material-symbols-outlined text-[18px]">chevron_right</span>
            </a>
        </div>
    </div>

    {{-- Kartu ringkasan --}}
    <div class="grid grid-cols-2 sm:grid-cols-4 gap-3 sm:gap-4 mb-5">
        <div class="card p-3.5 sm:p-5">
            <div class="flex items-center gap-3 mb-3">
                <div class="w-9 h-9 rounded-lg bg-[#f2f3f6] flex items-center justify-center">
                    <span class="material-symbols-outlined text-[#5c6266] text-[18px]">group</span>
                </div>
                <span class="text-sm text-[#5c6266]">Total Tim</span>
            </div>
            <p class="font-display text-[24px] sm:text-[28px] font-semibold text-[#14181a]">{{ $totalTim }}</p>
        </div>
        <div class="card p-3.5 sm:p-5">
            <div class="flex items-center gap-3 mb-3">
                <div class="w-9 h-9 rounded-lg bg-[#f0f5f4] flex items-center justify-center">
                    <span class="material-symbols-outlined text-[#0f7a5f] text-[18px]">check_circle</span>
                </div>
                <span class="text-sm text-[#5c6266]">Hadir</span>
            </div>
            <p class="font-display text-[24px] sm:text-[28px] font-semibold text-[#14181a]">{{ $hadirCount }}</p>
        </div>
        <div class="card p-3.5 sm:p-5">
            <div class="flex items-center gap-3 mb-3">
                <div class="w-9 h-9 rounded-lg bg-[#fdf6ec] flex items-center justify-center">
                    <span class="material-symbols-outlined text-[#8a6423] text-[18px]">schedule</span>
                </div>
                <span class="text-sm text-[#5c6266]">Telat</span>
            </div>
            <p class="font-display text-[24px] sm:text-[28px] font-semibold text-[#14181a]">{{ $telatCount }}</p>
        </div>
        <div class="card p-3.5 sm:p-5">
            <div class="flex items-center gap-3 mb-3">
                <div class="w-9 h-9 rounded-lg bg-[#fdf2f1] flex items-center justify-center">
                    <span class="material-symbols-outlined text-[#b3423e] text-[18px]">pending_actions</span>
                </div>
                <span class="text-sm text-[#5c6266]">{{ $absenLabel }}</span>
            </div>
            <p class="font-display text-[24px] sm:text-[28px] font-semibold text-[#14181a]">{{ $absenCount }}</p>
        </div>
    </div>

    <div class="card overflow-hidden hidden sm:block">
      <div class="overflow-x-auto">
        <table class="w-full text-sm text-left">
            <thead class="bg-[#f7f8fc]">
                <tr class="text-[#767c80] text-[11px] uppercase tracking-wide">
                    <th class="px-6 py-3 font-medium whitespace-nowrap">Nama</th>
                    <th class="px-4 py-3 font-medium whitespace-nowrap">Check-in</th>
                    <th class="px-4 py-3 font-medium whitespace-nowrap">Check-out</th>
                    <th class="px-4 py-3 font-medium whitespace-nowrap">Status</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($attendanceRecords as $r)
                    @php $meta = $statusMeta[$r['status']] ?? ['class' => 'badge-neutral', 'dot' => '#5c6266']; @endphp
                    <tr class="border-t border-[#f2f3f6] hover:bg-[#f7f8fc] transition-colors">
                        <td class="px-6 py-3.5">
                            <div class="flex items-center gap-3">
                                @if ($r['user']->avatar_url)
                                    <img src="{{ $r['user']->avatar_url }}" alt="" referrerpolicy="no-referrer" class="w-8 h-8 rounded-full object-cover">
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
                            <span class="badge {{ $meta['class'] }} inline-flex items-center gap-1.5">
                                <span class="w-1.5 h-1.5 rounded-full" style="background-color: {{ $meta['dot'] }}"></span>
                                {{ $r['status'] }}
                            </span>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="4" class="px-6 py-6 text-center text-sm text-[#767c80]">Tidak ada personel internal.</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
      </div>
    </div>

    {{-- Mobile list - flat, jam check-in/check-out langsung kelihatan di
         kanan tanpa perlu expand (beda dari pola accordion tabel lain -
         datanya cuma 2 angka + status, kependekan buat disembunyikan). --}}
    <div class="sm:hidden space-y-2">
        @forelse ($attendanceRecords as $r)
            @php $meta = $statusMeta[$r['status']] ?? ['class' => 'badge-neutral', 'dot' => '#5c6266']; @endphp
            <div class="card p-3 flex items-center justify-between gap-3">
                <div class="flex items-center gap-2.5 min-w-0">
                    @if ($r['user']->avatar_url)
                        <img src="{{ $r['user']->avatar_url }}" alt="" referrerpolicy="no-referrer" class="w-8 h-8 rounded-full object-cover shrink-0">
                    @else
                        <div class="w-8 h-8 rounded-full bg-[#044b46] text-white text-xs font-semibold flex items-center justify-center shrink-0">
                            {{ strtoupper(substr($r['user']->name, 0, 1)) }}
                        </div>
                    @endif
                    <div class="min-w-0">
                        <p class="font-medium text-[#14181a] truncate text-sm">{{ $r['user']->name }}</p>
                        <span class="badge {{ $meta['class'] }} inline-flex items-center gap-1.5 mt-1">
                            <span class="w-1.5 h-1.5 rounded-full" style="background-color: {{ $meta['dot'] }}"></span>
                            {{ $r['status'] }}
                        </span>
                    </div>
                </div>
                <div class="text-right shrink-0">
                    <p class="text-sm text-[#14181a] font-medium whitespace-nowrap">{{ $r['attendance']?->check_in_at?->format('H:i') ?? '-' }}</p>
                    <p class="text-sm text-[#767c80] whitespace-nowrap">{{ $r['attendance']?->check_out_at?->format('H:i') ?? '-' }}</p>
                </div>
            </div>
        @empty
            <div class="card p-6 text-center text-sm text-[#767c80]">Tidak ada personel internal.</div>
        @endforelse
    </div>
</div>

{{-- Rekap Kehadiran Bulanan --}}
<div>
    <div class="flex flex-col lg:flex-row lg:items-center justify-between gap-3 mb-4">
        <h2 class="font-display text-lg font-semibold text-[#14181a]">Rekap Kehadiran Bulanan</h2>

        {{-- Selalu satu baris (termasuk mobile) - search, bulan, dan "Hari
             Kerja" (sama buat semua orang di bulan ini, jadi cukup satu
             angka di sini, bukan kolom berulang di tabel). --}}
        <div class="flex items-center gap-2">
            <div class="relative shrink-0">
                <span class="material-symbols-outlined absolute left-3 top-1/2 -translate-y-1/2 text-[#767c80] text-[17px] pointer-events-none">calendar_month</span>
                <input id="month" type="text" name="month" form="filter-form" value="{{ $month->format('Y-m') }}"
                    data-flatpickr="month-combined" data-autosubmit="true" autocomplete="off"
                    class="border border-[#eef0f4] rounded-lg pl-9 pr-3 py-2 text-sm bg-white focus:outline-none focus:border-[#044b46]/40 h-[40px] w-[110px] lg:w-[150px]" readonly>
            </div>
            <div class="text-right shrink-0 pl-1">
                <p class="text-[9px] font-medium text-[#767c80] uppercase leading-none whitespace-nowrap">Hari Kerja</p>
                <p class="font-display text-lg font-semibold text-[#14181a] leading-none mt-1">{{ $totalWorkdays }}</p>
            </div>
        </div>
    </div>

    <div class="card overflow-hidden hidden sm:block">
      <div class="overflow-x-auto">
        <table class="w-full text-sm text-left">
            <thead class="bg-[#f7f8fc]">
                <tr class="text-[#767c80] text-[11px] uppercase tracking-wide">
                    <th class="px-6 py-3 font-medium whitespace-nowrap">Nama</th>
                    <th class="px-4 py-3 font-medium text-center whitespace-nowrap">Hadir</th>
                    <th class="px-4 py-3 font-medium text-center whitespace-nowrap">Telat</th>
                    <th class="px-4 py-3 font-medium text-center whitespace-nowrap">Tidak Hadir</th>
                    <th class="px-4 py-3 font-medium text-center whitespace-nowrap">Lupa Check-Out</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($monthlySummary as $s)
                    <tr class="border-t border-[#f2f3f6] hover:bg-[#f7f8fc] transition-colors">
                        <td class="px-6 py-3.5 font-medium text-[#14181a] whitespace-nowrap">{{ $s['user']->name }}</td>
                        <td class="px-4 py-3.5 text-center font-medium text-[#0f7a5f]">{{ $s['hadir'] }}</td>
                        <td class="px-4 py-3.5 text-center {{ $s['telat'] > 0 ? 'text-[#8a6423] font-semibold' : 'text-[#767c80]' }}">{{ $s['telat'] }}</td>
                        <td class="px-4 py-3.5 text-center {{ $s['tidak_hadir'] > 0 ? 'text-[#b3423e] font-semibold' : 'text-[#767c80]' }}">{{ $s['tidak_hadir'] }}</td>
                        <td class="px-4 py-3.5 text-center {{ $s['lupa_checkout'] > 0 ? 'text-[#8a6423] font-semibold' : 'text-[#767c80]' }}">{{ $s['lupa_checkout'] }}</td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="5" class="px-6 py-6 text-center text-sm text-[#767c80]">
                            {{ $search ? 'Tidak ada nama yang cocok dengan pencarian.' : 'Tidak ada personel internal.' }}
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
      </div>
      <div class="px-6 py-4 border-t border-[#f2f3f6]">
          {{ $monthlySummary->links() }}
      </div>
    </div>

    {{-- Mobile list - flat, 4 angka rekap langsung kelihatan di bawah nama
         tanpa perlu expand. --}}
    <div class="sm:hidden space-y-2">
        @forelse ($monthlySummary as $s)
            <div class="card p-3">
                <p class="font-medium text-[#14181a] truncate text-sm">{{ $s['user']->name }}</p>
                <div class="flex items-center gap-3 mt-1.5 text-xs flex-wrap">
                    <span class="text-[#767c80]">Hadir <span class="text-[#0f7a5f] font-semibold">{{ $s['hadir'] }}</span></span>
                    <span class="text-[#767c80]">Telat <span class="{{ $s['telat'] > 0 ? 'text-[#8a6423] font-semibold' : 'text-[#5c6266] font-medium' }}">{{ $s['telat'] }}</span></span>
                    <span class="text-[#767c80]">Tidak Hadir <span class="{{ $s['tidak_hadir'] > 0 ? 'text-[#b3423e] font-semibold' : 'text-[#5c6266] font-medium' }}">{{ $s['tidak_hadir'] }}</span></span>
                    <span class="text-[#767c80]">Lupa CO <span class="{{ $s['lupa_checkout'] > 0 ? 'text-[#8a6423] font-semibold' : 'text-[#5c6266] font-medium' }}">{{ $s['lupa_checkout'] }}</span></span>
                </div>
            </div>
        @empty
            <div class="card p-6 text-center text-sm text-[#767c80]">
                {{ $search ? 'Tidak ada nama yang cocok dengan pencarian.' : 'Tidak ada personel internal.' }}
            </div>
        @endforelse

        <div>
            {{ $monthlySummary->links() }}
        </div>
    </div>

    {{-- Legend --}}
    <div class="flex flex-wrap items-center gap-x-5 gap-y-2 mt-5 px-1 text-xs text-[#767c80]">
        <div class="flex items-center gap-1.5">
            <span class="w-2 h-2 rounded-full bg-[#0f7a5f]"></span>
            <span><strong class="text-[#5c6266]">Hadir:</strong> ada data check-in tepat waktu.</span>
        </div>
        <div class="flex items-center gap-1.5">
            <span class="w-2 h-2 rounded-full bg-[#8a6423]"></span>
            <span><strong class="text-[#5c6266]">Telat/Lupa Check-Out:</strong> ada catatan waktu yang perlu diperhatikan.</span>
        </div>
        <div class="flex items-center gap-1.5">
            <span class="w-2 h-2 rounded-full bg-[#b3423e]"></span>
            <span><strong class="text-[#5c6266]">Tidak Hadir:</strong> tidak ada data check-in di hari kerja.</span>
        </div>
        <div class="flex items-center gap-1.5">
            <span class="w-2 h-2 rounded-full bg-[#5c6266]"></span>
            <span><strong class="text-[#5c6266]">Belum Check-In:</strong> hari masih berjalan, belum ada aksi.</span>
        </div>
    </div>
</div>
