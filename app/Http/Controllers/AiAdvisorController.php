<?php

namespace App\Http\Controllers;

use App\Models\ContentItem;
use App\Models\ContentPillar;
use Illuminate\Support\Carbon;

/**
 * NOTE UNTUK TIM:
 * Controller ini scaffold FRONT-END saja untuk halaman AI Content Planning
 * Advisor (PRD 7.1.4, domain PIC 1 - Ghazi). Logika "AI"-nya sesuai PRD
 * cuma agregasi & ranking pilar konten (statistik deskriptif), BUKAN
 * generative text/confidence score seperti di data placeholder di bawah.
 *
 * Bagian yang masih placeholder (perlu diganti PIC 1):
 * - $recommendation (judul + narasi strategi)
 * - $actionItems
 * - $confidence
 * - $suggestedSplit (persentase per tipe konten, bukan per pilar)
 *
 * Bagian yang sudah realistis (ambil dari DB):
 * - $topPillars -> ranking pilar berdasarkan jumlah content item, 90 hari terakhir
 */
class AiAdvisorController extends Controller
{
    public function index()
    {
        $since = Carbon::now()->subDays(90);

        $pillarRanking = ContentItem::where('created_at', '>=', $since)
            ->whereNotNull('content_pillar_id')
            ->selectRaw('content_pillar_id, count(*) as total')
            ->groupBy('content_pillar_id')
            ->orderByDesc('total')
            ->with('client') // eager load ringan, hindari N+1 kalau nanti dipakai
            ->get();

        $pillarNames = ContentPillar::pluck('name', 'id');

        // Deskripsi placeholder per pilar (PIC 1 nanti bisa generate dari data brief/insight asli)
        $pillarDescriptions = [
            'Edukasi' => '"How-to" guides dan insight industri.',
            'Storytelling' => 'Behind-the-scenes dan cerita di balik brand.',
            'Branding' => 'Feature highlight dan kesuksesan klien.',
            'Hard Selling' => 'Promosi produk/layanan secara langsung.',
            'Engagement' => 'Interaksi ringan: polling, Q&A, giveaway.',
        ];

        $topPillars = $pillarRanking->take(3)->map(function ($row) use ($pillarNames, $pillarDescriptions) {
            $name = $pillarNames[$row->content_pillar_id] ?? 'Tanpa Pilar';

            return [
                'name' => $name,
                'description' => $pillarDescriptions[$name] ?? 'Belum ada deskripsi untuk pilar ini.',
                'total' => $row->total,
            ];
        })->values();

        // --- Placeholder (lihat catatan di atas) ---
        $recommendation = [
            'label' => 'Strategic Recommendation',
            'title' => 'Shift focus to video-first content for Q4',
            'description' => 'Berdasarkan tren engagement terbaru dan analisis kompetitor, konten video pendek '
                . 'menghasilkan conversion rate 3x lebih tinggi di sektor ini. Memindahkan resource dari '
                . 'static post ke storytelling dinamis akan menangkap momentum top-of-funnel menjelang musim liburan.',
        ];

        $actionItems = [
            'Realokasikan 40% budget iklan standar ke TikTok dan Reels.',
            'Kembangkan 3 template storytelling inti untuk produksi cepat.',
            'Adakan sprint ideasi mingguan bersama tim kreatif.',
        ];

        $confidence = 94;
        $confidenceNote = 'Probabilitas engagement tinggi berdasarkan pola data historis.';

        $suggestedSplit = [
            ['label' => 'Short-form Video', 'value' => 55, 'color' => '#044b46'],
            ['label' => 'Carousel Posts', 'value' => 25, 'color' => '#5eae9e'],
            ['label' => 'Long-form Articles', 'value' => 15, 'color' => '#9ca3af'],
            ['label' => 'Other', 'value' => 5, 'color' => '#d1d5db'],
        ];
        // --- End placeholder ---

        return view('ai-advisor.index', compact(
            'recommendation', 'actionItems', 'confidence', 'confidenceNote',
            'suggestedSplit', 'topPillars'
        ));
    }
}