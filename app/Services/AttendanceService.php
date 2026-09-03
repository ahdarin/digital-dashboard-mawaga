<?php

namespace App\Services;

use App\Enums\UserRole;
use App\Models\Attendance;
use App\Models\User;
use Illuminate\Support\Carbon;

/**
 * Check-in/check-out sederhana - jam kerja 11:00-17:00, toleransi 15
 * menit sebelum dianggap telat/lembur. Sengaja tanpa koreksi manual
 * (lihat percakapan perencanaan fitur ini) - kalau lupa check-out,
 * datanya dibiarkan kosong (bukan ditebak) dan ditandai di laporan,
 * biar nggak ada angka durasi kerja yang sebenarnya cuma tebakan.
 */
class AttendanceService
{
    public const START_HOUR = 11;
    public const END_HOUR = 17;
    public const TOLERANCE_MINUTES = 15;

    public function isWorkday(Carbon $date): bool
    {
        return ! $date->isWeekend();
    }

    public function shiftStart(Carbon $date): Carbon
    {
        return $date->copy()->setTime(self::START_HOUR, 0, 0);
    }

    public function shiftEnd(Carbon $date): Carbon
    {
        return $date->copy()->setTime(self::END_HOUR, 0, 0);
    }

    public function today(User $user): ?Attendance
    {
        return Attendance::where('user_id', $user->id)
            ->where('date', Carbon::now()->toDateString())
            ->first();
    }

    public function checkIn(User $user): Attendance
    {
        $now = Carbon::now();

        if (! $this->isWorkday($now)) {
            throw new \RuntimeException('Hari ini bukan hari kerja (akhir pekan).');
        }

        $existing = $this->today($user);
        if ($existing && $existing->check_in_at) {
            throw new \RuntimeException('Kamu sudah check-in hari ini.');
        }

        $status = $now->gt($this->shiftStart($now)->addMinutes(self::TOLERANCE_MINUTES)) ? 'late' : 'on_time';

        if ($existing) {
            $existing->update(['check_in_at' => $now, 'check_in_status' => $status]);
            return $existing->fresh();
        }

        return Attendance::create([
            'user_id' => $user->id,
            'date' => $now->toDateString(),
            'check_in_at' => $now,
            'check_in_status' => $status,
        ]);
    }

    public function checkOut(User $user): Attendance
    {
        $now = Carbon::now();
        $attendance = $this->today($user);

        if (! $attendance || ! $attendance->check_in_at) {
            throw new \RuntimeException('Kamu belum check-in hari ini.');
        }
        if ($attendance->check_out_at) {
            throw new \RuntimeException('Kamu sudah check-out hari ini.');
        }

        $end = $this->shiftEnd($now);
        $status = match (true) {
            $now->lt($end->copy()->subMinutes(self::TOLERANCE_MINUTES)) => 'early',
            $now->gt($end->copy()->addMinutes(self::TOLERANCE_MINUTES)) => 'overtime',
            default => 'normal',
        };

        $attendance->update(['check_out_at' => $now, 'check_out_status' => $status]);

        return $attendance->fresh();
    }

    public function lateMinutes(Attendance $attendance): int
    {
        if (! $attendance->check_in_at || $attendance->check_in_status !== 'late') {
            return 0;
        }

        // diffInMinutes() balikin float bertanda di Carbon 3 - dibulatkan dan
        // dipaksa absolute biar cocok sama tipe int yang dideklarasikan, dan
        // nggak ada lagi E_DEPRECATED senyap tiap check-in yang bawa detik.
        return (int) round(
            $this->shiftStart($attendance->check_in_at)->diffInMinutes($attendance->check_in_at, absolute: true)
        );
    }

    public function formatMinutes(int $minutes): string
    {
        if ($minutes < 60) {
            return "{$minutes} menit";
        }

        $hours = intdiv($minutes, 60);
        $rest = $minutes % 60;

        return $rest > 0 ? "{$hours} jam {$rest} menit" : "{$hours} jam";
    }

