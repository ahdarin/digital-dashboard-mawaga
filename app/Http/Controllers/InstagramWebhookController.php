<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;

class InstagramWebhookController extends Controller
{
    /**
     * Handshake verifikasi webhook Meta. PHP mengubah titik pada nama query
     * parameter jadi underscore (hub.mode -> hub_mode dst), jadi baca dari
     * bentuk underscore dengan fallback ke bentuk asli (titik) untuk jaga-jaga.
     */
    public function verify(Request $request): Response
    {
        $mode = $request->query('hub_mode', $request->query('hub.mode'));
        $token = $request->query('hub_verify_token', $request->query('hub.verify_token'));
        $challenge = $request->query('hub_challenge', $request->query('hub.challenge'));

        $expectedToken = (string) config('services.instagram.webhook_verify_token');

        if ($mode === 'subscribe' && $expectedToken !== '' && hash_equals($expectedToken, (string) $token)) {
            Log::info('Instagram webhook verification berhasil');

            return response((string) $challenge, 200);
        }

        Log::info('Instagram webhook verification ditolak', ['mode' => $mode]);

        return response('Forbidden', 403);
    }

    /**
     * Terima event webhook. Belum ada business logic - cukup ack 200 supaya
     * Meta tidak retry. JANGAN log payload mentah (bisa berisi data user).
     */
    public function handle(Request $request): Response
    {
        Log::info('Instagram webhook event diterima', [
            'object' => $request->input('object'),
            'entries' => is_array($request->input('entry')) ? count($request->input('entry')) : 0,
        ]);

        return response('', 200);
    }
}
