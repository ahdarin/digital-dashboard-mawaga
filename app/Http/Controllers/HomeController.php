<?php

namespace App\Http\Controllers;

use App\Services\NextStepsService;
use App\Services\UserWorkSummaryService;
use Illuminate\Http\Request;

/**
 * Beranda - landing page semua user internal setelah login (lihat rute
 * "/"). Ringkasan pekerjaan diri sendiri + panel Langkah Berikutnya,
 * beda dari halaman Profil (ProfileController) yang dipakai buat lihat
 * ringkasan siapa pun lewat Team Performance / User Management.
 */
class HomeController extends Controller
{
    public function index(Request $request, UserWorkSummaryService $summaryService, NextStepsService $nextStepsService)
    {
        $user = $request->user();
        $nextSteps = $nextStepsService->forUser($user);

        if ($summaryService->isCopywriter($user)) {
            $briefQueueItems = $summaryService->copywriterQueue($user);

            return view('home.index-copywriter', compact('user', 'briefQueueItems', 'nextSteps'));
        }

        $data = $summaryService->productionSummary($user);

        return view('home.index', array_merge(['user' => $user, 'nextSteps' => $nextSteps], $data));
    }
}