    /**
     * Status per hari untuk laporan Kehadiran. "Tidak Hadir" dan "Lupa
     * Check-Out" dihitung saat laporan dibuka (bukan disimpan lewat cron),
     * sesuai keputusan fitur ini.
     */
    public function statusLabel(Carbon $date, ?Attendance $attendance): string
    {
        if (! $this->isWorkday($date)) {
            return 'Libur';
        }

        if ($date->isFuture()) {
            return 'Belum Datang';
        }

        if (! $attendance || ! $attendance->check_in_at) {
            if ($date->isToday() && Carbon::now()->lt($this->shiftEnd($date))) {
                return 'Belum Check-In';
            }

            return 'Tidak Hadir';
        }

        if (! $attendance->check_out_at) {
            if ($date->isToday()) {
                return $attendance->check_in_status === 'late' ? 'Telat (Belum Pulang)' : 'Sudah Check-In';
            }

            return 'Lupa Check-Out';
        }

        return match (true) {
            $attendance->check_in_status === 'late' => 'Telat',
            $attendance->check_out_status === 'early' => 'Pulang Awal',
            $attendance->check_out_status === 'overtime' => 'Lembur',
            default => 'Tepat Waktu',
        };
    }

    /**
     * Admin dikecualikan dari absensi - tidak wajib check-in/check-out
     * dan tidak muncul di laporan Kehadiran.
     */
    private function trackedUsers(): \Illuminate\Support\Collection
    {
        return User::query()
            ->where('status', 'active')
            ->whereDoesntHave('roles', fn ($q) => $q->where('name', UserRole::Admin->value))
            ->orderBy('name')
            ->get();
    }

    public function dailyRecords(Carbon $date): \Illuminate\Support\Collection
    {
        $users = $this->trackedUsers();
        $attendances = Attendance::where('date', $date->toDateString())->get()->keyBy('user_id');

        return $users->map(function ($user) use ($date, $attendances) {
            $attendance = $attendances->get($user->id);

            return [
                'user' => $user,
                'attendance' => $attendance,
                'status' => $this->statusLabel($date, $attendance),
            ];
        });
    }

    public function monthlySummary(Carbon $month): \Illuminate\Support\Collection
    {
        $users = $this->trackedUsers();
        $start = $month->copy()->startOfMonth();
        $end = $month->copy()->endOfMonth();

        $attendances = Attendance::whereBetween('date', [$start->toDateString(), $end->toDateString()])
            ->get()
            ->groupBy('user_id');

        $workdays = collect();
        $cursor = $start->copy();
        $limit = Carbon::now()->lt($end) ? Carbon::now() : $end;
        while ($cursor->lte($limit)) {
            if ($this->isWorkday($cursor)) {
                $workdays->push($cursor->toDateString());
            }
            $cursor->addDay();
        }

        return $users->map(function ($user) use ($workdays, $attendances) {
            $userAttendances = ($attendances->get($user->id) ?? collect())
                ->keyBy(fn ($a) => $a->date->toDateString());

            $hadir = 0;
            $telat = 0;
            $tidakHadir = 0;
            $lupaCheckout = 0;

            foreach ($workdays as $dateString) {
                $attendance = $userAttendances->get($dateString);

                if (! $attendance || ! $attendance->check_in_at) {
                    // Jangan hitung hari ini "tidak hadir" kalau jam pulang
                    // belum lewat - biar konsisten dengan status di tabel
                    // Absensi Harian di halaman yang sama ("Belum Check-In").
                    $isTodayBeforeShiftEnd = $dateString === Carbon::now()->toDateString()
                        && Carbon::now()->lt($this->shiftEnd(Carbon::now()));

                    if (! $isTodayBeforeShiftEnd) {
                        $tidakHadir++;
                    }
                    continue;
                }

                $hadir++;
                if ($attendance->check_in_status === 'late') {
                    $telat++;
                }
                if (! $attendance->check_out_at) {
                    $lupaCheckout++;
                }
            }

            return [
                'user' => $user,
                'total_workdays' => $workdays->count(),
                'hadir' => $hadir,
                'telat' => $telat,
                'tidak_hadir' => $tidakHadir,
                'lupa_checkout' => $lupaCheckout,
            ];
        });
    }
}
