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

        \Log::info('Client magic link request received', [
            'input_phone' => $validated['phone_number'],
            'normalized_phone' => $normalizedPhone,
            'ip' => $request->ip(),
        ]);

        // Cegah spam ke nomor yang sama: max 1 token baru per 2 menit
        $recentToken = MagicLoginToken::whereHas('user', fn ($q) => $q->where('phone_number', $normalizedPhone))
            ->where('created_at', '>', now()->subMinutes(2))
            ->exists();

        if ($recentToken) {
            \Log::warning('Magic link blocked by rate limiter', [
                'phone' => $normalizedPhone,
            ]);

            return back()->with(
                'status',
                'Link login sudah dikirim baru-baru ini. Silakan cek WhatsApp Anda atau tunggu beberapa menit.'
            );
        }

        $user = User::where('phone_number', $normalizedPhone)
            ->whereNotNull('client_id')
            ->first();

        // Selalu tampilkan pesan sukses generik, jangan bocorkan apakah nomor terdaftar atau tidak
        $genericMessage = 'Jika nomor terdaftar, link login sudah dikirim ke WhatsApp Anda.';

        if (!$user || !in_array($user->status, ['active', 'invited'])) {

            \Log::warning('Magic link request rejected', [
                'phone' => $normalizedPhone,
                'user_found' => $user ? true : false,
                'status' => $user?->status,
            ]);

            return back()->with('status', $genericMessage);
        }

        \Log::info('Client found', [
            'user_id' => $user->id,
            'name' => $user->name,
            'phone' => $user->phone_number,
            'status' => $user->status,
        ]);

        $rawToken = Str::random(8);

        MagicLoginToken::create([
            'user_id' => $user->id,
            'token' => hash('sha256', $rawToken),
            'expires_at' => now()->addMinutes(10),
        ]);

        \Log::info('Magic token created', [
            'user_id' => $user->id,
            'token_preview' => substr($rawToken, 0, 4) . '****',
            'expires_at' => now()->addMinutes(10)->toDateTimeString(),
        ]);

        $link = route('client.magic-login.verify', ['token' => $rawToken]);

        $message = "Halo {$user->name}, klik link berikut untuk masuk ke Dashboard 523 Studio (berlaku 10 menit):\n{$link}";

        \Log::info('Sending WhatsApp via Fonnte', [
            'target' => $user->phone_number,
            'message' => $message,
            'link' => $link,
        ]);

        try {
            $result = $fonnte->sendMessage(
                $user->phone_number,
                $message
            );

            \Log::info('Fonnte sendMessage completed', [
                'result' => $result,
            ]);
        } catch (\Throwable $e) {
            \Log::error('Fonnte sendMessage exception', [
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            throw $e;
        }

        AuthAuditLog::create([
            'user_id' => $user->id,
            'event' => 'magic_link_requested',
            'method' => 'whatsapp',
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
        ]);

        \Log::info('Magic link request completed', [
            'user_id' => $user->id,
        ]);

        return back()->with('status', $genericMessage);
    }

    public function verify(Request $request, string $token)
    {
        \Log::info('Magic link verification started', [
            'token_preview' => substr($token, 0, 4) . '****',
            'ip' => $request->ip(),
        ]);

        $hashedToken = hash('sha256', $token);

        $magicToken = MagicLoginToken::where('token', $hashedToken)->first();

        if (!$magicToken || !$magicToken->isValid()) {

            \Log::warning('Magic link invalid or expired', [
                'token_found' => $magicToken ? true : false,
            ]);

            return redirect()->route('client.login')
                ->withErrors(['token' => 'Link login tidak valid atau sudah kedaluwarsa.']);
        }

        $user = $magicToken->user;

        \Log::info('Magic token valid', [
            'user_id' => $user->id,
            'status' => $user->status,
        ]);

        if (!in_array($user->status, ['active', 'invited'])) {

            \Log::warning('User status rejected during login', [
                'user_id' => $user->id,
                'status' => $user->status,
            ]);

            return redirect()->route('client.login')
                ->withErrors(['token' => 'Akun Anda belum aktif.']);
        }

        $magicToken->update([
            'used_at' => now()
        ]);

        \Log::info('Magic token marked as used', [
            'token_id' => $magicToken->id,
        ]);

        if ($user->status === 'invited') {

            $user->update([
                'status' => 'active'
            ]);

            \Log::info('User activated automatically', [
                'user_id' => $user->id,
            ]);
        }

        Auth::login($user, remember: true);
        $request->session()->regenerate();

        \Log::info('Client login successful', [
            'user_id' => $user->id,
            'session_id' => $request->session()->getId(),
        ]);

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