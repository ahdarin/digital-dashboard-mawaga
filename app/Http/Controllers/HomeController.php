<?php

namespace App\Http\Controllers;

use App\Services\AttendanceService;
use App\Services\NextStepsService;
use App\Services\UserWorkSummaryService;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * Beranda - landing page semua user internal setelah login (lihat rute
 * "/"). Ringkasan pekerjaan diri sendiri + panel Langkah Berikutnya,
 * beda dari halaman Profil (ProfileController) yang dipakai buat lihat
 * ringkasan siapa pun lewat Team Performance / User Management.
 */
class HomeController extends Controller
{
    public function index(
        Request $request,
        UserWorkSummaryService $summaryService,
        NextStepsService $nextStepsService,
        AttendanceService $attendanceService
    ) {
        $user = $request->user();
        $nextSteps = $nextStepsService->forUser($user);

        $now = Carbon::now();
        $attendanceProps = [
            'isWorkday' => $attendanceService->isWorkday($now),
            'attendance' => $attendance = $attendanceService->today($user),
            'lateMinutes' => $attendance ? $attendanceService->lateMinutes($attendance) : 0,
        ];

        if ($summaryService->isCopywriter($user)) {
            $briefQueueItems = $summaryService->copywriterQueue($user);

            return view('home.index-copywriter', array_merge(
                compact('user', 'briefQueueItems', 'nextSteps'),
                $attendanceProps
            ));
        }

        $data = $summaryService->productionSummary($user);

        return view('home.index', array_merge(
            ['user' => $user, 'nextSteps' => $nextSteps],
            $data,
            $attendanceProps
        ));
    }
}
