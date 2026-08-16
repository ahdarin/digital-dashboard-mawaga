{{-- Shared "Brief Belum Final" queue body for the copywriter role.
     Used by home/index-copywriter and profile/show-copywriter.
     Expects: $briefQueueItems --}}
<div class="card overflow-hidden flex flex-col">
    <div class="p-5 pb-4 shrink-0">
        <h2 class="font-display text-base font-semibold text-[#14181a]">Brief Belum Final ({{ $briefQueueItems->count() }})</h2>
        <p class="text-xs text-[#767c80] mt-1">Konten yang briefnya belum diterapkan ke tim produksi - baik yang belum digarap sama sekali maupun yang masih draft/diskusi.</p>
    </div>
    <div class="overflow-auto max-h-[520px] thin-autohide-scrollbar hidden sm:block">
        <table class="w-full text-sm text-left">
            <thead class="bg-[#f7f8fc] text-[#767c80] text-[11px] uppercase tracking-wide sticky top-0 z-10">
                <tr>
                    <th class="px-6 py-3 font-medium whitespace-nowrap">Konten</th>
                    <th class="px-4 py-3 font-medium whitespace-nowrap">Klien</th>
                    <th class="px-4 py-3 font-medium whitespace-nowrap">Status Brief</th>
                    <th class="px-4 py-3 font-medium whitespace-nowrap">Deadline</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($briefQueueItems as $item)
                    @php
                        $brief = $item->contentBriefDraft;
                        $briefStatus = match ($brief?->status) {
                            'discussing' => ['label' => 'Sedang Didiskusikan', 'class' => 'badge-warning'],
                            'draft' => ['label' => 'Draft', 'class' => 'badge-neutral'],
                            default => ['label' => 'Belum Dibuat', 'class' => 'badge-danger'],
                        };
                    @endphp
                    <tr class="border-t border-[#f2f3f6] hover:bg-[#f7f8fc] transition-colors">
                        <td class="px-6 py-3.5 font-medium text-[#14181a] whitespace-nowrap">
                            <a href="{{ route('content-items.show', $item) }}" class="hover:underline">{{ $item->title }}</a>
                        </td>
                        <td class="px-4 py-3.5 text-[#5c6266] whitespace-nowrap">{{ $item->client->name ?? '-' }}</td>
                        <td class="px-4 py-3.5">
                            <span class="badge {{ $briefStatus['class'] }}">
                                {{ $briefStatus['label'] }}
                            </span>
                        </td>
                        <td class="px-4 py-3.5 text-[#5c6266] whitespace-nowrap">{{ $item->deadline_at->format('d M Y') }}</td>
                    </tr>
                @empty
                    <tr><td colspan="4" class="px-6 py-10 text-center text-[#767c80] text-sm">Semua brief sudah diterapkan ke tim produksi. Kerja bagus!</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    {{-- Mobile accordion --}}
    <div class="sm:hidden p-3.5 space-y-3 max-h-[520px] overflow-auto thin-autohide-scrollbar">
        @forelse ($briefQueueItems as $item)
            @php
                $brief = $item->contentBriefDraft;
                $briefStatus = match ($brief?->status) {
                    'discussing' => ['label' => 'Sedang Didiskusikan', 'class' => 'badge-warning'],
                    'draft' => ['label' => 'Draft', 'class' => 'badge-neutral'],
                    default => ['label' => 'Belum Dibuat', 'class' => 'badge-danger'],
                };
            @endphp
            <div class="card p-3.5" x-data="{ open: false }">
                <button type="button" class="w-full text-left flex items-start gap-2 cursor-pointer" @click="open = !open" :aria-expanded="open">
                    <div class="flex-1 min-w-0">
                        <p class="font-medium text-[#14181a] text-sm">{{ $item->title }}</p>
                        <div class="flex items-center gap-2 mt-1.5">
                            <span class="badge {{ $briefStatus['class'] }}">
                                {{ $briefStatus['label'] }}
                            </span>
                            <span class="text-xs text-[#5c6266] whitespace-nowrap">{{ $item->deadline_at->format('d M Y') }}</span>
                        </div>
                    </div>
                    <div class="w-7 h-7 shrink-0 flex items-center justify-center rounded-lg text-[#767c80]">
                        <span class="material-symbols-outlined text-[19px] transition-transform" :class="open && 'rotate-180'">expand_more</span>
                    </div>
                </button>
                <div x-show="open" x-cloak x-transition class="mt-3 pt-3 border-t border-[#f2f3f6] space-y-2">
                    <div class="flex items-center justify-between text-xs">
                        <span class="text-[#767c80]">Klien</span>
                        <span class="text-[#14181a] font-medium">{{ $item->client->name ?? '-' }}</span>
                    </div>
                    <a href="{{ route('content-items.show', $item) }}" class="mt-2 flex items-center justify-center gap-1.5 text-xs font-semibold text-[#044b46] bg-[#f0f5f4] hover:bg-[#e4ede9] rounded-lg py-2 transition-colors">Lihat Detail <span class="material-symbols-outlined text-[15px]">arrow_forward</span></a>
                </div>
            </div>
        @empty
            <p class="px-2 py-10 text-center text-[#767c80] text-sm">Semua brief sudah diterapkan ke tim produksi. Kerja bagus!</p>
        @endforelse
    </div>
</div>
