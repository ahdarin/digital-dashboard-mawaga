<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\PinService;
use App\Services\UserWorkSummaryService;

class ProfileController extends Controller
{
    public function show(User $user, UserWorkSummaryService $summaryService, PinService $pinService)
    {
        // Pin selalu relatif ke user yang lagi login (penonton), bukan ke
        // $user yang profilnya sedang dilihat - lihat catatan di
        // UserWorkSummaryService::copywriterQueue().
        $pinnedIds = $pinService->pinnedContentItemIds(auth()->user());

        if ($summaryService->isCopywriter($user)) {
            $briefQueueItems = $summaryService->copywriterQueue($user, $pinnedIds);

            return view('profile.show-copywriter', compact('user', 'briefQueueItems', 'pinnedIds'));
        }

        $data = $summaryService->productionSummary($user, $pinnedIds);

        return view('profile.show', array_merge(['user' => $user, 'pinnedIds' => $pinnedIds], $data));
    }
}
