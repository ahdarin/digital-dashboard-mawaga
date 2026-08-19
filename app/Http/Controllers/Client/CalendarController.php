<?php

namespace App\Http\Controllers\Client;

use App\Http\Controllers\Controller;
use App\Models\ContentItem;
use Illuminate\Http\Request;

class CalendarController extends Controller
{
    /**
     * Kalender konten client - versi client-scoped dari
     * content-plan/partials/calendar-grid.blade.php (view internal).
     * Disederhanakan karena cuma 1 client (nggak perlu legenda warna/
     * grouping per-client kayak versi internal yang lintas client), dan
     * item-nya diarahkan ke client.approval.show, bukan content-items.show
     * yang internal.
     */
    public function index(Request $request)
    {
        $clientId = $request->user()->client_id;

        $month = (int) $request->input('month', now()->month);
        $year = (int) $request->input('year', now()->year);

        $items = ContentItem::with('contentType')
            ->where('client_id', $clientId)
            ->whereMonth('deadline_at', $month)
            ->whereYear('deadline_at', $year)
            ->orderBy('deadline_at')
            ->get();

        $itemsByDate = $items->groupBy(fn ($item) => $item->deadline_at->format('Y-m-d'));

        return view('client.calendar.index', compact('month', 'year', 'itemsByDate'));
    }
}
