<?php

namespace App\Http\Middleware;

use App\Models\Client;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\View;
use Symfony\Component\HttpFoundation\Response;

/**
 * Client Portal TIDAK PAKAI Laravel Auth sama sekali - Client bukan User,
 * tidak ada login/session identity. Token permanen di URL ITU SENDIRI yang
 * jadi credential (permanent capability URL) - lihat Client::portal_token.
 *
 * Resolve token -> Client, lalu taruh di request attributes ('portalClient')
 * SUPAYA controller portal ambil dari situ, bukan dari $request->user().
 * Juga di-share ke semua view (layouts/client.blade.php butuh token buat
 * bangun link navigasi) supaya controller portal tidak perlu compact()
 * $client->portal_token di setiap return view().
 *
 * Token tidak valid atau portal_access_enabled=false -> 404 (BUKAN 403),
 * biar tidak membocorkan bahwa suatu token "pernah ada tapi dinonaktifkan" -
 * dari luar, token invalid dan token yang di-disable harus terlihat identik.
 */
class ResolveClientPortal
{
    public function handle(Request $request, Closure $next): Response
    {
        $token = $request->route('token');

        $client = Client::where('portal_token', $token)
            ->where('portal_access_enabled', true)
            ->first();

        abort_unless($client, 404);

        $request->attributes->set('portalClient', $client);

        View::share('portalClient', $client);
        View::share('portalToken', $token);

        return $next($request);
    }
}
