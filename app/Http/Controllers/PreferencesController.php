<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

class PreferencesController extends Controller
{
    /**
     * Simpan pilihan tema (Terang/Gelap/Ikut Sistem) dari sidebar ke
     * users.preferences - dipanggil via fetch() dari komponen sidebar,
     * terpisah dari penerapan visualnya sendiri (itu sudah kejadian
     * duluan di sisi client, lewat localStorage + atribut data-theme,
     * supaya nggak nunggu response network buat kelihatan efeknya).
     * Nilainya cuma dipakai buat sinkron ke device/browser lain.
     */
    public function updateTheme(Request $request)
    {
        $validated = $request->validate([
            'theme' => 'required|in:light,dark,system',
        ]);

        $user = $request->user();
        $user->update([
            'preferences' => array_merge($user->preferences ?? [], ['theme' => $validated['theme']]),
        ]);

        return response()->json(['success' => true]);
    }
}
