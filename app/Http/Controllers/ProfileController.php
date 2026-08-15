<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\UserWorkSummaryService;

class ProfileController extends Controller
{
    public function show(User $user, UserWorkSummaryService $summaryService)
    {
        if ($summaryService->isCopywriter($user)) {
            $briefQueueItems = $summaryService->copywriterQueue($user);

            return view('profile.show-copywriter', compact('user', 'briefQueueItems'));
        }

        $data = $summaryService->productionSummary($user);

        return view('profile.show', array_merge(['user' => $user], $data));
    }
}
