<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\AuthAuditLog;
use App\Models\MagicLoginToken;
use App\Models\User;
use App\Services\FonnteService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use App\Services\PhoneNumberNormalizer;

class ClientMagicLinkController extends Controller
{
    public function showForm()
    {
        return view('auth.client-login');
    }

    public function requestLink(Request $request, FonnteService $fonnte)
    {
        $validated = $request->validate([
            'phone_number' => 'required|string',
        ]);

        $normalizedPhone = PhoneNumberNormalizer::normalize($validated['phone_number']);

        // Cegah spam ke nomor yang sama: max 1 token baru per 2 menit
        $recentToken = MagicLoginToken::whereHas('user', fn ($q) => $q->where('phone_number', $normalizedPhone))
            ->where('created_at', '>', now()->subMinutes(2))
            ->exists();

        if ($recentToken) {
            return back()->with('status', 'Link login sudah dikirim baru-baru ini. Silakan cek WhatsApp Anda atau tunggu beberapa menit.');
        }

        $user = User::where('phone_number', $normalizedPhone)
            ->whereNotNull('client_id')
            ->first();

        // Selalu tampilkan pesan sukses generik, jangan bocorkan apakah nomor terdaftar atau tidak
        $genericMessage = 'Jika nomor terdaftar, link login sudah dikirim ke WhatsApp Anda.';

        if (!$user || !in_array($user->status, ['active', 'invited'])) {
            return back()->with('status', $genericMessage);
        }

        $rawToken = Str::random(8);

        MagicLoginToken::create([
            'user_id' => $user->id,
            'token' => hash('sha256', $rawToken),
            'expires_at' => now()->addMinutes(10),
        ]);

        $link = route('client.magic-login.verify', ['token' => $rawToken]);

        $fonnte->sendMessage(
            $user->phone_number,
            "Halo {$user->name}, klik link berikut untuk masuk ke Dashboard 523 Studio (berlaku 10 menit):\n{$link}"
        );

        AuthAuditLog::create([
            'user_id' => $user->id,
            'event' => 'magic_link_requested',
            'method' => 'whatsapp',
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
        ]);

        return back()->with('status', $genericMessage);
    }

    public function verify(Request $request, string $token)
    {
        $hashedToken = hash('sha256', $token);
        $magicToken = MagicLoginToken::where('token', $hashedToken)->first();

        if (!$magicToken || !$magicToken->isValid()) {
            return redirect()->route('client.login')
                ->withErrors(['token' => 'Link login tidak valid atau sudah kedaluwarsa.']);
        }

        $user = $magicToken->user;

        if (!in_array($user->status, ['active', 'invited'])) {
            return redirect()->route('client.login')
                ->withErrors(['token' => 'Akun Anda belum aktif.']);
        }

        $magicToken->update(['used_at' => now()]);

        if ($user->status === 'invited') {
            $user->update(['status' => 'active']);
        }

        Auth::login($user, remember: true);
        $request->session()->regenerate();

        AuthAuditLog::create([
            'user_id' => $user->id,
            'event' => 'login_success',
            'method' => 'whatsapp',
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
        ]);

        return redirect()->intended(route('client.dashboard'));
    }
}