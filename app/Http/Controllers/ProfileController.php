<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Models\UserMonthlyKpiResult;
use App\Services\PinService;
use App\Services\TeamPerformanceKpiCalculator;
use App\Services\UserWorkSummaryService;
use Illuminate\Support\Carbon;

class ProfileController extends Controller
{
    public function show(
        User $user,
        UserWorkSummaryService $summaryService,
        PinService $pinService,
        TeamPerformanceKpiCalculator $kpiCalculator
    ) {
        // Pin selalu relatif ke user yang lagi login (penonton), bukan ke
        // $user yang profilnya sedang dilihat - lihat catatan di
        // UserWorkSummaryService::copywriterQueue().
        $pinnedIds = $pinService->pinnedContentItemIds(auth()->user());

        // KPI ikut permission Team Performance yang sudah ada (tidak ada
        // permission baru) - kecuali profil milik sendiri, semua orang boleh
        // lihat KPI-nya sendiri.
        $canViewKpi = auth()->id() === $user->id || auth()->user()->hasPermissionTo('team_performance', 'view');
        $kpiPeriod = Carbon::now()->startOfMonth();
        $kpiResult = null;

        if ($canViewKpi) {
            $kpiCalculator->ensureCalculated($kpiPeriod);
            $kpiResult = UserMonthlyKpiResult::where('user_id', $user->id)
                ->where('period_start', $kpiPeriod->toDateString())
                ->first();
        }

        $kpiData = ['canViewKpi' => $canViewKpi, 'kpiPeriod' => $kpiPeriod, 'kpiResult' => $kpiResult];

        if ($summaryService->isCopywriter($user)) {
            $briefQueueItems = $summaryService->copywriterQueue($user, $pinnedIds);

            return view('profile.show-copywriter', array_merge(compact('user', 'briefQueueItems', 'pinnedIds'), $kpiData));
        }

        $data = $summaryService->productionSummary($user, $pinnedIds);

        return view('profile.show', array_merge(['user' => $user, 'pinnedIds' => $pinnedIds], $data, $kpiData));
    }
}
