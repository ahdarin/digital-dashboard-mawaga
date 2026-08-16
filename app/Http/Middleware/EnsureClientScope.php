<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Menutup celah IDOR lintas client - route yang route-model-binding-nya
 * cuma dijaga permission:module,action (bukan kepemilikan client) rawan
 * diakses/diubah user yang benar punya izin tapi bukan tim client
 * tersebut. Middleware ini menolak akses kalau model yang di-bind bukan
 * milik client yang ada di roster user (CEO/Manager selalu lolos).
 *
 * Pemakaian: 'client.scope:contentItem' (client_id langsung di model),
 * atau 'client.scope:contentBrief,contentItem.client_id' (client_id lewat
 * relasi, dipisah titik) buat model yang tidak punya kolom client_id
 * sendiri (mis. ContentBriefDraft -> contentItem -> client_id).
 */
class EnsureClientScope
{
    public function handle(Request $request, Closure $next, string $param, string $path = 'client_id'): Response
    {
        $user = $request->user();
        $model = $request->route($param);

        // Model tidak ke-bind (mis. route optional) - biarkan lolos, bukan
        // urusan middleware ini.
        if (! $model) {
            return $next($request);
        }

        $clientId = $model;
        foreach (explode('.', $path) as $segment) {
            $clientId = is_object($clientId) ? ($clientId->{$segment} ?? null) : null;
        }

        // Nggak ketemu client_id-nya (mis. relasi null) - jangan block,
        // biar nggak nge-blok route yang sebenarnya tidak butuh scope ini.
        if ($clientId === null) {
            return $next($request);
        }

        abort_unless(
            $user && ($user->canSeeAllClients() || $user->assignedClients()->whereKey($clientId)->exists()),
            403,
            'Anda tidak punya akses ke client ini.'
        );

        return $next($request);
    }
}
