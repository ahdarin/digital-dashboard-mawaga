<?php

namespace App\Http\Controllers;

use App\Models\ApiIntegration;
use App\Models\Platform;

/**
 * NOTE UNTUK TIM:
 * Tidak ada desain UI resmi untuk halaman ini, jadi disusun mengikuti pola
 * visual halaman lain (card putih rounded-2xl, warna aksen #044b46).
 *
 * Isinya digabung dari dua hal yang masuk akal ada di Settings:
 * 1. Account - data akun user yang login (read-only, edit lewat halaman Profile).
 * 2. Analytics Integration Settings - sesuai PRD 7.3.4 (domain PIC 3):
 *    status koneksi API per platform + jalur import performa via CSV/Excel.
 *
 * Bagian import CSV & connect/disconnect integration MASIH UI SAJA (belum ada
 * action/route submit-nya) - form action & handler menyusul saat backend-nya
 * digarap.
 */
class SettingsController extends Controller
{
    public function index()
    {
        $user = auth()->user();

        $platforms = Platform::all();

        $integrations = $platforms->map(function ($platform) {
            $integration = ApiIntegration::where('platform_id', $platform->id)
                ->where('status', 'active')
                ->latest()
                ->first();

            return [
                'platform' => $platform->name,
                'connected' => (bool) $integration,
                'integration_name' => $integration->integration_name ?? null,
                'updated_at' => $integration->updated_at ?? null,
            ];
        });

        return view('settings.index', compact('user', 'integrations'));
    }
}