<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class EnsureInternalUser
{
    public function handle(Request $request, Closure $next)
    {
        $user = $request->user();

        if (!$user || $user->isClientUser()) {
            abort(403, 'Halaman ini hanya untuk tim internal 523 Studio.');
        }

        return $next($request);
    }
}