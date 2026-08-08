<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsurePermission
{
    public function handle(Request $request, Closure $next, string $module, string $action): Response
    {
        $user = $request->user();

        abort_unless(
            $user && $user->hasPermissionTo($module, $action),
            403,
            'Anda tidak memiliki akses ke halaman ini.'
        );

        return $next($request);
    }
}
