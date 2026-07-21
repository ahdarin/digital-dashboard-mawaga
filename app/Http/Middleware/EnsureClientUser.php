<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class EnsureClientUser
{
    public function handle(Request $request, Closure $next)
    {
        $user = $request->user();

        if (!$user || !$user->isClientUser()) {
            abort(403, 'Halaman ini hanya untuk klien 523 Studio.');
        }

        return $next($request);
    }
}