<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

/**
 * users SEKARANG cuma internal staff (Client bukan User sama sekali - lihat
 * Client::portal_token & ResolveClientPortal). Middleware ini jadi guard
 * defensif "harus user internal yang login" - redundan dengan 'auth' di
 * hampir semua kasus, tapi dipertahankan sebagai lapisan eksplisit (nama
 * middleware-nya sendiri jadi dokumentasi niat route ini).
 */
class EnsureInternalUser
{
    public function handle(Request $request, Closure $next)
    {
        if (! $request->user()) {
            abort(403, 'Halaman ini hanya untuk tim internal 523 Studio.');
        }

        return $next($request);
    }
}
