<?php

namespace App\Http\Controllers;

use App\Services\AttendanceService;
use Illuminate\Http\Request;

class AttendanceController extends Controller
{
    public function checkIn(Request $request, AttendanceService $service)
    {
        try {
            $service->checkIn($request->user());
        } catch (\RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('status', 'Check-in berhasil dicatat.');
    }

    public function checkOut(Request $request, AttendanceService $service)
    {
        try {
            $service->checkOut($request->user());
        } catch (\RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('status', 'Check-out berhasil dicatat.');
    }
}
