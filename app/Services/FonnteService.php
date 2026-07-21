<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class FonnteService
{
    public function sendMessage(string $phoneNumber, string $message): bool
    {
        $response = Http::withHeaders([
            'Authorization' => config('services.fonnte.token'),
        ])->post(config('services.fonnte.url'), [
            'target' => $phoneNumber,
            'message' => $message,
        ]);

        if (!$response->successful()) {
            Log::error('Fonnte send failed', ['response' => $response->body()]);
            return false;
        }

        return true;
    }
}