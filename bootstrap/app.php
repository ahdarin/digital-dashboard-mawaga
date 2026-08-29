<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'internal' => \App\Http\Middleware\EnsureInternalUser::class,
            'permission' => \App\Http\Middleware\EnsurePermission::class,
            'client.scope' => \App\Http\Middleware\EnsureClientScope::class,
            'client.portal' => \App\Http\Middleware\ResolveClientPortal::class,
        ]);

        // Railway (dan platform container serupa) menaruh TLS termination di
        // depan container - request yang sampai ke php-fpm selalu terlihat
        // http:// dari sudut pandang Laravel kalau ini tidak diset. Tanpa
        // trustProxies, url()/asset()/Socialite redirect URI semua ikut
        // ter-generate http://, browser memblokirnya sebagai mixed content
        // begitu APP_URL sudah https://. '*' aman di sini karena container
        // cuma menerima traffic dari load balancer platform, bukan
        // langsung dari internet.
        $middleware->trustProxies(at: '*');
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*'),
        );
    })->create();
