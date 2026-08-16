{{-- ARCH-03: data absensi hari ini (isWorkday/attendance/lateMinutes)
     di-fetch di HomeController dan dikirim ke sini lewat prop, bukan
     query langsung dari view - biar controller yang pegang kendali data
     yang dirender, dan komponen ini aman dipakai di dalam loop tanpa
     jadi N+1 senyap. --}}
@props(['isWorkday' => true, 'attendance' => null, 'lateMinutes' => 0])
@php
    $attendanceService = app(\App\Services\AttendanceService::class);
@endphp
<div class="shrink-0 text-right">
    @if (! $isWorkday)
        <div class="inline-flex items-center gap-2 bg-[#f2f3f6] text-[#5c6266] text-sm font-medium px-4 py-2.5 rounded-lg">
            <span class="material-symbols-outlined text-[18px]">weekend</span> Hari ini libur (akhir pekan)
        </div>
    @elseif (! $attendance || ! $attendance->check_in_at)
        <form action="{{ route('attendance.check-in') }}" method="POST">
            @csrf
            <button type="submit" class="btn-primary">
                <span class="material-symbols-outlined text-[18px]">login</span> Check In
            </button>
        </form>
    @elseif (! $attendance->check_out_at)
        <form action="{{ route('attendance.check-out') }}" method="POST">
            @csrf
            <button type="submit" class="bg-[#b3423e] text-white text-sm font-semibold px-5 py-2.5 rounded-lg hover:bg-[#96352f] transition-colors flex items-center gap-2">
                <span class="material-symbols-outlined text-[18px]">logout</span> Check Out
            </button>
        </form>
        <p class="text-xs text-[#5c6266] mt-1.5">Check-in {{ $attendance->check_in_at->format('H:i') }}</p>
        @if ($lateMinutes > 0)
            <p class="text-xs text-[#b3423e] font-medium mt-0.5">Anda telat {{ $attendanceService->formatMinutes($lateMinutes) }}</p>
        @endif
    @else
        <div class="inline-flex items-center gap-2 bg-[#f0f5f4] text-[#0f7a5f] text-sm font-semibold px-4 py-2.5 rounded-lg">
            <span class="material-symbols-outlined text-[18px]">check_circle</span>
            {{ $attendance->check_in_at->format('H:i') }} - {{ $attendance->check_out_at->format('H:i') }}
        </div>
        <p class="text-xs text-[#767c80] mt-1.5">Absensi hari ini selesai</p>
        @if ($lateMinutes > 0)
            <p class="text-xs text-[#b3423e] font-medium mt-0.5">Anda telat {{ $attendanceService->formatMinutes($lateMinutes) }}</p>
        @endif
    @endif
</div>
