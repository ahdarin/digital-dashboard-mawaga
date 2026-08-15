<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\AuthAuditLog;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Laravel\Socialite\Facades\Socialite;

class GoogleAuthController extends Controller
{
    public function redirect()
    {
        return Socialite::driver('google')->redirect();
    }

    public function callback(Request $request)
    {
        $googleUser = Socialite::driver('google')->user();

        $user = User::where('email', $googleUser->getEmail())->first();

        if (!$user) {
            $this->logAttempt(null, 'login_failed', $request);
            return redirect()->route('login')
                ->withErrors(['email' => 'Akun tidak ditemukan. Hubungi Admin untuk didaftarkan.']);
        }

        if (!in_array($user->status, ['active', 'invited'])) {
            $this->logAttempt($user, 'login_failed', $request);
            return redirect()->route('login')
                ->withErrors(['email' => 'Akun Anda belum aktif. Status: ' . $user->status]);
        }

        $updates = [];
        if (!$user->google_id) {
            $updates['google_id'] = $googleUser->getId();
        }
        if ($googleUser->getAvatar()) {
            $updates['avatar_url'] = $googleUser->getAvatar();
        }
        if ($user->status === 'invited') {
            $updates['status'] = 'active';
        }
        if (!empty($updates)) {
            $user->update($updates);
        }

        Auth::login($user, remember: true);
        $request->session()->regenerate();

        $this->logAttempt($user, 'login_success', $request);

        // Sama seperti logika di rute '/': arahkan ke Dashboard kalau punya izin,
        // kalau tidak ke Production Workflow - biar role tanpa akses dashboard
        // (Content Creator, Graphic Designer, Copywriter, SMO) nggak mentok 403.
        $destination = $user->hasPermissionTo('dashboard', 'view') ? 'dashboard' : 'production-workflow.index';

        return redirect()->intended(route($destination));
    }

    private function logAttempt(?User $user, string $event, Request $request): void
    {
        AuthAuditLog::create([
            'user_id' => $user?->id,
            'event' => $event,
            'method' => 'google',
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
        ]);
    }
}