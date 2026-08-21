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
        <div class="inline-flex items-center gap-2 bg-[var(--surface-muted)] text-[var(--text-secondary)] text-sm font-medium px-4 py-2.5 rounded-lg">
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
        <div x-data="{ confirmCheckout: false }">
            <button type="button" @click="confirmCheckout = true" class="bg-[var(--danger-solid)] text-white text-sm font-semibold px-5 py-2.5 rounded-lg hover:bg-[var(--danger-dark)] transition-colors flex items-center gap-2">
                <span class="material-symbols-outlined text-[18px]">logout</span> Check Out
            </button>

            <template x-teleport="body">
                <div x-show="confirmCheckout" x-cloak x-transition
                     x-on:keydown.escape.window="confirmCheckout = false"
                     class="fixed inset-0 z-50 flex items-center justify-center p-4" style="display: none;">
                    <div class="absolute inset-0 bg-[#14181a]/40" @click="confirmCheckout = false"></div>

                    <div x-show="confirmCheckout" x-transition
                         role="dialog" aria-modal="true" aria-labelledby="confirm-checkout-title" x-trap="confirmCheckout"
                         class="relative bg-[var(--surface-card)] rounded-2xl shadow-xl w-full max-w-md max-h-[90vh] overflow-y-auto text-left">
                        <div class="flex items-center justify-between px-6 py-5 border-b border-[var(--border)]">
                            <h3 id="confirm-checkout-title" class="font-display text-lg font-semibold text-[var(--text-primary)]">Konfirmasi Check Out</h3>
                            <button type="button" @click="confirmCheckout = false" class="text-[var(--text-muted)] hover:text-[var(--text-secondary)]">
                                <span class="material-symbols-outlined text-[19px]">close</span>
                            </button>
                        </div>

                        <div class="px-6 py-5">
                            <p class="text-sm text-[var(--text-secondary)]">Yakin ingin check out sekarang? Anda check-in tadi jam <strong class="text-[var(--text-primary)]">{{ $attendance->check_in_at->format('H:i') }}</strong> dan absensi hari ini akan ditutup.</p>
                        </div>

                        <form action="{{ route('attendance.check-out') }}" method="POST">
                            @csrf
                            <div class="flex items-center gap-3 px-6 py-4 border-t border-[var(--border)]">
                                <button type="submit" class="bg-[var(--danger-solid)] text-white text-sm font-semibold px-5 py-2.5 rounded-lg hover:bg-[var(--danger-dark)] transition-colors flex items-center gap-2">
                                    <span class="material-symbols-outlined text-[18px]">logout</span> Ya, Check Out
                                </button>
                                <button type="button" @click="confirmCheckout = false" class="btn-secondary">
                                    Batal
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            </template>
        </div>
        <p class="text-xs text-[var(--text-secondary)] mt-1.5">Check-in {{ $attendance->check_in_at->format('H:i') }}</p>
        @if ($lateMinutes > 0)
            <p class="text-xs text-[var(--danger-text)] font-medium mt-0.5">Anda telat {{ $attendanceService->formatMinutes($lateMinutes) }}</p>
        @endif
    @else
        <div class="inline-flex items-center gap-2 bg-[var(--success-tint)] text-[var(--success-text)] text-sm font-semibold px-4 py-2.5 rounded-lg">
            <span class="material-symbols-outlined text-[18px]">check_circle</span>
            {{ $attendance->check_in_at->format('H:i') }} - {{ $attendance->check_out_at->format('H:i') }}
        </div>
        <p class="text-xs text-[var(--text-muted)] mt-1.5">Absensi hari ini selesai</p>
        @if ($lateMinutes > 0)
            <p class="text-xs text-[var(--danger-text)] font-medium mt-0.5">Anda telat {{ $attendanceService->formatMinutes($lateMinutes) }}</p>
        @endif
    @endif
</div>
