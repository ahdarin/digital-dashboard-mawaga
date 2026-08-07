<?php

namespace App\Http\Controllers;

use App\Models\ContentBriefDraft;
use App\Models\ContentItem;
use App\Services\BriefGenerationService;
use Illuminate\Http\Request;

class ContentBriefController extends Controller
{
    private const CHAT_HISTORY_LIMIT = 20;

    public function __construct(private BriefGenerationService $briefService) {}

    /**
     * Generate brief siap pakai dari ContentItem (ide mentah).
     * Kalau brief sudah pernah dibuat untuk item ini, tampilkan yang lama
     * (tidak generate ulang otomatis - cegah biaya API berulang tanpa sengaja).
     */
    public function generate(ContentItem $contentItem)
    {
        $existing = ContentBriefDraft::where('content_item_id', $contentItem->id)->first();

        if ($existing) {
            return redirect()->route('content-items.show', $contentItem);
        }

        $this->briefService->generate($contentItem, auth()->id());

        return redirect()->route('content-items.show', $contentItem)
            ->with('status', 'Brief berhasil disusun dari ide mentah. Silakan review dan diskusikan kalau perlu penyesuaian.');
    }

    /**
     * Link lama/bookmark ke halaman brief terpisah - diarahkan ke halaman
     * Content Item (brief sekarang ditampilkan menyatu di sana).
     */
    public function show(ContentBriefDraft $contentBrief)
    {
        return redirect()->route('content-items.show', $contentBrief->contentItem);
    }

    /**
     * Regenerate paksa - hanya boleh kalau brief belum finalized.
     */
    public function regenerate(ContentBriefDraft $contentBrief)
    {
        abort_if($contentBrief->isLocked(), 422, 'Brief sudah diterapkan, tarik kembali dulu sebelum regenerate ulang.');

        $contentItem = $contentBrief->contentItem;
        $this->briefService->regenerate($contentBrief);

        return redirect()->route('content-items.show', $contentItem)
            ->with('status', 'Brief berhasil disusun ulang.');
    }

    /**
     * Kirim pesan diskusi ke AI. AI membalas + mengusulkan perubahan,
     * TAPI belum menerapkannya ke brief (lihat applyChanges di bawah).
     */
    public function discuss(Request $request, ContentBriefDraft $contentBrief)
    {
        abort_if($contentBrief->isLocked(), 422, 'Brief sudah diterapkan, tarik kembali dulu untuk berdiskusi lagi.');

        $validated = $request->validate([
            'message' => 'required|string|max:1000',
        ]);

        $result = $this->briefService->discuss($contentBrief, $validated['message']);

        $history = $contentBrief->chat_history ?? [];
        $history[] = ['role' => 'user', 'content' => $validated['message'], 'at' => now()->toIso8601String()];
        $history[] = [
            'role' => 'assistant',
            'content' => $result['reply'],
            'suggested_changes' => $result['suggested_changes'],
            'at' => now()->toIso8601String(),
        ];

        // Batasi histori supaya tidak tumbuh tanpa batas (bloat kolom JSON +
        // beban context prompt diskusi berikutnya).
        $history = array_slice($history, -self::CHAT_HISTORY_LIMIT);

        $contentBrief->update([
            'chat_history' => $history,
            'status' => 'discussing',
        ]);

        return response()->json([
            'reply' => $result['reply'],
            'suggested_changes' => $result['suggested_changes'],
        ]);
    }

    /**
     * Terapkan hasil diskusi terakhir (atau field manual) ke brief.
     */
    public function applyChanges(Request $request, ContentBriefDraft $contentBrief)
    {
        abort_if($contentBrief->isLocked(), 422, 'Brief sudah diterapkan sebelumnya.');

        $validated = $request->validate([
            'fields' => 'required|array',
        ]);

        $this->briefService->applyChanges($contentBrief, $validated['fields']);

        return back()->with('status', 'Perubahan dari diskusi berhasil diterapkan ke brief.');
    }

    /**
     * Kembalikan brief ke kondisi sebelum perubahan terakhir (undo 1 langkah).
     */
    public function revert(ContentBriefDraft $contentBrief)
    {
        abort_if($contentBrief->isLocked(), 422, 'Brief sudah diterapkan sebelumnya.');
        abort_if(! $contentBrief->canRevert(), 422, 'Tidak ada versi sebelumnya untuk dikembalikan.');

        $this->briefService->revert($contentBrief);

        return back()->with('status', 'Brief dikembalikan ke versi sebelumnya.');
    }

    /**
     * Finalisasi brief - dikirim ke tim produksi, brief jadi read-only.
     */
    public function finalize(ContentBriefDraft $contentBrief)
    {
        $contentBrief->update([
            'status' => 'finalized',
            'finalized_at' => now(),
        ]);

        return back()->with('status', 'Brief sudah diterapkan dan dikirim ke tim produksi.');
    }

    /**
     * Tarik kembali - buka lagi brief untuk diskusi/revisi.
     */
    public function withdraw(ContentBriefDraft $contentBrief)
    {
        $contentBrief->update([
            'status' => 'discussing',
            'finalized_at' => null,
        ]);

        return back()->with('status', 'Brief ditarik kembali, sekarang bisa didiskusikan/direvisi lagi.');
    }
}
