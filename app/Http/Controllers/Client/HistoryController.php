<?php

namespace App\Http\Controllers\Client;

use App\Http\Controllers\Controller;
use App\Models\ContentItem;
use App\Models\ContentType;
use Illuminate\Http\Request;

class HistoryController extends Controller
{
    /**
     * Riwayat/arsip konten client - lintas SEMUA status (bukan cuma
     * waiting_review kayak Approval Queue), biar client bisa telusur balik
     * konten lama. Kolom PIC & Risiko sengaja tidak ada di sini - itu info
     * operasional internal, bukan urusan client.
     */
    public function index(Request $request)
    {
        $client = $request->attributes->get('portalClient');

        $query = ContentItem::with(['contentType', 'workflow'])
            ->where('client_id', $client->id);

        if ($request->filled('type')) {
            $query->whereHas('contentType', fn ($q) => $q->where('name', $request->input('type')));
        }

        if ($request->filled('month')) {
            $month = \Carbon\Carbon::parse($request->input('month') . '-01');
            $query->whereYear('deadline_at', $month->year)->whereMonth('deadline_at', $month->month);
        }

        $items = $query->orderByDesc('deadline_at')->paginate(15)->withQueryString();

        $typeOptions = ContentType::all();

        return view('client.history.index', compact('client', 'items', 'typeOptions'));
    }
}
